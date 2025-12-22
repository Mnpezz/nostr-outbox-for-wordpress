<?php
/**
 * Lightning Payment Page Template
 *
 * Shown on order received page for Lightning NWC payments
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Order ID is passed from thankyou_page method
if ( ! isset( $order_id ) ) {
    $order_id = 0;
}

$order = wc_get_order( $order_id );

if ( ! $order ) {
    echo '<p>' . esc_html__( 'Order not found.', 'nostr-outbox-wordpress' ) . '</p>';
    return;
}

$amount_sats = $order->get_meta( '_nwc_amount_sats' );
$invoice = $order->get_meta( '_nwc_invoice' );
$payment_hash = $order->get_meta( '_nwc_payment_hash' );
?>

<div class="nwc-payment-container" id="nwc-payment-<?php echo esc_attr( $order_id ); ?>">
    <div class="nwc-payment-header" style="text-align: center; padding: 20px; background: #f0f9ff; border-radius: 8px; margin-bottom: 20px;">
        <h2 style="margin: 0 0 10px 0; color: #1e40af;">
            ⚡ <?php esc_html_e( 'Lightning Payment', 'nostr-outbox-wordpress' ); ?>
        </h2>
        <p style="margin: 0; font-size: 24px; font-weight: bold; color: #0ea5e9;">
            <?php echo number_format( $amount_sats ); ?> <span style="font-size: 18px;">sats</span>
        </p>
        <p style="margin: 5px 0 0 0; font-size: 14px; color: #64748b;">
            <?php echo wc_price( $order->get_total() ); ?> <?php echo get_woocommerce_currency(); ?>
        </p>
    </div>

    <div id="nwc-payment-status" class="nwc-payment-status" style="text-align: center; padding: 30px;">
        <!-- Status will be updated by JavaScript -->
        <div class="spinner" style="margin: 20px auto; border: 4px solid #f3f3f3; border-top: 4px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;"></div>
        <p style="color: #64748b; margin-top: 15px;"><?php esc_html_e( 'Creating Lightning invoice...', 'nostr-outbox-wordpress' ); ?></p>
    </div>

    <div id="nwc-invoice-display" class="nwc-invoice-display" style="display: none; text-align: center; padding: 20px;">
        <!-- Invoice QR code and details will be inserted here -->
    </div>

    <div id="nwc-payment-complete" class="nwc-payment-complete" style="display: none; text-align: center; padding: 30px; background: #f0fdf4; border: 2px solid #22c55e; border-radius: 8px;">
        <div style="font-size: 48px; margin-bottom: 10px;">✓</div>
        <h3 style="color: #15803d; margin: 0 0 10px 0;"><?php esc_html_e( 'Payment Received!', 'nostr-outbox-wordpress' ); ?></h3>
        <p style="color: #166534; margin: 0;"><?php esc_html_e( 'Your order is being processed.', 'nostr-outbox-wordpress' ); ?></p>
    </div>

    <div id="nwc-payment-error" class="nwc-payment-error" style="display: none; text-align: center; padding: 20px; background: #fee; border: 2px solid #ef4444; border-radius: 8px;">
        <!-- Error message will be inserted here -->
    </div>
</div>

<style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .nwc-invoice-qr {
        margin: 20px auto;
        padding: 20px;
        background: white;
        border-radius: 8px;
        display: inline-block;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .nwc-invoice-string {
        margin: 20px 0;
        padding: 15px;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        word-break: break-all;
        font-family: monospace;
        font-size: 12px;
        color: #333;
    }
    
    .nwc-copy-button {
        background: #3b82f6;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
    }
    
    .nwc-copy-button:hover {
        background: #2563eb;
    }
    
    .nwc-payment-instructions {
        margin-top: 20px;
        padding: 15px;
        background: #fffbeb;
        border-left: 4px solid #f59e0b;
        border-radius: 4px;
        text-align: left;
    }

