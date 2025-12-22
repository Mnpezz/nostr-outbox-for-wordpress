<?php
/**
 * Payment Webhook Handler
 *
 * Receives payment notifications from Lightning providers
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles payment webhook callbacks
 */
class Nostr_Login_Pay_Payment_Webhook {

    /**
     * Initialize webhook handler
     */
    public function __construct() {
        // Register REST API endpoint for webhooks
        add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
    }

    /**
     * Register webhook REST endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route( 'nostr-login-pay/v1', '/webhook/payment', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_payment_webhook' ),
            'permission_callback' => '__return_true', // Webhooks are public but we verify signature
        ) );
    }

    /**
     * Handle incoming payment webhook
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function handle_payment_webhook( $request ) {
        $body = $request->get_body();
        $data = json_decode( $body, true );

        error_log( 'Payment Webhook Received: ' . $body );

        // Verify webhook (implementation depends on provider)
        // For now, we'll accept all webhooks and log them

        // Expected data format:
        // {
        //   "invoice": "lnbc...",
        //   "payment_hash": "abc123...",
        //   "amount": 20,
        //   "paid": true,
        //   "order_id": 123  // or we find it by invoice
        // }

        if ( empty( $data ) ) {
            error_log( 'Webhook: Empty data' );
            return new WP_REST_Response( array( 'error' => 'Invalid data' ), 400 );
        }

        // Find order by invoice
        $invoice = isset( $data['invoice'] ) ? sanitize_text_field( $data['invoice'] ) : '';
        $payment_hash = isset( $data['payment_hash'] ) ? sanitize_text_field( $data['payment_hash'] ) : '';

        if ( empty( $invoice ) && empty( $payment_hash ) ) {
            error_log( 'Webhook: No invoice or payment_hash provided' );
            return new WP_REST_Response( array( 'error' => 'Missing invoice or payment_hash' ), 400 );
        }

        // Find order
        $order = $this->find_order_by_invoice( $invoice, $payment_hash );

        if ( ! $order ) {
            error_log( 'Webhook: Order not found for invoice' );
            return new WP_REST_Response( array( 'error' => 'Order not found' ), 404 );
        }

        // Check if payment is marked as paid
        $is_paid = isset( $data['paid'] ) ? (bool) $data['paid'] : false;
        $amount_received = isset( $data['amount'] ) ? intval( $data['amount'] ) : 0;

        if ( $is_paid || $amount_received > 0 ) {
            // Mark order as paid
            if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ) ) ) {
                $order->payment_complete();
                $order->add_order_note( sprintf(
                    __( 'Lightning payment confirmed via webhook. Amount: %d sats', 'nostr-login-pay' ),
                    $amount_received
                ) );

                error_log( 'Webhook: Order #' . $order->get_id() . ' marked as paid!' );

                return new WP_REST_Response( array( 
                    'success' => true,
                    'order_id' => $order->get_id(),
                    'message' => 'Order marked as paid'
                ), 200 );
            }

            return new WP_REST_Response( array( 
                'success' => true,
                'message' => 'Order already paid'
            ), 200 );
        }

        error_log( 'Webhook: Payment not confirmed in webhook data' );
        return new WP_REST_Response( array( 'message' => 'Payment not confirmed' ), 200 );
    }

    /**
     * Find order by invoice or payment hash
     *
     * @param string $invoice      Invoice string
     * @param string $payment_hash Payment hash
     * @return WC_Order|false Order object or false
     */
    private function find_order_by_invoice( $invoice, $payment_hash ) {
        global $wpdb;

        // Try to find by invoice first
        if ( ! empty( $invoice ) ) {
            $order_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_nwc_invoice' 
                AND meta_value = %s 
                LIMIT 1",
                $invoice
            ) );

            if ( $order_id ) {
                return wc_get_order( $order_id );
            }
        }

        // Try to find by payment hash
        if ( ! empty( $payment_hash ) ) {
            $order_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_nwc_payment_hash' 
                AND meta_value = %s 
                LIMIT 1",
                $payment_hash
            ) );

            if ( $order_id ) {
                return wc_get_order( $order_id );
            }
        }

        return false;
    }

    /**
     * Get webhook URL for admin display
     *
     * @return string Webhook URL
     */
    public static function get_webhook_url() {
        return rest_url( 'nostr-login-pay/v1/webhook/payment' );
    }
}

// Initialize webhook handler
new Nostr_Login_Pay_Payment_Webhook();

