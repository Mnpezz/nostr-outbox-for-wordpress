<?php
/**
 * LNURL Service for Lightning Address Invoice Generation
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles LNURL-pay protocol for Lightning Address invoices
 */
class Nostr_Login_Pay_LNURL_Service {

    /**
     * Generate invoice from Lightning Address
     *
     * @param string $lightning_address Lightning address (e.g., user@coinos.io)
     * @param int    $amount_msats      Amount in millisatoshis
     * @param string $comment           Optional comment/description
     * @return array|WP_Error Invoice data or error
     */
    public function create_invoice_from_address( $lightning_address, $amount_msats, $comment = '' ) {
        // Validate Lightning address
        if ( ! $this->is_valid_lightning_address( $lightning_address ) ) {
            return new WP_Error( 'invalid_address', __( 'Invalid Lightning address format', 'nostr-outbox-wordpress' ) );
        }

        // Step 1: Parse Lightning address to get LNURL endpoint
        list( $username, $domain ) = explode( '@', $lightning_address );
        $lnurl_endpoint = "https://{$domain}/.well-known/lnurlp/{$username}";

        // Step 2: Fetch LNURL-pay details
        $lnurl_data = $this->fetch_lnurl_details( $lnurl_endpoint );
        
        if ( is_wp_error( $lnurl_data ) ) {
            return $lnurl_data;
        }

        // Step 3: Validate amount is within limits
        $min_sendable = isset( $lnurl_data['minSendable'] ) ? intval( $lnurl_data['minSendable'] ) : 1000;
        $max_sendable = isset( $lnurl_data['maxSendable'] ) ? intval( $lnurl_data['maxSendable'] ) : 100000000000;

        if ( $amount_msats < $min_sendable || $amount_msats > $max_sendable ) {
            return new WP_Error(
                'amount_out_of_range',
                sprintf(
                    __( 'Amount must be between %d and %d sats', 'nostr-outbox-wordpress' ),
                    $min_sendable / 1000,
                    $max_sendable / 1000
                )
            );
        }

        // Step 4: Request invoice from callback URL
        if ( empty( $lnurl_data['callback'] ) ) {
            return new WP_Error( 'no_callback', __( 'No callback URL provided by Lightning address', 'nostr-outbox-wordpress' ) );
        }

        $invoice = $this->request_invoice( $lnurl_data['callback'], $amount_msats, $comment );
        
        return $invoice;
    }

