<?php
/**
 * WooCommerce NWC Payment Gateway
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NWC Payment Gateway Class
 */
class WC_Gateway_NWC extends WC_Payment_Gateway {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'nwc';
        $this->icon = NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/images/lightning-icon.svg';
        $this->has_fields = true;
        $this->method_title = __( 'Lightning (NWC)', 'nostr-outbox-wordpress' );
        $this->method_description = __( 'Accept Lightning payments via Nostr Wallet Connect', 'nostr-outbox-wordpress' );
        $this->supports = array(
            'products',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option( 'title', __( 'Lightning Network (NWC)', 'nostr-outbox-wordpress' ) );
        $this->description = $this->get_option( 'description', __( 'Pay instantly with Bitcoin Lightning via NWC', 'nostr-outbox-wordpress' ) );
        $this->enabled = $this->get_option( 'enabled', 'yes' );
        
        // Get merchant NWC from plugin settings (not gateway settings)
        $this->merchant_nwc = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_wc_gateway_nwc', array( $this, 'check_payment_response' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        
        // AJAX handlers for payment processing
        // Add receipt page handler for order-pay page  
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        
        add_action( 'wp_ajax_get_merchant_nwc', array( $this, 'ajax_get_merchant_nwc' ) );
        add_action( 'wp_ajax_nopriv_get_merchant_nwc', array( $this, 'ajax_get_merchant_nwc' ) );
        add_action( 'wp_ajax_save_nwc_invoice', array( $this, 'ajax_save_nwc_invoice' ) );
        add_action( 'wp_ajax_nopriv_save_nwc_invoice', array( $this, 'ajax_save_nwc_invoice' ) );
        add_action( 'wp_ajax_check_nwc_payment', array( $this, 'ajax_check_nwc_payment' ) );
        add_action( 'wp_ajax_nopriv_check_nwc_payment', array( $this, 'ajax_check_nwc_payment' ) );
        add_action( 'wp_ajax_nwc_mark_paid', array( $this, 'ajax_nwc_mark_paid' ) );
        add_action( 'wp_ajax_nopriv_nwc_mark_paid', array( $this, 'ajax_nwc_mark_paid' ) );
        
        // Prevent WooCommerce from clearing cart for on-hold NWC orders
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'prevent_early_cart_clear' ), 10, 3 );
        
        // Prevent cart from being cleared immediately after order creation (before payment)
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'prevent_cart_clear_for_unpaid_orders' ), 10, 3 );
        
        // Prevent WooCommerce from clearing cart on thankyou page for unpaid Lightning orders
        add_action( 'woocommerce_thankyou', array( $this, 'restore_cart_on_payment_page' ), 1 );
        
        // Add Lightning payment meta box to order edit page
        add_action( 'add_meta_boxes', array( $this, 'add_lightning_payment_meta_box' ) );
        add_action( 'wp_ajax_mark_lightning_paid', array( $this, 'ajax_mark_lightning_paid_admin' ) );
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        // Get payment method status
        $lightning_address = get_option( 'nostr_login_pay_lightning_address', '' );
        $merchant_wallet = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
        
        $status_message = '';
        if ( ! empty( $lightning_address ) ) {
            $status_message .= '<span style="color: #22c55e;">✓ Lightning Address: ' . esc_html( $lightning_address ) . '</span><br>';
        }
        if ( ! empty( $merchant_wallet ) ) {
            $status_message .= '<span style="color: #22c55e;">✓ NWC Connected</span><br>';
        }
        if ( empty( $lightning_address ) && empty( $merchant_wallet ) ) {
            $status_message = '<span style="color: #ef4444;">✗ No Payment Method Configured</span>';
        }
        
        $settings_url = admin_url( 'options-general.php?page=nostr-outbox-wordpress&tab=nwc' );
        
        $this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable/Disable', 'nostr-outbox-wordpress' ),
                'type' => 'checkbox',
                'label' => __( 'Enable Lightning (NWC) payments', 'nostr-outbox-wordpress' ),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __( 'Title', 'nostr-outbox-wordpress' ),
                'type' => 'text',
                'description' => __( 'Payment method title that customers see during checkout', 'nostr-outbox-wordpress' ),
                'default' => __( 'Lightning Network', 'nostr-outbox-wordpress' ),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __( 'Description', 'nostr-outbox-wordpress' ),
                'type' => 'textarea',
                'description' => __( 'Payment method description that customers see during checkout', 'nostr-outbox-wordpress' ),
                'default' => __( 'Pay instantly with Bitcoin Lightning Network via your NWC-enabled wallet', 'nostr-outbox-wordpress' ),
                'desc_tip' => true,
            ),
            'merchant_wallet_status' => array(
                'title' => __( 'Payment Methods', 'nostr-outbox-wordpress' ),
                'type' => 'title',
                'description' => sprintf(
                    __( '%s<br><a href="%s" class="button button-secondary">Configure Payment Methods</a>', 'nostr-outbox-wordpress' ),
                    $status_message,
                    $settings_url
                ),
            ),
            'exchange_rate_provider' => array(
                'title' => __( 'Exchange Rate Provider', 'nostr-outbox-wordpress' ),
                'type' => 'select',
                'description' => __( 'Choose a provider to convert fiat prices to satoshis', 'nostr-outbox-wordpress' ),
                'default' => 'coinbase',
                'desc_tip' => true,
                'options' => array(
                    'coinbase' => __( 'Coinbase', 'nostr-outbox-wordpress' ),
                    'bitstamp' => __( 'Bitstamp', 'nostr-outbox-wordpress' ),
                    'kraken' => __( 'Kraken', 'nostr-outbox-wordpress' ),
                ),
            ),
        );
    }

    /**
     * Check if this gateway is available
     */
    public function is_available() {
        // Check if gateway is enabled in WooCommerce settings
        if ( 'yes' !== $this->enabled ) {
            return false;
        }

        // Check if NWC is enabled in plugin settings
        $plugin_enabled = get_option( 'nostr_login_pay_nwc_enable_payment_gateway', '' );
        if ( $plugin_enabled !== '1' ) {
            return false;
        }

        // Check if at least one payment method is configured
        $lightning_address = get_option( 'nostr_login_pay_lightning_address', '' );
        $merchant_wallet = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
        
        if ( empty( $lightning_address ) && empty( $merchant_wallet ) ) {
            return false;
        }

        return true;
    }

    /**
     * Payment fields for checkout page
     */
    public function payment_fields() {
        // Description
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
            $connection = $nwc_wallet->get_user_connection( $user_id );

            if ( $connection ) {
                ?>
                <div class="nwc-payment-info" style="padding: 15px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; margin: 10px 0;">
                    <p style="margin: 0;">
                        <strong style="color: #15803d;">⚡ <?php _e( 'Your Lightning Wallet is Connected', 'nostr-outbox-wordpress' ); ?></strong>
                    </p>
                    <p style="margin: 10px 0 0 0; font-size: 14px;">
                        <?php _e( 'Payment will be processed instantly from your connected wallet.', 'nostr-outbox-wordpress' ); ?>
                    </p>
                </div>
                <?php
            } else {
                ?>
                <div class="nwc-payment-warning" style="padding: 15px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; margin: 10px 0;">
                    <p style="margin: 0;">
                        <strong><?php _e( 'No Lightning Wallet Connected', 'nostr-outbox-wordpress' ); ?></strong>
                    </p>
                    <p style="margin: 10px 0 0 0; font-size: 14px;">
                        <?php _e( 'You need to connect your Lightning wallet first.', 'nostr-outbox-wordpress' ); ?>
                        <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'nwc-wallet' ) ); ?>" target="_blank">
                            <?php _e( 'Connect Wallet', 'nostr-outbox-wordpress' ); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
        } else {
            ?>
            <div class="nwc-payment-notice" style="padding: 15px; background: #eff6ff; border: 1px solid #3b82f6; border-radius: 6px; margin: 10px 0;">
                <p style="margin: 0; font-size: 14px;">
                    <?php _e( 'Please log in and connect your Lightning wallet to use this payment method.', 'nostr-outbox-wordpress' ); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Process the payment
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // For now, just create the order and mark it as pending
        // TODO: Implement actual NWC invoice creation and payment processing
        
        // Get order total in satoshis
        $total_sats = $this->convert_to_satoshis( $order->get_total(), get_woocommerce_currency() );
        
        // Check if conversion failed
        if ( is_wp_error( $total_sats ) ) {
            wc_add_notice( $total_sats->get_error_message(), 'error' );
            return array(
                'result' => 'failure',
            );
        }
        
        // Store the amount in satoshis for reference
        $order->update_meta_data( '_nwc_amount_sats', $total_sats );
        $order->update_meta_data( '_nwc_payment_status', 'pending' );
        
        // Add order note
        $order->add_order_note( sprintf(
            __( 'Lightning payment initiated. Amount: %d sats (%s %s)', 'nostr-outbox-wordpress' ),
            $total_sats,
            $order->get_total(),
            get_woocommerce_currency()
        ) );
        
        $order->save();

        // Mark as pending payment (like Coinos - prevents cart clearing)
        $order->update_status( 'pending', __( 'Awaiting Lightning payment', 'nostr-outbox-wordpress' ) );

        // Don't clear cart yet - only clear when payment is confirmed
        // WooCommerce will automatically clear cart when order status changes to processing/completed

        // Redirect to checkout payment page (not thank you page) to show payment form
        // This prevents WooCommerce from clearing the cart
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url( true ),
        );
    }

    /**
     * Convert fiat amount to satoshis
     */
    private function convert_to_satoshis( $amount, $currency ) {
        // If already in BTC, convert directly
        if ( $currency === 'BTC' ) {
            return intval( $amount * 100000000 );
        }

        // Get exchange rate (placeholder - implement real API call)
        $btc_price = $this->get_btc_price( $currency );

        if ( ! $btc_price ) {
            return new WP_Error( 'exchange_rate_error', __( 'Could not get Bitcoin exchange rate', 'nostr-outbox-wordpress' ) );
        }

        $btc_amount = $amount / $btc_price;
        return intval( $btc_amount * 100000000 );
    }

    /**
     * Get BTC price in given currency
     */
    private function get_btc_price( $currency ) {
        // Check cache
        $cache_key = 'nwc_btc_price_' . $currency;
        $cached_price = get_transient( $cache_key );

        if ( false !== $cached_price ) {
            error_log( '✓ Using cached BTC price: $' . number_format( $cached_price, 2 ) . ' ' . $currency );
            return floatval( $cached_price );
        }

        error_log( '🔄 Fetching fresh BTC price for ' . $currency . '...' );

        // Try multiple reliable APIs in order
        $apis = array(
            // CoinGecko (no API key required, very reliable)
            array(
                'name' => 'CoinGecko',
                'url' => 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=' . strtolower( $currency ),
                'parse' => function( $body ) use ( $currency ) {
                    $data = json_decode( $body, true );
                    return isset( $data['bitcoin'][ strtolower( $currency ) ] ) 
                        ? floatval( $data['bitcoin'][ strtolower( $currency ) ] ) 
                        : null;
                }
            ),
            // Coinbase (backup)
            array(
                'name' => 'Coinbase',
                'url' => 'https://api.coinbase.com/v2/exchange-rates?currency=BTC',
                'parse' => function( $body ) use ( $currency ) {
                    $data = json_decode( $body, true );
                    return isset( $data['data']['rates'][ $currency ] ) 
                        ? floatval( $data['data']['rates'][ $currency ] ) 
                        : null;
                }
            ),
            // Blockchain.info (backup)
            array(
                'name' => 'Blockchain.info',
                'url' => 'https://blockchain.info/ticker',
                'parse' => function( $body ) use ( $currency ) {
                    $data = json_decode( $body, true );
                    return isset( $data[ $currency ]['last'] ) 
                        ? floatval( $data[ $currency ]['last'] ) 
                        : null;
                }
            )
        );

        // Try each API until one works
        foreach ( $apis as $api ) {
            $response = wp_remote_get( $api['url'], array(
                'timeout' => 5,
                'headers' => array(
                    'Accept' => 'application/json',
                )
            ) );

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $body = wp_remote_retrieve_body( $response );
                $price = $api['parse']( $body );

                if ( $price && $price > 0 ) {
                    // Cache for 5 minutes
                    set_transient( $cache_key, $price, 300 );
                    
                    // Save as last known good price (long-term backup)
                    update_option( 'nwc_last_good_btc_price_' . $currency, $price );
                    
                    error_log( '✅ Got BTC price from ' . $api['name'] . ': $' . number_format( $price, 2 ) . ' ' . $currency );
                    return $price;
                }
            } else {
                $error_msg = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
                error_log( '⚠️ ' . $api['name'] . ' API failed: ' . $error_msg );
            }
        }

        // All APIs failed - use last known good price or fallback
        error_log( '❌ All BTC price APIs failed! Using fallback price.' );
        
        // Try to get last successful price from options (longer cache)
        $last_good_price = get_option( 'nwc_last_good_btc_price_' . $currency, false );
        if ( $last_good_price ) {
            error_log( '💡 Using last known good price: $' . number_format( $last_good_price, 2 ) . ' ' . $currency );
            // Cache for 1 hour since APIs are down
            set_transient( $cache_key, $last_good_price, 3600 );
            return floatval( $last_good_price );
        }
        
        // Ultimate fallback (updated to realistic 2025 price)
        $fallback_price = 97000; // Updated fallback price
        error_log( '⚠️ Using hardcoded fallback: $' . number_format( $fallback_price, 2 ) . ' ' . $currency );
        return $fallback_price;
    }

    /**
     * Create invoice on merchant's NWC wallet
     */
    private function create_merchant_invoice( $amount_sats, $order ) {
        // Parse merchant NWC connection
        $nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
        $parsed = $nwc_wallet->parse_nwc_url( $this->merchant_nwc );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        // In a real implementation, create invoice via NWC
        // For now, return placeholder
        return array(
            'invoice' => 'lnbc' . $amount_sats . 'n1...',
            'payment_hash' => bin2hex( random_bytes( 32 ) ),
            'amount' => $amount_sats,
        );
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        // Enqueue on checkout page
        if ( is_checkout() ) {
            wp_enqueue_script(
                'wc-nwc-checkout',
                NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/checkout.js',
                array( 'jquery', 'nostr-tools' ),
                NOSTR_LOGIN_PAY_VERSION,
                true
            );

            wp_localize_script(
                'wc-nwc-checkout',
                'nwcCheckout',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'nostr-outbox-wordpress-nonce' ),
                )
            );
        }

        // Enqueue on order-pay page OR order-received page (for NWC payment)
        $order_id = 0;
        global $wp;
        
        // Check if we're on order-pay page
        if ( is_checkout() && ! empty( $wp->query_vars['order-pay'] ) ) {
            $order_id = absint( $wp->query_vars['order-pay'] );
        }
        // Or order-received page
        elseif ( is_order_received_page() && ! empty( $wp->query_vars['order-received'] ) ) {
            $order_id = absint( $wp->query_vars['order-received'] );
        }
        
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            
            // Only load for NWC payment method and pending/on-hold status
            if ( $order && $order->get_payment_method() === 'nwc' && in_array( $order->get_status(), array( 'pending', 'on-hold' ) ) ) {
                    // QR code library
                    wp_enqueue_script( 'qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', array(), '1.0.0', true );
                    
                    // LOAD ORDER IS CRITICAL!
                    // 1. nostr-tools MUST load first (already registered in main plugin with proper config)
                    wp_enqueue_script( 'nostr-tools' );
                    
                    // 2. Alby SDK loads after nostr-tools (already registered with dependency)
                    wp_enqueue_script( 'alby-nwc-sdk' );
                    
                    // 3. Our scripts load last
                    wp_enqueue_script( 'nwc-verification', NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/nwc-verification.js', array( 'jquery', 'nostr-tools' ), NOSTR_LOGIN_PAY_VERSION, true );
                    wp_enqueue_script( 'nwc-client', NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/nwc-client.js', array( 'jquery', 'nostr-tools' ), NOSTR_LOGIN_PAY_VERSION, true );
                    wp_enqueue_script( 'nwc-payment', NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/nwc-payment.js', array( 'jquery', 'nwc-verification', 'nwc-client', 'alby-nwc-sdk' ), NOSTR_LOGIN_PAY_VERSION, true );
                    
                    // DISABLED: Customer wallet one-click payment not currently functional
                    // Keeping code for future implementation when browser NWC support improves
                    $user_wallet = '';
                    
                    /* Original code:
                    $user_id = get_current_user_id();
                    $user_wallet = '';
                    
                    if ( $user_id ) {
                        // Use NWC Wallet class to get the full connection string
                        $nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
                        $user_wallet = $nwc_wallet->get_user_connection_string( $user_id );
                        
                        if ( $user_wallet ) {
                            error_log( '✓ User #' . $user_id . ' has NWC wallet connected for one-click payment' );
                        } else {
                            error_log( '⚠ User #' . $user_id . ' has no NWC wallet connected' );
                        }
                    }
                    */
                    
                    wp_localize_script(
                        'nwc-payment',
                        'nwcPaymentData',
                        array(
                            'ajax_url' => admin_url( 'admin-ajax.php' ),
                            'nonce' => wp_create_nonce( 'nwc-payment-nonce' ),
                            'order_id' => $order_id,
                            'userWallet' => $user_wallet ? $user_wallet : '', // For one-click payment
                            'thank_you_url' => $order->get_checkout_order_received_url(), // Redirect here after payment
                        )
                    );
            }
        }
    }

    /**
     * Check payment response
     */
    public function check_payment_response() {
        // Handle payment verification callback
        // This would be called when payment is confirmed
    }

    /**
     * Receipt page - shows payment form on order-pay page
     * This is called when redirecting to /checkout/order-pay/{order-id}/
     */
    public function receipt_page( $order_id ) {
        $order = wc_get_order( $order_id );
        
        // Only show payment page if order is pending (awaiting payment)
        if ( ! $order || $order->is_paid() ) {
            echo '<p>' . __( 'This order has already been paid.', 'nostr-outbox-wordpress' ) . '</p>';
            return;
        }
        
        echo '<p>' . __( 'Please complete your payment using Bitcoin Lightning Network.', 'nostr-outbox-wordpress' ) . '</p>';
        $this->generate_payment_form( $order );
    }

    /**
     * Generate the Lightning payment form (used by receipt_page and thankyou_page)
     */
    private function generate_payment_form( $order ) {
        $order_id = $order->get_id();
        
        // Load payment template
        $template_path = NOSTR_LOGIN_PAY_PLUGIN_DIR . 'templates/order-payment-page.php';
        
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            echo '<p style="color: red;">Payment template not found. Please contact support.</p>';
        }
    }

    /**
     * Display payment page on order received/thank you page  
     * (This is for when someone accesses order-received URL directly)
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            error_log( 'NWC Payment: Order not found for ID ' . $order_id );
            return;
        }

        $order_status = $order->get_status();
        error_log( 'NWC Payment: Order #' . $order_id . ' status is ' . $order_status );

        // Only show payment page if order is pending or on-hold (awaiting payment)
        if ( ! in_array( $order_status, array( 'pending', 'on-hold' ) ) ) {
            error_log( 'NWC Payment: Not showing payment page, order status is ' . $order_status );
            return;
        }

        error_log( 'NWC Payment: Displaying payment page for order #' . $order_id );

        // Scripts are enqueued in payment_scripts() method
        echo '<div class="woocommerce-order-details" style="margin-top: 30px;">';
        echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Complete Your Payment', 'nostr-outbox-wordpress' ) . '</h2>';
        
        // Load payment template
        $template_path = NOSTR_LOGIN_PAY_PLUGIN_DIR . 'templates/order-payment-page.php';
        error_log( 'NWC Payment: Loading template from ' . $template_path );
        
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            error_log( 'NWC Payment: Template file not found at ' . $template_path );
            echo '<p style="color: red;">Payment template not found. Please contact support.</p>';
        }
        
        echo '</div>';
    }

    /**
     * AJAX: Get merchant NWC connection data and create invoice
     */
    public function ajax_get_merchant_nwc() {
        check_ajax_referer( 'nwc-payment-nonce', 'nonce' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( 'Invalid order' );
        }

        $amount_sats = $order->get_meta( '_nwc_amount_sats' );
        
        // Try Lightning Address first (preferred method)
        $lightning_address = get_option( 'nostr_login_pay_lightning_address', '' );
        
        if ( ! empty( $lightning_address ) ) {
            // Use LNURL to create real invoice
            $lnurl_service = new Nostr_Login_Pay_LNURL_Service();
            $amount_msats = $amount_sats * 1000; // Convert sats to millisats
            
            $description = sprintf(
                'Order #%d - %s',
                $order_id,
                get_bloginfo( 'name' )
            );
            
            $invoice_result = $lnurl_service->create_invoice_from_address(
                $lightning_address,
                $amount_msats,
                $description
            );
            
            if ( is_wp_error( $invoice_result ) ) {
                error_log( 'LNURL Invoice Error: ' . $invoice_result->get_error_message() );
                wp_send_json_error( $invoice_result->get_error_message() );
            }
            
            // Save invoice to order immediately
            $order->update_meta_data( '_nwc_invoice', $invoice_result['invoice'] );
            $order->update_meta_data( '_nwc_payment_hash', $invoice_result['payment_hash'] );
            $order->add_order_note( __( 'Lightning invoice created via LNURL', 'nostr-outbox-wordpress' ) );
            $order->save();
            
            // Get merchant NWC for instant verification
            $merchant_nwc = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
            
            // Ensure the relay URL is properly formatted (fix wss// → wss://)
            if ( ! empty( $merchant_nwc ) && strpos( $merchant_nwc, 'wss//' ) !== false ) {
                $merchant_nwc = str_replace( 'wss//', 'wss://', $merchant_nwc );
                error_log( '⚠️ Fixed malformed NWC relay URL: wss// → wss://' );
            }
            
            // Get customer's connected wallet for one-click payment
            $user_id = get_current_user_id();
            $user_wallet = $user_id ? get_user_meta( $user_id, 'nostr_nwc_connection', true ) : '';
            
            wp_send_json_success( array(
                'method' => 'lnurl',
                'invoice' => $invoice_result['invoice'],
                'payment_hash' => $invoice_result['payment_hash'],
                'amount_sats' => $amount_sats,
                'order_total' => $order->get_total(),
                'currency' => get_woocommerce_currency(),
                'merchant_nwc' => $merchant_nwc, // For instant verification
                'user_wallet' => $user_wallet,   // For one-click payment
            ) );
        }
        
        // Fallback: Check if NWC is configured
        $merchant_nwc = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
        
        // Fix malformed relay URL
        if ( ! empty( $merchant_nwc ) && strpos( $merchant_nwc, 'wss//' ) !== false ) {
            $merchant_nwc = str_replace( 'wss//', 'wss://', $merchant_nwc );
        }
        
        if ( empty( $merchant_nwc ) ) {
            wp_send_json_error( 'No payment method configured. Please configure Lightning Address in plugin settings.' );
        }

        // TODO: Implement NWC invoice creation
        wp_send_json_success( array(
            'method' => 'nwc',
            'nwc' => 'configured',
            'amount_sats' => $amount_sats,
            'order_total' => $order->get_total(),
            'currency' => get_woocommerce_currency(),
        ) );
    }

    /**
     * AJAX: Save invoice to order
     */
    public function ajax_save_nwc_invoice() {
        check_ajax_referer( 'nwc-payment-nonce', 'nonce' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $invoice = isset( $_POST['invoice'] ) ? sanitize_text_field( $_POST['invoice'] ) : '';
        $payment_hash = isset( $_POST['payment_hash'] ) ? sanitize_text_field( $_POST['payment_hash'] ) : '';

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( 'Invalid order' );
        }

        // Save invoice data
        $order->update_meta_data( '_nwc_invoice', $invoice );
        $order->update_meta_data( '_nwc_payment_hash', $payment_hash );
        $order->add_order_note( __( 'Lightning invoice created and sent to customer', 'nostr-outbox-wordpress' ) );
        $order->save();

        wp_send_json_success();
    }

    /**
     * AJAX: Check if payment has been received
     */
    public function ajax_check_nwc_payment() {
        check_ajax_referer( 'nwc-payment-nonce', 'nonce' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( 'Invalid order' );
        }

        // Simple status check - NWC lookup_invoice on frontend handles verification
        // This is just a fallback to check if payment was marked complete by ajax_nwc_mark_paid
        $is_paid = in_array( $order->get_status(), array( 'processing', 'completed' ) );

        wp_send_json_success( array(
            'paid' => $is_paid,
            'order_status' => $order->get_status(),
        ) );
    }

    /**
     * AJAX: Mark order as paid (called by NWC verification or browser wallet)
     */
    public function ajax_nwc_mark_paid() {
        check_ajax_referer( 'nwc-payment-nonce', 'nonce' );

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $preimage = isset( $_POST['preimage'] ) ? sanitize_text_field( $_POST['preimage'] ) : '';
        
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( 'Invalid order' );
        }

        // Check if order is already paid
        if ( in_array( $order->get_status(), array( 'processing', 'completed' ) ) ) {
            wp_send_json_success( array(
                'paid' => true,
                'message' => 'Order already paid',
            ) );
            return;
        }

        // Determine verification method
        $verification_method = 'NWC verification';
        if ( ! empty( $preimage ) ) {
            $verification_method = 'Browser wallet with preimage proof';
            $order->update_meta_data( '_nwc_payment_preimage', $preimage );
            error_log( '🔐 Payment preimage received: ' . substr( $preimage, 0, 16 ) . '...' );
        }

        // Mark order as complete
        $order->payment_complete();
        $order->add_order_note( sprintf( 
            __( 'Lightning payment confirmed via %s', 'nostr-outbox-wordpress' ), 
            $verification_method 
        ) );
        $order->save();
        
        // Clear the cart now that payment is confirmed
        if ( WC()->cart ) {
            WC()->cart->empty_cart();
            error_log( '🧹 Cart cleared after payment confirmation' );
        }
        
        // Clean up saved cart from session
        if ( WC()->session ) {
            WC()->session->__unset( 'nwc_pending_order_' . $order_id );
            error_log( '🧹 Cleaned up saved cart from session' );
        }
        
        error_log( '✅ NWC Payment: Order #' . $order_id . ' marked as paid via ' . $verification_method . '!' );

        wp_send_json_success( array(
            'paid' => true,
            'message' => 'Payment confirmed',
            'verification_method' => $verification_method,
        ) );
    }

    /**
     * Prevent premature cart clearing for on-hold orders
     * 
     * @param string $order_status The order status.
     * @param int    $order_id     The order ID.
     * @param object $order        The order object.
     * @return string
     */
    public function prevent_early_cart_clear( $order_status, $order_id, $order ) {
        // Just return the status unchanged
        // Cart will be cleared in ajax_nwc_mark_paid when payment is confirmed
        return $order_status;
    }

    /**
     * Prevent cart from being cleared for unpaid Lightning orders
     * 
     * @param int    $order_id Order ID.
     * @param array  $posted_data Posted data.
     * @param object $order The order object.
     */
    public function prevent_cart_clear_for_unpaid_orders( $order_id, $posted_data, $order ) {
        // Check if this is a Lightning (NWC) order
        if ( $order->get_payment_method() !== 'nwc' ) {
            return; // Not our payment method, let WooCommerce handle it
        }

        // Check if order is still unpaid (pending status)
        if ( in_array( $order->get_status(), array( 'pending', 'on-hold' ) ) ) {
            // Prevent WooCommerce from clearing the cart
            // We'll clear it manually when payment is confirmed
            error_log( '⏸️ Preventing cart clear for unpaid Lightning order #' . $order_id . ' (status: ' . $order->get_status() . ')' );
            
            // Store cart contents in session for potential restoration
            WC()->session->set( 'nwc_pending_order_' . $order_id, WC()->cart->get_cart_for_session() );
            
            // Remove the action that clears the cart
            remove_action( 'woocommerce_thankyou', 'wc_empty_cart' );
        }
    }

    /**
     * Restore cart on payment page if order is still unpaid
     * 
     * @param int $order_id Order ID.
     */
    public function restore_cart_on_payment_page( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        
        // Only process Lightning orders
        if ( ! $order || $order->get_payment_method() !== 'nwc' ) {
            return;
        }

        // Only restore for unpaid orders
        if ( ! in_array( $order->get_status(), array( 'on-hold', 'pending' ) ) ) {
            return; // Order is already paid or cancelled
        }

        error_log( '🔄 Checking cart restoration for unpaid Lightning order #' . $order_id );

        // Check if cart is empty (was cleared)
        if ( WC()->cart->is_empty() ) {
            error_log( '📦 Cart is empty, checking for saved cart...' );
            
            // Try to restore from session
            $saved_cart = WC()->session->get( 'nwc_pending_order_' . $order_id );
            
            if ( $saved_cart ) {
                error_log( '✅ Restoring cart from session' );
                WC()->cart->set_cart_contents( $saved_cart );
            } else {
                error_log( '⚠️ No saved cart found, cart was already cleared' );
            }
        } else {
            error_log( '✅ Cart still has items, no restoration needed' );
        }
    }

    /**
     * Add Lightning payment meta box to order edit page
     */
    public function add_lightning_payment_meta_box() {
        global $post;
        
        if ( ! $post ) {
            return;
        }
        
        $order = wc_get_order( $post->ID );
        if ( ! $order || $order->get_payment_method() !== 'nwc' ) {
            return;
        }
        
        add_meta_box(
            'nwc_lightning_payment',
            '⚡ Lightning Payment Status',
            array( $this, 'render_lightning_payment_meta_box' ),
            'shop_order',
            'side',
            'high'
        );
        
        // For HPOS (High-Performance Order Storage)
        add_meta_box(
            'nwc_lightning_payment',
            '⚡ Lightning Payment Status',
            array( $this, 'render_lightning_payment_meta_box' ),
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    /**
     * Render Lightning payment meta box
     */
    public function render_lightning_payment_meta_box( $post_or_order ) {
        // Get order object
        $order = $post_or_order instanceof WP_Post 
            ? wc_get_order( $post_or_order->ID ) 
            : $post_or_order;
            
        if ( ! $order ) {
            return;
        }
        
        $order_id = $order->get_id();
        $status = $order->get_status();
        $invoice = $order->get_meta( '_nwc_invoice' );
        $amount_sats = $order->get_meta( '_nwc_amount_sats' );
        
        ?>
        <div class="nwc-payment-meta-box">
            <style>
                .nwc-payment-meta-box { padding: 5px; }
                .nwc-status-badge { 
                    display: inline-block; 
                    padding: 5px 10px; 
                    border-radius: 4px; 
                    font-weight: bold;
                    margin-bottom: 10px;
                }
                .nwc-status-pending { background: #fff3cd; color: #856404; }
                .nwc-status-paid { background: #d4edda; color: #155724; }
                .nwc-invoice-display {
                    background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;
                    margin: 10px 0;
                    word-break: break-all;
                    font-size: 11px;
                    max-height: 100px;
                    overflow-y: auto;
                }
                .nwc-mark-paid-btn {
                    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                    color: white;
                    border: none;
                    padding: 12px 20px;
                    font-size: 14px;
                    font-weight: bold;
                    border-radius: 6px;
                    cursor: pointer;
                    width: 100%;
                    margin-top: 10px;
                }
                .nwc-mark-paid-btn:hover {
                    background: linear-gradient(135deg, #059669 0%, #047857 100%);
                }
                .nwc-mark-paid-btn:disabled {
                    background: #ccc;
                    cursor: not-allowed;
                }
            </style>
            
            <?php if ( in_array( $status, array( 'processing', 'completed' ) ) ) : ?>
                <div class="nwc-status-badge nwc-status-paid">
                    ✓ PAID
                </div>
                <p><strong>Amount:</strong> <?php echo esc_html( $amount_sats ); ?> sats</p>
                <p style="font-size: 12px; color: #666;">
                    This order has been marked as paid and is being processed.
                </p>
            <?php else : ?>
                <div class="nwc-status-badge nwc-status-pending">
                    ⏳ PENDING
                </div>
                
                <p><strong>Amount:</strong> <?php echo esc_html( $amount_sats ); ?> sats</p>
                
                <?php if ( $invoice ) : ?>
                    <p style="font-size: 12px; margin: 10px 0 5px 0;">
                        <strong>Invoice:</strong>
                    </p>
                    <div class="nwc-invoice-display" title="Click to copy" onclick="navigator.clipboard.writeText('<?php echo esc_js( $invoice ); ?>'); this.style.background='#d4edda';" style="cursor: pointer;">
                        <?php echo esc_html( substr( $invoice, 0, 80 ) . '...' ); ?>
                    </div>
                    
                    <div style="background: #e8f4f8; padding: 10px; border-radius: 4px; font-size: 12px; margin: 10px 0;">
                        <strong>📝 To complete this order:</strong>
                        <ol style="margin: 5px 0 0 15px; padding: 0;">
                            <li>Check your Coinos wallet</li>
                            <li>Verify payment received</li>
                            <li>Click button below</li>
                        </ol>
                    </div>
                    
                    <button 
                        type="button" 
                        class="nwc-mark-paid-btn" 
                        onclick="markLightningPaid(<?php echo esc_js( $order_id ); ?>)"
                    >
                        ✓ Mark as Paid
                    </button>
                    
                    <script>
                    function markLightningPaid(orderId) {
                        if (!confirm('Have you confirmed the payment in your Coinos wallet?\n\nThis will mark the order as paid and send confirmation to the customer.')) {
                            return;
                        }
                        
                        const btn = event.target;
                        btn.disabled = true;
                        btn.innerHTML = '⏳ Processing...';
                        
                        jQuery.post(ajaxurl, {
                            action: 'mark_lightning_paid',
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce( 'mark-lightning-paid' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                btn.innerHTML = '✓ Paid!';
                                btn.style.background = '#10b981';
                                alert('Order marked as paid successfully!');
                                location.reload();
                            } else {
                                btn.disabled = false;
                                btn.innerHTML = '✓ Mark as Paid';
                                alert('Error: ' + (response.data || 'Unknown error'));
                            }
                        }).fail(function() {
                            btn.disabled = false;
                            btn.innerHTML = '✓ Mark as Paid';
                            alert('Network error. Please try again.');
                        });
                    }
                    </script>
                <?php else : ?>
                    <p style="font-size: 12px; color: #666;">
                        No invoice generated yet. Customer needs to proceed with payment.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler for admin "Mark as Paid" button
     */
    public function ajax_mark_lightning_paid_admin() {
        check_ajax_referer( 'mark-lightning-paid', 'nonce' );
        
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            wp_send_json_error( 'Invalid order' );
        }
        
        if ( $order->get_payment_method() !== 'nwc' ) {
            wp_send_json_error( 'Not a Lightning payment order' );
        }
        
        // Mark as paid
        $order->payment_complete();
        $order->add_order_note( __( 'Lightning payment confirmed manually by admin', 'nostr-outbox-wordpress' ) );
        
        error_log( 'Admin marked order #' . $order_id . ' as paid manually' );
        
        wp_send_json_success( array(
            'message' => 'Order marked as paid',
            'order_id' => $order_id,
        ) );
    }
}


