/**
 * Admin JavaScript for Nostr Login & Pay Settings
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initAdminSettings();
    });

    /**
     * Initialize admin settings functionality
     */
    function initAdminSettings() {
        // Add validation for NWC connection strings
        $('input[name="nostr_login_pay_nwc_merchant_wallet"]').on('blur', function () {
            const value = $(this).val().trim();

            if (value && !value.startsWith('nostr+walletconnect://')) {
                alert('Invalid NWC connection string. It should start with "nostr+walletconnect://"');
                $(this).focus();
            }
        });

        // Toggle relay settings based on enable/disable
        $('input[name="nostr_login_pay_enable_login"]').on('change', function () {
            const $relayField = $('textarea[name="nostr_login_pay_relays"]').closest('tr');

            if ($(this).is(':checked')) {
                $relayField.show();
            } else {
                $relayField.hide();
            }
        }).trigger('change');

        // Media Picker
        $('.nostr-choose-media').on('click', function (e) {
            e.preventDefault();
            const $button = $(this);
            const targetId = $button.data('target');
            const $input = $('#' + targetId);

            const frame = wp.media({
                title: 'Select Avatar Image',
                button: {
                    text: 'Use this image'
                },
                multiple: false
            });

            frame.on('select', function () {
                const attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.url);
            });

            frame.open();
        });
    }

})(jQuery);

