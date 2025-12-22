/**
 * Checkout JavaScript for NWC Payment Gateway
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initNWCCheckout();
    });

    /**
     * Initialize NWC checkout functionality
     */
    function initNWCCheckout() {
        // Monitor for NWC payment method selection
        $(document.body).on('payment_method_selected', function() {
            const selectedMethod = $('input[name="payment_method"]:checked').val();
            
            if (selectedMethod === 'nwc') {
                // NWC payment method selected
                console.log('NWC payment method selected');
            }
        });

        // Handle place order for NWC
        $(document.body).on('checkout_place_order_nwc', function() {
            // Additional validation before placing order
            return validateNWCCheckout();
        });
    }

    /**
     * Validate NWC checkout before submission
     */
    function validateNWCCheckout() {
        // Check if user is logged in (should be handled server-side as well)
        const $warning = $('.nwc-payment-warning');
        
        if ($warning.length > 0) {
            alert('Please connect your Lightning wallet before proceeding.');
            return false;
        }

        return true;
    }

    /**
     * Process Lightning payment
     */
    async function processLightningPayment(invoice) {
        try {
            if (!window.nostr) {
                throw new Error('Nostr extension not found');
            }

            // Request payment via NWC
            // This would involve creating a NWC payment request event
            // and waiting for the wallet to process it

            return {
                success: true,
                preimage: 'payment_preimage_here'
            };
        } catch (error) {
            console.error('Lightning payment error:', error);
            return {
                success: false,
                error: error.message
            };
        }
    }

})(jQuery);