    /**
     * Validate Lightning address format
     *
     * @param string $address Lightning address
     * @return bool
     */
    private function is_valid_lightning_address( $address ) {
        // Must be in format: username@domain.com
        return (bool) preg_match( '/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $address );
    }

    /**
     * Fetch LNURL-pay details from Lightning address
     *
     * @param string $lnurl_endpoint LNURL endpoint URL
     * @return array|WP_Error LNURL data or error
     */
    private function fetch_lnurl_details( $lnurl_endpoint ) {
        $response = wp_remote_get( $lnurl_endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'lnurl_fetch_failed',
                __( 'Failed to fetch Lightning address details: ', 'nostr-outbox-wordpress' ) . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return new WP_Error(
                'lnurl_error',
                sprintf( __( 'Lightning address returned error: HTTP %d', 'nostr-outbox-wordpress' ), $status_code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! $data || ! isset( $data['callback'] ) ) {
            return new WP_Error(
                'invalid_lnurl_response',
                __( 'Invalid response from Lightning address', 'nostr-outbox-wordpress' )
            );
        }

        return $data;
    }

    /**
     * Request invoice from LNURL callback
     *
     * @param string $callback_url Callback URL
     * @param int    $amount_msats Amount in millisatoshis
     * @param string $comment      Optional comment
     * @return array|WP_Error Invoice data or error
     */
    private function request_invoice( $callback_url, $amount_msats, $comment = '' ) {
        // Build callback URL with parameters
        $url = add_query_arg( array(
            'amount' => $amount_msats,
        ), $callback_url );

        if ( ! empty( $comment ) ) {
            $url = add_query_arg( 'comment', urlencode( $comment ), $url );
        }

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'invoice_request_failed',
                __( 'Failed to request invoice: ', 'nostr-outbox-wordpress' ) . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return new WP_Error(
                'invoice_error',
                sprintf( __( 'Invoice request failed: HTTP %d', 'nostr-outbox-wordpress' ), $status_code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! $data || ! isset( $data['pr'] ) ) {
            return new WP_Error(
                'invalid_invoice_response',
                __( 'Invalid invoice response', 'nostr-outbox-wordpress' )
            );
        }

        // Extract payment hash from invoice if provided
        $payment_hash = isset( $data['paymentHash'] ) ? $data['paymentHash'] : $this->extract_payment_hash_from_invoice( $data['pr'] );

        return array(
            'invoice' => $data['pr'],
            'payment_hash' => $payment_hash,
            'description' => isset( $data['successAction']['message'] ) ? $data['successAction']['message'] : '',
        );
    }

    /**
     * Extract payment hash from BOLT11 invoice
     *
     * @param string $invoice BOLT11 invoice string
     * @return string Payment hash (or empty string if not found)
     */
    private function extract_payment_hash_from_invoice( $invoice ) {
        // This is a simplified extraction - in production you'd use a proper BOLT11 decoder
        // For now, return empty string as we'll rely on the LNURL response providing it
        return '';
    }

    /**
     * Check if an invoice has been paid
     *
     * @param string $lightning_address Lightning address (not used, kept for compatibility)
     * @param string $invoice           BOLT11 invoice string
     * @param string $payment_hash      Payment hash (optional)
     * @return bool|WP_Error True if paid, false if not paid, WP_Error on error
     */
    public function check_invoice_paid( $lightning_address, $invoice, $payment_hash = '' ) {
        // The best way to check invoice status is via NWC lookup_invoice
        // Check if merchant has NWC configured
        $merchant_nwc = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
        
        if ( ! empty( $merchant_nwc ) && ! empty( $payment_hash ) ) {
            return $this->check_via_nwc( $merchant_nwc, $payment_hash, $invoice );
        }

        // Fallback: Try Coinos-specific checking
        if ( strpos( $lightning_address, '@coinos.io' ) !== false ) {
            return $this->check_coinos_invoice( $invoice );
        }

        // No way to verify - manual verification required
        error_log( 'Payment verification: No NWC configured, cannot auto-verify invoice' );
        return false;
    }

    /**
     * Check invoice via NWC lookup_invoice command
     *
     * @param string $nwc_connection NWC connection string
     * @param string $payment_hash   Payment hash
     * @param string $invoice        BOLT11 invoice
     * @return bool True if paid, false otherwise
     */
    private function check_via_nwc( $nwc_connection, $payment_hash, $invoice ) {
        error_log( 'Checking invoice via NWC lookup_invoice...' );
        
        // Parse NWC connection
        $nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
        $parsed = $nwc_wallet->parse_nwc_url( $nwc_connection );
        
        if ( is_wp_error( $parsed ) ) {
            error_log( 'NWC parse error: ' . $parsed->get_error_message() );
            return false;
        }

        // TODO: Implement actual NWC lookup_invoice command
        // For now, this would require Nostr protocol implementation
        // which needs secp256k1 cryptography libraries
        
        error_log( 'NWC lookup not yet fully implemented - requires Nostr protocol libraries' );
        return false;
    }

    /**
     * Check Coinos invoice status
     *
     * @param string $invoice BOLT11 invoice string
     * @return bool|WP_Error True if paid, false if unpaid, WP_Error on error
     */
    private function check_coinos_invoice( $invoice ) {
        error_log( 'Checking Coinos invoice: ' . substr( $invoice, 0, 50 ) . '...' );
        
        // Method 1: Try to decode invoice first to get payment hash
        $decode_url = 'https://coinos.io/api/bitcoin/decode';
        
        $decode_response = wp_remote_post( $decode_url, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'text' => $invoice,
            ) ),
        ) );
        
        if ( ! is_wp_error( $decode_response ) ) {
            $decode_status = wp_remote_retrieve_response_code( $decode_response );
            $decode_body = wp_remote_retrieve_body( $decode_response );
            error_log( 'Coinos decode response: ' . $decode_status . ' - ' . substr( $decode_body, 0, 200 ) );
            
            if ( $decode_status === 200 ) {
                $decode_data = json_decode( $decode_body, true );
                
                // If we got the payment hash, try to check its status
                if ( isset( $decode_data['payment_hash'] ) ) {
                    $payment_hash = $decode_data['payment_hash'];
                    error_log( 'Got payment hash from decode: ' . $payment_hash );
                    
                    // Try to check payment status by hash
                    $check_url = 'https://coinos.io/api/bitcoin/' . $payment_hash;
                    $check_response = wp_remote_get( $check_url, array(
                        'timeout' => 10,
                        'headers' => array( 'Accept' => 'application/json' ),
                    ) );
                    
                    if ( ! is_wp_error( $check_response ) ) {
                        $check_status = wp_remote_retrieve_response_code( $check_response );
                        $check_body = wp_remote_retrieve_body( $check_response );
                        error_log( 'Payment hash check response: ' . $check_status . ' - ' . substr( $check_body, 0, 200 ) );
                        
                        if ( $check_status === 200 ) {
                            $payment_data = json_decode( $check_body, true );
                            
                            // Check if paid
                            if ( isset( $payment_data['confirmed'] ) && $payment_data['confirmed'] ) {
                                error_log( 'Invoice is PAID! (confirmed via payment hash)' );
                                return true;
                            }
                            if ( isset( $payment_data['received'] ) && $payment_data['received'] > 0 ) {
                                error_log( 'Invoice is PAID! (received: ' . $payment_data['received'] . ')' );
                                return true;
                            }
                        }
                    }
                }
                
                // Also check if the decode response itself indicates payment
                if ( isset( $decode_data['confirmed'] ) && $decode_data['confirmed'] ) {
                    error_log( 'Invoice is PAID! (confirmed in decode response)' );
                    return true;
                }
                if ( isset( $decode_data['received'] ) && $decode_data['received'] > 0 ) {
                    error_log( 'Invoice is PAID! (received in decode: ' . $decode_data['received'] . ')' );
                    return true;
                }
            }
        }
        
        // Method 2: Try the invoice endpoint with GET
        $invoice_get_url = 'https://coinos.io/api/lightning/invoice?text=' . urlencode( $invoice );
        $get_response = wp_remote_get( $invoice_get_url, array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );
        
        if ( ! is_wp_error( $get_response ) ) {
            $get_status = wp_remote_retrieve_response_code( $get_response );
            $get_body = wp_remote_retrieve_body( $get_response );
            error_log( 'Coinos GET invoice response: ' . $get_status . ' - ' . substr( $get_body, 0, 200 ) );
            
            if ( $get_status === 200 ) {
                $get_data = json_decode( $get_body, true );
                
                if ( isset( $get_data['paid'] ) && $get_data['paid'] ) {
                    error_log( 'Invoice is PAID! (paid field)' );
                    return true;
                }
                if ( isset( $get_data['confirmed'] ) && $get_data['confirmed'] ) {
                    error_log( 'Invoice is PAID! (confirmed field)' );
                    return true;
                }
                if ( isset( $get_data['received'] ) && $get_data['received'] > 0 ) {
                    error_log( 'Invoice is PAID! (received: ' . $get_data['received'] . ')' );
                    return true;
                }
            }
        }

        error_log( 'Invoice NOT paid yet (all methods tried)' );
        return false;
    }
}

