<?php
/**
 * Zap Rewards Processor
 * Handles Lightning payments via NWC
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nostr_Outbox_Zap_Rewards_Processor {
    private $nwc_wallet = null;

    public function __construct() {
        error_log( 'Zap Rewards Processor: __construct called (lazy loading wallet)' );
        // Don't initialize wallet in constructor - do it when needed
    }
    
    /**
     * Get NWC wallet instance (lazy loaded)
     */
    private function get_nwc_wallet() {
        if ( $this->nwc_wallet === null ) {
            error_log( 'Zap Rewards Processor: Lazy loading NWC wallet...' );
            
            // Check if NWC wallet class exists
            if ( ! class_exists( 'Nostr_Login_Pay_NWC_Wallet' ) ) {
                error_log( 'Zap Rewards Processor: NWC Wallet class not found!' );
                throw new Exception( 'NWC Wallet class not loaded' );
            }
            
            error_log( 'Zap Rewards Processor: NWC Wallet class exists, creating new instance...' );
            
            try {
                // NWC Wallet is NOT a singleton - instantiate with new
                $this->nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
                error_log( 'Zap Rewards Processor: NWC wallet instance created successfully' );
            } catch ( Exception $e ) {
                error_log( 'Zap Rewards Processor: Failed to create NWC wallet instance: ' . $e->getMessage() );
                error_log( 'Zap Rewards Processor: Exception trace: ' . $e->getTraceAsString() );
                throw $e;
            }
        }
        
        return $this->nwc_wallet;
    }

    /**
     * Lazy load NWC client
     */
    private function get_nwc_client() {
        if ( $this->nwc_client === null ) {
            error_log( 'Zap Rewards Processor: Lazy loading NWC client...' );
            
            // Check if NWC client class exists
            if ( ! class_exists( 'Nostr_Login_Pay_NWC_Client' ) ) {
                error_log( 'Zap Rewards Processor: ERROR - Nostr_Login_Pay_NWC_Client class not found!' );
                throw new Exception( 'NWC Client class not loaded' );
            }
            
            error_log( 'Zap Rewards Processor: NWC Client class exists, creating new instance...' );
            
            try {
                $this->nwc_client = new Nostr_Login_Pay_NWC_Client();
                error_log( 'Zap Rewards Processor: NWC client instance created successfully' );
            } catch ( Exception $e ) {
                error_log( 'Zap Rewards Processor: ERROR - Failed to create NWC Client instance: ' . $e->getMessage() );
                error_log( 'Zap Rewards Processor: Exception trace: ' . $e->getTraceAsString() );
                throw new Exception( 'Failed to initialize NWC Client: ' . $e->getMessage() );
            }
        }
        
        return $this->nwc_client;
    }

    /**
     * Process a reward by sending Lightning payment via NWC
     */
    public function process_reward( $user_id, $type, $amount, $lightning_address, $reference_id = null ) {
        global $wpdb;
        
        error_log( "Zap Rewards Processor: Starting reward - User: $user_id, Type: $type, Amount: $amount sats, Address: $lightning_address, Ref ID: $reference_id" );
        
        // Prepare insert data
        $insert_data = array(
            'user_id' => $user_id,
            'zap_address' => $lightning_address,
            'reward_type' => $type,
            'amount' => $amount,
            'status' => 'pending',
        );

        // Add reference ID based on type
        if ( $reference_id ) {
            if ( $type === 'comment' || $type === 'review' ) {
                $insert_data['comment_id'] = $reference_id;
            } elseif ( $type === 'purchase' ) {
                $insert_data['order_id'] = $reference_id;
            }
        }
        
        // Insert reward record as pending
        $insert_result = $wpdb->insert(
            $wpdb->prefix . 'zap_rewards',
            $insert_data
        );

        if ( $insert_result === false ) {
            error_log( 'Zap Rewards: Failed to insert reward record - ' . $wpdb->last_error );
            return false;
        }

        $reward_id = $wpdb->insert_id;
        error_log( "Zap Rewards: Reward record created with ID: $reward_id" );

        // Try to send the payment immediately
        $result = $this->send_payment( $reward_id, $lightning_address, $amount );
        
        error_log( "Zap Rewards: Payment result: " . ( $result ? 'SUCCESS' : 'FAILED' ) );
        
        return $result;
    }

    /**
     * Send a Lightning payment via NWC
     */
    public function send_payment( $reward_id, $lightning_address, $amount ) {
        global $wpdb;
        
        // Check if NWC is configured
        $nwc_connection = get_option( 'nostr_login_pay_nwc_merchant_wallet' );
        if ( empty( $nwc_connection ) ) {
            error_log( "Zap Rewards: NWC not configured!" );
            $this->update_reward_status( $reward_id, 'failed', 'NWC wallet not configured' );
            return false;
        }

        // Validate minimum amount
        if ( $amount < 1 ) {
            error_log( "Zap Rewards: Amount too small ($amount sats)" );
            $this->update_reward_status( $reward_id, 'failed', 'Amount must be at least 1 sat' );
            return false;
        }

        try {
            // Get or create invoice for the Lightning address
            $invoice = $this->resolve_lightning_address( $lightning_address, $amount );
            
            if ( ! $invoice ) {
                throw new Exception( 'Failed to resolve Lightning address to invoice' );
            }

            error_log( "Zap Rewards: Got invoice, checking payment method..." );
            
            // Try Coinos API first (simpler, more reliable)
            $coinos_token = get_option( 'nostr_zap_rewards_coinos_api_token', '' );
            $use_coinos = ! empty( $coinos_token );
            
            if ( $use_coinos ) {
                error_log( "Zap Rewards: Using Coinos API for payment" );
                return $this->send_payment_via_coinos( $reward_id, $invoice, $coinos_token );
            }
            
            // Fall back to NWC
            error_log( "Zap Rewards: Using NWC for payment (experimental)" );
            $merchant_nwc_connection_string = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
            if ( empty( $merchant_nwc_connection_string ) ) {
                error_log( "Zap Rewards: Merchant NWC connection not configured!" );
                throw new Exception( 'No payment method configured. Either set Coinos API token or NWC connection.' );
            }
            
            error_log( "Zap Rewards: Parsing merchant NWC connection..." );
            
            // Parse the NWC connection string
            $nwc_wallet = $this->get_nwc_wallet();
            $merchant_connection = $nwc_wallet->parse_nwc_url( $merchant_nwc_connection_string );
            
            if ( is_wp_error( $merchant_connection ) ) {
                error_log( "Zap Rewards: Failed to parse NWC connection: " . $merchant_connection->get_error_message() );
                throw new Exception( 'Invalid merchant NWC connection: ' . $merchant_connection->get_error_message() );
            }
            
            error_log( "Zap Rewards: NWC connection parsed successfully, relay: " . $merchant_connection['relay'] );
            
            // Get NWC client
            $nwc_client = $this->get_nwc_client();
            
            error_log( "Zap Rewards: Calling NWC client pay_invoice()..." );
            $payment_result = $nwc_client->pay_invoice( $merchant_connection, $invoice );
            
            if ( is_wp_error( $payment_result ) ) {
                error_log( "Zap Rewards: Payment failed: " . $payment_result->get_error_message() );
                throw new Exception( 'NWC payment failed: ' . $payment_result->get_error_message() );
            }
            
            error_log( "Zap Rewards: Payment result: " . print_r( $payment_result, true ) );

            if ( $payment_result && isset( $payment_result['preimage'] ) ) {
                error_log( "Zap Rewards: ✓ Payment successful! Preimage: " . substr( $payment_result['preimage'], 0, 16 ) . '...' );
                
                // Mark as completed
                $update_result = $wpdb->update(
                    $wpdb->prefix . 'zap_rewards',
                    array(
                        'status' => 'completed',
                        'block_hash' => $payment_result['preimage'],
                    ),
                    array( 'id' => $reward_id )
                );
                
                if ( $update_result === false ) {
                    error_log( "Zap Rewards: ERROR - Failed to update reward status to completed! DB Error: " . $wpdb->last_error );
                } else {
                    error_log( "Zap Rewards: Database updated - Reward ID $reward_id marked as completed" );
                }
                
                return true;
            } else {
                $error_msg = isset( $payment_result['message'] ) ? $payment_result['message'] : ( isset( $payment_result['error'] ) ? $payment_result['error'] : 'Unknown payment error from Coinos API' );
                error_log( "Zap Rewards: ✗ Payment failed: " . $error_msg );
                $this->update_reward_status( $reward_id, 'failed', $error_msg );
                return false;
            }
        } catch ( Exception $e ) {
            error_log( 'Zap Rewards: Exception caught: ' . $e->getMessage() );
            $this->update_reward_status( $reward_id, 'failed', $e->getMessage() );
            return false;
        }
    }

    /**
     * Send payment via Coinos API (fallback/alternative to NWC)
     */
    private function send_payment_via_coinos( $reward_id, $invoice, $coinos_token ) {
        global $wpdb;
        
        try {
            error_log( "Zap Rewards: Sending payment via Coinos API..." );
            
            $response = wp_remote_post( 'https://coinos.io/api/payments', array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $coinos_token,
                ),
                'body' => wp_json_encode( array(
                    'payreq' => $invoice,
                ) ),
            ) );
            
            if ( is_wp_error( $response ) ) {
                throw new Exception( 'Coinos API request failed: ' . $response->get_error_message() );
            }
            
            $status_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            
            error_log( "Zap Rewards: Coinos API response code: $status_code, body: " . substr( $body, 0, 200 ) );
            
            $payment_result = json_decode( $body, true );
            
            if ( $status_code >= 400 ) {
                $error_msg = isset( $payment_result['message'] ) ? $payment_result['message'] : 'HTTP ' . $status_code;
                throw new Exception( 'Payment failed: ' . $error_msg );
            }
            
            // Check for successful payment
            // Coinos returns different fields for sent vs received payments
            if ( isset( $payment_result['id'] ) ) {
                // Payment sent successfully!
                $tx_id = $payment_result['id'];
                $payment_hash = isset( $payment_result['hash'] ) ? $payment_result['hash'] : '';
                $preimage = isset( $payment_result['preimage'] ) ? $payment_result['preimage'] : $tx_id;
                
                error_log( "Zap Rewards: ✓ Payment successful via Coinos! TX ID: " . substr( $tx_id, 0, 16 ) . '...' );
                
                $wpdb->update(
                    $wpdb->prefix . 'zap_rewards',
                    array(
                        'status' => 'completed',
                        'block_hash' => $preimage, // Store transaction ID or preimage
                    ),
                    array( 'id' => $reward_id )
                );
                
                return true;
            }
            
            throw new Exception( 'No transaction ID in Coinos response' );
            
        } catch ( Exception $e ) {
            error_log( 'Zap Rewards: Coinos payment failed: ' . $e->getMessage() );
            $this->update_reward_status( $reward_id, 'failed', 'Coinos API: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Resolve Lightning address to invoice
     * Supports: Coinos usernames, Lightning addresses (user@domain), and invoices (lnbc...)
     */
    private function resolve_lightning_address( $lightning_address, $amount_sats ) {
        // If it's already an invoice, return it
        if ( strpos( $lightning_address, 'lnbc' ) === 0 || strpos( $lightning_address, 'lntb' ) === 0 ) {
            error_log( "Zap Rewards: Already an invoice: " . substr( $lightning_address, 0, 20 ) . '...' );
            return $lightning_address;
        }

        // Check if it's a Coinos username (alphanumeric only, no special chars)
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $lightning_address ) ) {
            error_log( "Zap Rewards: Detected Coinos username: $lightning_address" );
            return $this->get_coinos_invoice( $lightning_address, $amount_sats );
        }

        // Check if it's a Lightning address (user@domain.com)
        if ( preg_match( '/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $lightning_address ) ) {
            error_log( "Zap Rewards: Detected Lightning address: $lightning_address" );
            return $this->resolve_lnurl_address( $lightning_address, $amount_sats );
        }

        error_log( "Zap Rewards: Unknown address format: $lightning_address" );
        return false;
    }

    /**
     * Get invoice from Coinos username
     */
    private function get_coinos_invoice( $username, $amount_sats ) {
        error_log( "Zap Rewards: Requesting Coinos invoice for $username ($amount_sats sats)" );
        
        $url = "https://coinos.io/api/invoice";
        
        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => json_encode( array(
                'invoice' => array(
                    'username' => $username,
                    'amount' => $amount_sats,
                ),
            ) ),
            'timeout' => 30,
        ) );
        
        if ( is_wp_error( $response ) ) {
            error_log( 'Zap Rewards: Coinos API error: ' . $response->get_error_message() );
            return false;
        }
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset( $body['text'] ) ) {
            error_log( "Zap Rewards: Got Coinos invoice: " . substr( $body['text'], 0, 20 ) . '...' );
            return $body['text'];
        }
        
        error_log( 'Zap Rewards: Failed to get Coinos invoice' );
        return false;
    }

    /**
     * Resolve Lightning address (user@domain) to invoice using LNURL
     */
    private function resolve_lnurl_address( $lightning_address, $amount_sats ) {
        error_log( "Zap Rewards: Resolving Lightning address: {$lightning_address} for {$amount_sats} sats" );
        
        // Split address
        list( $username, $domain ) = explode( '@', $lightning_address );
        
        // Step 1: Fetch LNURL endpoint
        $lnurl_url = "https://{$domain}/.well-known/lnurlp/{$username}";
        error_log( "Zap Rewards: Fetching LNURL from: {$lnurl_url}" );
        
        $response = wp_remote_get( $lnurl_url, array(
            'timeout' => 15,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );
        
        if ( is_wp_error( $response ) ) {
            error_log( "Zap Rewards: Failed to fetch LNURL: " . $response->get_error_message() );
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        
        error_log( "Zap Rewards: LNURL response code: {$status_code}, body: " . substr( $body_raw, 0, 200 ) );
        
        $body = json_decode( $body_raw, true );
        
        if ( ! isset( $body['callback'] ) ) {
            error_log( "Zap Rewards: Invalid LNURL response from {$domain} - missing callback" );
            if ( isset( $body['reason'] ) ) {
                error_log( "Zap Rewards: LNURL error reason: " . $body['reason'] );
            }
            return false;
        }
        
        // Check min/max amounts if provided
        if ( isset( $body['minSendable'] ) && isset( $body['maxSendable'] ) ) {
            $min_msats = intval( $body['minSendable'] );
            $max_msats = intval( $body['maxSendable'] );
            $amount_msats = $amount_sats * 1000;
            
            error_log( "Zap Rewards: LNURL limits - Min: {$min_msats} msat, Max: {$max_msats} msat, Requested: {$amount_msats} msat" );
            
            if ( $amount_msats < $min_msats ) {
                error_log( "Zap Rewards: Amount too small! Min: " . ($min_msats / 1000) . " sats" );
                throw new Exception( "Amount too small. Minimum: " . ($min_msats / 1000) . " sats" );
            }
            if ( $amount_msats > $max_msats ) {
                error_log( "Zap Rewards: Amount too large! Max: " . ($max_msats / 1000) . " sats" );
                throw new Exception( "Amount too large. Maximum: " . ($max_msats / 1000) . " sats" );
            }
        }
        
        // Step 2: Request invoice
        $amount_msats = $amount_sats * 1000; // Convert to millisatoshis
        $invoice_url = $body['callback'] . '?amount=' . $amount_msats;
        
        error_log( "Zap Rewards: Requesting invoice from: {$invoice_url}" );
        
        $invoice_response = wp_remote_get( $invoice_url, array(
            'timeout' => 15,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );
        
        if ( is_wp_error( $invoice_response ) ) {
            error_log( "Zap Rewards: Failed to get invoice: " . $invoice_response->get_error_message() );
            return false;
        }
        
        $invoice_status = wp_remote_retrieve_response_code( $invoice_response );
        $invoice_body_raw = wp_remote_retrieve_body( $invoice_response );
        
        error_log( "Zap Rewards: Invoice response code: {$invoice_status}, body: " . substr( $invoice_body_raw, 0, 200 ) );
        
        $invoice_body = json_decode( $invoice_body_raw, true );
        
        if ( ! isset( $invoice_body['pr'] ) ) {
            error_log( "Zap Rewards: No invoice returned from Lightning address - missing 'pr' field" );
            if ( isset( $invoice_body['reason'] ) ) {
                error_log( "Zap Rewards: Invoice error reason: " . $invoice_body['reason'] );
                throw new Exception( "Lightning address error: " . $invoice_body['reason'] );
            }
            return false;
        }
        
        error_log( "Zap Rewards: Successfully got invoice from Lightning address: " . substr( $invoice_body['pr'], 0, 30 ) . '...' );
        
        return $invoice_body['pr']; // Return the payment request (invoice)
    }

    /**
     * Update reward status with error message
     */
    private function update_reward_status( $reward_id, $status, $error_message = '' ) {
        global $wpdb;
        
        $data = array( 'status' => $status );
        
        if ( ! empty( $error_message ) ) {
            $data['error_message'] = $error_message;
        }
        
        $wpdb->update(
            $wpdb->prefix . 'zap_rewards',
            $data,
            array( 'id' => $reward_id )
        );
    }

    /**
     * Test NWC connection
     */
    public function test_connection() {
        $merchant_nwc_connection_string = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
        
        if ( empty( $merchant_nwc_connection_string ) ) {
            return array(
                'success' => false,
                'error' => 'Merchant NWC wallet not configured',
            );
        }

        try {
            // Parse the NWC connection
            $nwc_wallet = $this->get_nwc_wallet();
            $merchant_connection = $nwc_wallet->parse_nwc_url( $merchant_nwc_connection_string );
            
            if ( is_wp_error( $merchant_connection ) ) {
                return array(
                    'success' => false,
                    'error' => 'Failed to parse NWC connection: ' . $merchant_connection->get_error_message(),
                );
            }
            
            // Try to get wallet balance via NWC
            $nwc_client = $this->get_nwc_client();
            $balance_result = $nwc_client->get_balance( $merchant_connection );
            
            if ( is_wp_error( $balance_result ) ) {
                return array(
                    'success' => false,
                    'error' => 'NWC communication failed: ' . $balance_result->get_error_message(),
                );
            }
            
            return array(
                'success' => true,
                'balance' => isset( $balance_result['balance'] ) ? intval( $balance_result['balance'] / 1000 ) : 0, // msats to sats
                'currency' => 'sats',
                'relay' => $merchant_connection['relay'],
            );
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Retry a failed payment
     */
    public function retry_payment( $reward_id ) {
        global $wpdb;
        
        error_log( "Zap Rewards Processor: retry_payment called for ID: $reward_id" );
        
        $reward = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}zap_rewards WHERE id = %d",
            $reward_id
        ) );

        if ( ! $reward ) {
            error_log( "Zap Rewards Processor: Reward ID $reward_id not found" );
            return array(
                'success' => false,
                'error' => 'Reward not found',
            );
        }

        error_log( "Zap Rewards Processor: Found reward - Status: {$reward->status}, Address: {$reward->zap_address}, Amount: {$reward->amount}" );

        if ( $reward->status === 'completed' ) {
            error_log( "Zap Rewards Processor: Payment already completed" );
            return array(
                'success' => false,
                'error' => 'Payment already completed',
            );
        }

        error_log( "Zap Rewards Processor: Attempting to send payment..." );
        $result = $this->send_payment( $reward_id, $reward->zap_address, $reward->amount );
        
        error_log( "Zap Rewards Processor: Payment result: " . ( $result ? 'SUCCESS' : 'FAILED' ) );
        
        return array(
            'success' => $result,
            'error' => $result ? null : 'Payment failed',
        );
    }

    /**
     * Get current Bitcoin price and calculate sats per currency
     */
    public function get_sats_per_currency( $currency = 'USD' ) {
        // Check cache first (5 minute cache)
        $cache_key = 'zap_rewards_btc_rate_' . strtolower( $currency );
        $cached_rate = get_transient( $cache_key );
        
        if ( $cached_rate !== false ) {
            return $cached_rate;
        }
        
        try {
            // Use a public API to get BTC price
            $response = wp_remote_get( 'https://blockchain.info/ticker', array( 'timeout' => 10 ) );
            
            if ( ! is_wp_error( $response ) ) {
                $rates = json_decode( wp_remote_retrieve_body( $response ), true );
                
                if ( isset( $rates[ $currency ]['last'] ) ) {
                    $btc_price = floatval( $rates[ $currency ]['last'] );
                    $sats_per_currency = round( 100000000 / $btc_price );
                    
                    error_log( "Zap Rewards: Fetched live rate: 1 {$currency} = $sats_per_currency sats (BTC = \${$btc_price})" );
                    
                    // Cache for 5 minutes
                    set_transient( $cache_key, $sats_per_currency, 300 );
                    
                    return $sats_per_currency;
                }
            }
        } catch ( Exception $e ) {
            error_log( "Zap Rewards: Exception fetching BTC rate: " . $e->getMessage() );
        }
        
        // Fallback rate
        error_log( "Zap Rewards: Using fallback rate of 1000 sats/USD" );
        return 1000;
    }
}

