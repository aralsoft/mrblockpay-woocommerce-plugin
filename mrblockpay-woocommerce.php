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

// Exit if woocommerce is not installed and active.
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    die('Woocommerce not found.');
}

// Load main plugin class
add_action('plugins_loaded', 'mrblockpay');

function mrblockpay() {
    if (class_exists('WC_Payment_Gateway')) {
        class Mrblockpay_Payment_Gateway extends WC_Payment_Gateway
        {
            public $instructions = '';
            public $public_key = '';
            public $secret_key = '';
            public $api_url = 'https://mrblockpay.com/api';

            // Initialise class
            public function __construct()
            {
                $this->id = 'mrblockpay';
                $this->icon = apply_filters('woocommerce_mrblockpay_icon', plugins_url('/assets/img/crypto-icon.png', __FILE__));
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

                if (is_admin()) {
                    add_filter('plugin_action_links_'.plugin_basename(__FILE__), array($this, 'add_action_links'));
                    add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
                }

                add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

                add_filter('woocommerce_update_order_review_fragments', array($this, 'modify_order_review_ajax_response'));

                add_action('woocommerce_before_thankyou', array($this, 'thank_you_page'), 1);
                add_action('woocommerce_after_checkout_validation', array($this, 'validate_custom_checkout_fields'));
                add_action('woocommerce_checkout_update_order_meta', array($this, 'save_custom_checkout_fields'));

            }

            // Output Javascript files
            public function payment_scripts()
            {
                if ($order = $this->get_order_from_key())
                {
                    wp_enqueue_script('wc_mrblockpay_refresh_page' ,plugins_url('/assets/js/mrblockpay_refresh_page.js', __FILE__));
                    wp_enqueue_script('wc_mrblockpay_qrcode' ,plugins_url('/assets/js/mrblockpay_qrcode.js', __FILE__));
                    wp_enqueue_script('wc_mrblockpay_qrcode_show' ,plugins_url('/assets/js/mrblockpay_qrcode_show.js', __FILE__), array('jquery'));
                    wp_localize_script('wc_mrblockpay_qrcode_show', 'mrblockpayQrCodeParams', array(
                        'depositWallet' => $order->get_meta('_order_deposit_wallet')
                    ));
                }
                else
                {
                    wp_enqueue_script('wc_mrblockpay_currency-selector-script', plugins_url('/assets/js/mrblockpay-currency-selector.js', __FILE__), array('jquery'));
                    wp_localize_script('wc_mrblockpay_currency-selector-script', 'mrblockpayCurrencySelectorAjax', array(
                        'ajaxurl' => admin_url('admin-ajax.php')
                    ));
                }

            }

            // Add currency selector form on checkout page load
            public function modify_order_review_ajax_response($response)
            {
                $dom = new DOMDocument();
                $dom->loadHTML($response['.woocommerce-checkout-payment']);

                $radio_buttons = $dom->getElementsByTagName('input');

                foreach ($radio_buttons as $radio) {
                    if ($radio->getAttribute('type') === 'radio'
                        && $radio->getAttribute('name') === 'payment_method'
                        && $radio->getAttribute('value') === 'mrblockpay'
                        && $radio->getAttribute('checked') === 'checked')
                    {
                        $form = mrblockpay_currency_selector_form();

                        if ($start = strpos($response['.woocommerce-checkout-payment'], 'payment_box payment_method_mrblockpay')) {
                            if ($end = strpos($response['.woocommerce-checkout-payment'], '</div>', $start)) {
                                $response['.woocommerce-checkout-payment'] = substr_replace($response['.woocommerce-checkout-payment'], $form, $end, 0);
                            }
                        }

                        break;
                    }
                }

                return $response;
            }

            // Process order payment
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

            // Process order thank you page
            public function thank_you_page()
            {
                if (!$order = $this->get_order_from_key()) {
                    echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                    echo '<strong>Invalid order key.</strong>';
                    echo '</div><br/>';
                    return;
                }

                if ($order->get_payment_method() != $this->id) {
                    return;
                }

                $args = [
                    'timeout' => 30,
                    'body' => array('order_key' => $order->get_order_key()),
                    'headers' => [
                        'Public-Key' => $this->public_key,
                        'Signature' => hash_hmac('sha256', $order->get_order_key(), $this->secret_key),
                        'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
                    ]
                ];

                $response = wp_remote_post($this->api_url . '/check_order_transactions', $args);

                if ($response['response']['code'] != 200) {
                    echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                    echo '<strong>Invalid API response.</strong>';
                    echo '</div><br/>';
                    return;
                }

                parse_str($response['body'], $responseBody);

                if ($responseBody['status'] != 'success') {
                    echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                    echo '<strong>Invalid order status.</strong>';
                    echo '</div><br/>';
                    return;
                }

                // Output order payment details
                if ($responseBody['payment_status'] == 'paid')
                {
                    $order->update_status('processing');
                    echo '<div style="background-color: #009900; padding: 10px; color: #FFF;">';
                    echo '<strong>Payment Received In Full.</strong>';
                    echo '</div><br/>';
                }
                else if ($responseBody['payment_status'] == 'cancelled')
                {
                    $order->update_status('cancelled');
                    echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                    echo '<strong>Order Cancelled.</strong>';
                    echo '</div><br/>';
                }
                else
                {
                    if (!is_numeric($order->get_meta('_order_crypto_amount'))) {
                        echo '<div style="background-color: #990000; padding: 10px; color: #FFF;">';
                        echo '<strong>Invalid order amount.</strong>';
                        echo '</div><br/>';
                        return;
                    }

                    $depositWallet = esc_html($order->get_meta('_order_deposit_wallet'));
                    $orderAmount = esc_attr(ceil($order->get_meta('_order_crypto_amount') * 100) / 100);
                    $cryptoCurrency = esc_html($order->get_meta('_order_crypto_currency'));
                    $totalReceived  = esc_attr($responseBody['total_received']);

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
                            Send Payment To: <strong>' . $depositWallet . '</strong>
                            </p>
                            <p>
                            Order Amount: <strong>' . number_format($orderAmount, 2) . ' ' . $cryptoCurrency . '</strong>
                            <br/>Amount Received: <strong>' . number_format($totalReceived, 2) . ' ' . $cryptoCurrency . '</strong>
                            <br/>Amount Remaining: <strong>' . number_format($orderAmount - $totalReceived, 2) . ' ' . $cryptoCurrency . '</strong>
                            </p>
                            <p>Time left to Transaction check: <span id="countdown-timer"><strong>1:00</strong></span></p>
                        </td>
                     
                        </tr>
                        </table>
                        
                        <p>
                            <strong>' . esc_html($this->instructions) . '</strong>
                        </p>
                        ';
                    }

            }

            // Return order details array
            public function get_order_details($order)
            {
                $args = array(
                    'order_id' => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'amount' => $order->get_total(),
                    'currency' => get_woocommerce_currency(),
                    'crypto' => get_post_meta($order->get_id(), 'mrblockpay_currency', true),
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

            // Return order object with order key
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

            // Validate custom checkout fields
            public function validate_custom_checkout_fields()
            {
                if (WC()->session->get('chosen_payment_method') == $this->id && (!isset($_POST['mrblockpay_currency']) || !$_POST['mrblockpay_currency'])) {
                    wc_add_notice('Please choose a Cryptocurrency to use with this order.', 'error');
                }
            }

            // Save custom checkout fields
            public function save_custom_checkout_fields($order_id)
            {
                if (isset($_POST['mrblockpay_currency'])) {
                    update_post_meta($order_id, 'mrblockpay_currency', sanitize_text_field($_POST['mrblockpay_currency']));
                }
            }

            // Initialise settings form fields
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
                        'default' => 'Mr Block Pay Crypto Payment Gateway',
                        'desc_tip' => true
                    ),
                    'description' => array(
                        'title' => 'Mr Block Pay Description',
                        'type' => 'textarea',
                        'description' => 'Add a new description for Mr Block Pay gateway that customers see at checkout',
                        'default' => 'Please choose Cryptocurrency to use and send your payment to the wallet address shown on the Thank You page to complete your order.',
                        'desc_tip' => true
                    ),
                    'instructions' => array(
                        'title' => 'Payment Instructions',
                        'type' => 'textarea',
                        'description' => 'Add payment instructions that customers see at thank you page.',
                        'default' => 'Please send your payment to the address shown above to complete your order.',
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

            // Add settings link under plugin name on plugins list
            public function add_action_links ($actions)
            {
                $myLinks = array('<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=mrblockpay').'">Settings</a>');

                return array_merge($myLinks, $actions);
            }

        }

    }
    else
    {
        die('Woocommerce Payment Gateway class not found.');
    }
}

// Handle currency selector form on change of payment option
add_action('wp_ajax_get_currency_selector_form', 'mrblockpay_get_currency_selector_form');
add_action('wp_ajax_nopriv_get_currency_selector_form', 'mrblockpay_get_currency_selector_form');
function mrblockpay_get_currency_selector_form()
{
    ob_start();

    echo mrblockpay_currency_selector_form();

    $response = ob_get_clean();

    echo $response;
    exit;
}

function mrblockpay_currency_selector_form()
{
    return '<div id="mrblockpay-currency-selector">
                <label for="mrblockpay_currency">Choose a Cryptocurrency :</label>
                <select name="mrblockpay_currency">
                    <option value="">Select Crypto</option>
                    <option value="TRX">TRX</option>
                    <option value="USDT">Tether (TRC20)</option>
                </select>
            </div>';
}

// Add plugin as a payment gateway
add_filter('woocommerce_payment_gateways', 'mrblockpay_add_payment_gateway');
function mrblockpay_add_payment_gateway($gateways)
{
    $gateways[] = 'Mrblockpay_Payment_Gateway';
    return $gateways;
}
