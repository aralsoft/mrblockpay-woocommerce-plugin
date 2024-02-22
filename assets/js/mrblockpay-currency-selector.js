
// jQuery(document).ready() function
jQuery(document).ready(function($)
{
    // Function to handle changes in the payment method
    function mrblockpayHandlePaymentMethodChange() {
        // Get the selected payment method
        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();

        // Check if the selected payment method is your custom payment method
        if (selectedPaymentMethod == 'mrblockpay')
        {
            // Do Ajax call to get the currency selection form and if found insert in HTML
            $.ajax({
                url: mrblockpayCurrencySelectorAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_currency_selector_form'
                },
                success: function(response) {
                    // Insert the currency selector form on the selected payment method
                    $('.payment_box.payment_method_mrblockpay').append(response);
                }
            });
        }
        else {
            // If the selected payment method is not your custom payment method, remove the currency selector form
            $('#mrblockpay-currency-selector').remove();
        }

    }

    // Event listener for payment method change
    $('form.checkout').on('change', 'input[name="payment_method"]', mrblockpayHandlePaymentMethodChange);

});
