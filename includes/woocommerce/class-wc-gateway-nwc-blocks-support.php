<?php
/**
 * WooCommerce NWC Payment Gateway Blocks Support
 *
 * @package Nostr_Login_Pay
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NWC Gateway Blocks Support Class
 */
final class WC_Gateway_NWC_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name
     */
    protected $name = 'nwc';

    /**
     * Gateway instance
     */
    private $gateway;

    /**
     * Initialize the payment method
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_nwc_settings', array() );
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
    }

    /**
     * Check if the payment method is active
     */
    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    /**
     * Get payment method script handles
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-nwc-blocks-integration',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/blocks-checkout.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            NOSTR_LOGIN_PAY_VERSION,
            true
        );

        return array( 'wc-nwc-blocks-integration' );
    }

    /**
     * Get payment method data for frontend
     */
    public function get_payment_method_data() {
        return array(
            'title' => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'icon' => NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/images/lightning-icon.svg',
            'supports' => $this->gateway ? array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) : array(),
        );
    }
}

