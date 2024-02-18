<?php

/*
 * Plugin Name:       Mr Block Pay For Woocommerce
 * Plugin URI:        https://mrblockpay.com
 * Description:       Enable cryptocurrency payments for Woocommerce with this plugin.
 * Version:           1.0.0
 * Requires PHP:      7.0
 * Author:            Aralsoft Ltd.
 * Author URI:        http://aralsoft.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mr-block-pay-for-woocommerce
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    die('Direct access is not allowed.');
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    die('Woocommerce not found.');
}

add_action('plugins_loaded', 'mrblockpay');

function mrblockpay() {
    if (class_exists('WC_Payment_Gateway')) {
        class Mrblockpay_Payment_Gateway extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'mrblockpay';
                $this->icon = apply_filters('woocommerce_mrblockpay_icon', plugins_url('/assets/img/trx-icon.png', __FILE__));
                $this->has_fields = false;
                $this->method_title = 'MrBlockPay';
                $this->method_description = 'Cryptocurrency Checkout Support. <a href="https://mrblockpay.com/account/register" target="_blank">Get your API keys.</a>';

                $this->supports = array(
                    'products'
                );

                $this->init_form_fields();
                $this->init_settings();

                $this->enabled = $this->get_option('enabled');
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->instructions = $this->get_option('instructions');
                $this->public_key = $this->get_option('public_key');
                $this->secret_key = $this->get_option('secret_key');

                $this->api_url = 'https://mrblockpay.com/api';

                if (is_admin()) {
                    add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
                    add_filter('plugin_action_links_'.plugin_basename(__FILE__), array($this, 'add_action_links'));
                }

                add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
                add_action('woocommerce_before_thankyou', array($this, 'thank_you_page'), 1);
            }

            function add_action_links ($actions)
            {
                $myLinks = array('<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=mrblockpay').'">Settings</a>');

                return array_merge($myLinks, $actions);
            }

            public function payment_scripts()
            {
                wp_enqueue_script('wc_mrblockpay_qrcode' ,plugins_url('/assets/js/qrcode.js', __FILE__));
                wp_enqueue_script('wc_mrblockpay_refresh_page' ,plugins_url('/assets/js/refresh_page.js', __FILE__));

                if ($order = $this->get_order_from_key()) {
                    wp_enqueue_script('wc_mrblockpay_qrcode_show' ,plugins_url('/assets/js/qrcode_show.js', __FILE__), array('jquery'));
                    wp_localize_script('wc_mrblockpay_qrcode_show', 'qrCodeParams', array(
                        'depositWallet' => $order->get_meta('_order_deposit_wallet')
                    ));
                }
            }

            public function thank_you_page()
            {
                if ($order = $this->get_order_from_key()) {
                    $amount = ceil($order->get_meta('_order_crypto_amount') * 100) / 100;

                    $headers = [
                        'Public-Key' => $this->public_key,
                        'Signature' => hash_hmac('sha256', $order->get_order_key(), $this->secret_key),
                        'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
                    ];

                    $args = [
                        'timeout' => 30,
                        'body' => array('order_key' => $order->get_order_key()),
                        'headers' => $headers
                    ];

                    $response = wp_remote_post($this->api_url . '/check_order_transactions', $args);

                    if ($response['response']['code'] == 200) {
                        parse_str($response['body'], $responseBody);

                        if ($responseBody['status'] == 'success') {
                            if ($responseBody['payment_status'] == 'paid') {
                                $order->update_status('processing');
                                echo '<div style="background-color: #009900; padding: 10px; color: #FFF;">';
                                echo '<strong>Payment Received In Full.</strong>';
                                echo '</div><br/>';
                            } else if ($responseBody['payment_status'] == 'cancelled') {
                                $order->update_status('cancelled');
                                echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                                echo '<strong>Order Cancelled.</strong>';
                                echo '</div><br/>';
                            } else {
                                echo '
                           <table>
                           <tr>
                           
                           <td>
                                <div id="qrcode-out">
                                    <div id="qrcode" style="margin-top:7px;">
                                        <img alt="Scan me!" style="display: none;">
                                    </div>
                                </div>
                            </td>
                            <td>
                            
                                <p>
                                Send Payment To: <strong>' . esc_html($order->get_meta('_order_deposit_wallet')) . '</strong>
                                </p>
                                <p>
                                Order Amount: <strong>' . number_format(esc_attr($amount), 2) . ' ' . esc_html($order->get_meta('_order_crypto_currency')) . '</strong>
                                <br/>Amount Received: <strong>' . number_format(esc_attr($responseBody['total_received']), 2) . ' ' . esc_html($order->get_meta('_order_crypto_currency')) . '</strong>
                                <br/>Amount Remaining: <strong>' . number_format(esc_attr($amount - $responseBody['total_received']), 2) . ' ' . esc_html($order->get_meta('_order_crypto_currency')) . '</strong>
                                </p>
                                <p>Time left to Transaction check: <span id="countdown-timer"><strong>1:00</strong></span></p>
                            </td>
                         
                            </tr>
                            </table>
                            
                            <p><strong>' . esc_html($this->instructions) . '</strong></p>
                            ';
                            }
                        } else {
                            echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                            echo '<strong>Order Not Found.</strong>';
                            echo '</div><br/>';
                        }
                    } else {
                        echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                        echo '<strong>Failed to retrieve order.</strong>';
                        echo '</div><br/>';
                    }
                } else {
                    echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                    echo '<strong>Order key is missing.</strong>';
                    echo '</div><br/>';
                }

            }

            public function get_order_from_key()
            {
                $key = '';

                if (isset($_GET['key'])) {
                    $key = sanitize_text_field($_GET['key']);
                }

                if (empty($key)) {
                    return FALSE;
                }

                return wc_get_order(wc_get_order_id_by_order_key($key));
            }

            public function process_payment($order_id)
            {
                if ($order = wc_get_order($order_id))
                {
                    $order->update_status('pending-payment');

                    $orderDetails = $this->get_order_details($order);

                    $headers = [
                        'Public-Key' => $this->public_key,
                        'Signature' => hash_hmac('sha256', $orderDetails['order_key'], $this->secret_key),
                        'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
                    ];

                    $args = [
                        'timeout' => 60,
                        'body' => $orderDetails,
                        'headers' => $headers
                    ];

                    $response = wp_remote_post($this->api_url, $args);

                    if ($response['response']['code'] == 200)
                    {
                        parse_str($response['body'], $responseBody);

                        if ($responseBody['status'] == 'success')
                        {
                            $order->update_meta_data('_order_deposit_wallet', $responseBody['wallet']);
                            $order->update_meta_data('_order_crypto_amount', $responseBody['amount']);
                            $order->update_meta_data('_order_crypto_currency', $orderDetails['crypto']);
                            $order->save_meta_data();

                            return array(
                                'result' => 'success',
                                'redirect' => $this->get_return_url($order)
                            );
                        }
                    }

                }

                return array(
                    'result' => 'failure',
                    'redirect' => $this->get_return_url($order)
                );
            }

            public function get_order_details($order)
            {
                $args = array(
                    'order_id' => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'amount' => $order->get_total(),
                    'currency' => get_woocommerce_currency(),
                    'crypto' => 'TRX',
                    'billing_fname' => sanitize_text_field($order->get_billing_first_name()),
                    'billing_lname' => sanitize_text_field($order->get_billing_last_name()),
                    'billing_email' => sanitize_email($order->get_billing_email()),
                    'redirect_to' => $order->get_checkout_order_received_url(),
                    'cancel_url' => wc_get_checkout_url(),
                    'type' => 'wp'
                );

                $items = $order->get_items();
                $orderItems = [];

                foreach ($items as $item)
                {
                    $orderItems[] = [
                        "name" => sanitize_text_field($item->get_name()),
                        'qty' => $item->get_quantity(),
                        'price' => $item->get_total(),
                    ];
                }
                $args['items'] = $orderItems;

                return $args;
            }

            public function init_form_fields()
            {
                $this->form_fields = apply_filters('mrblockpay_fields', array(
                    'enabled' => array(
                        'title' => 'Enable/Disable',
                        'type' => 'checkbox',
                        'label' => 'Enable or Disable Mr Block Pay',
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => 'Mr Block Pay Title',
                        'type' => 'text',
                        'description' => 'Add a new title for Mr Block Pay gateway that customers see at checkout',
                        'default' => 'Mr Block Pay TRON Payment Gateway',
                        'desc_tip' => true
                    ),
                    'description' => array(
                        'title' => 'Mr Block Pay Description',
                        'type' => 'textarea',
                        'description' => 'Add a new description for Mr Block Pay gateway that customers see at checkout',
                        'default' => 'Please send your TRON payment to the address shown on the next screen to complete your order.',
                        'desc_tip' => true
                    ),
                    'instructions' => array(
                        'title' => 'Payment Instructions',
                        'type' => 'textarea',
                        'description' => 'Add payment instructions that customers see at thank you page.',
                        'default' => 'Please send your TRON payment to the address shown above to complete your order.',
                        'desc_tip' => true
                    ),
                    'credentials_title' => array(
                        'title' => 'Mr Block Pay API Credentials',
                        'type' => 'title'
                    ),
                    'public_key' => array(
                        'title' => 'Public key',
                        'type' => 'password'
                    ),
                    'secret_key' => array(
                        'title' => 'Secret key',
                        'type' => 'password'
                    ),

                ));

            }

        }

    } else {
        die('Woocommerce Payment Gateway class not found.');
    }
}

add_filter('woocommerce_payment_gateways', 'mrblockpay_add_payment_gateway');

function mrblockpay_add_payment_gateway($gateways)
{
    $gateways[] = 'Mrblockpay_Payment_Gateway';
    return $gateways;
}
