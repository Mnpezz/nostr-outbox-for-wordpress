<?php
/**
 * PHP NWC Client for Backend Invoice Verification
 * 
 * This implements a simple NWC client that can verify invoice payments
 * using the lookup_invoice command via Nostr relay communication.
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nostr_Login_Pay_NWC_PHP_Client {

    /**
     * Parse NWC connection string
     *
     * @param string $nwc_string NWC connection string
     * @return array|WP_Error Parsed connection data or error
     */
    public function parse_nwc_string( $nwc_string ) {
        // Format: nostr+walletconnect://pubkey?relay=wss://...&secret=...
        if ( strpos( $nwc_string, 'nostr+walletconnect://' ) !== 0 ) {
            return new WP_Error( 'invalid_format', 'Invalid NWC connection string format' );
        }

        // Remove protocol
        $url_str = str_replace( 'nostr+walletconnect://', 'http://', $nwc_string );
        $parsed = parse_url( $url_str );

        if ( ! $parsed || ! isset( $parsed['host'] ) ) {
            return new WP_Error( 'parse_failed', 'Failed to parse NWC connection string' );
        }

        parse_str( isset( $parsed['query'] ) ? $parsed['query'] : '', $params );

        if ( empty( $params['relay'] ) || empty( $params['secret'] ) ) {
            return new WP_Error( 'missing_params', 'Missing required parameters (relay or secret)' );
        }

        return array(
            'pubkey' => $parsed['host'],
            'relay'  => $params['relay'],
            'secret' => $params['secret'],
            'lud16'  => isset( $params['lud16'] ) ? $params['lud16'] : '',
        );
    }

    /**
     * Lookup invoice status via NWC
     *
     * @param string $nwc_string  NWC connection string
     * @param string $invoice     BOLT11 invoice string
     * @param string $payment_hash Payment hash (optional)
     * @return bool|WP_Error True if paid, false if not paid, WP_Error on error
     */
    public function lookup_invoice( $nwc_string, $invoice, $payment_hash = '' ) {
        error_log( '=== NWC PHP Client: lookup_invoice ===' );
        
        // Parse NWC connection
        $connection = $this->parse_nwc_string( $nwc_string );
        if ( is_wp_error( $connection ) ) {
            error_log( 'NWC parse error: ' . $connection->get_error_message() );
            return $connection;
        }

        error_log( 'NWC connection parsed: relay=' . $connection['relay'] );

        // For now, return false to use the Coinos API fallback
        // Full NWC protocol implementation requires Nostr event signing and relay communication
        // which is complex to implement in pure PHP without external libraries
        
        error_log( 'NWC lookup_invoice: Full Nostr protocol not yet implemented in PHP' );
        error_log( 'Recommendation: Use webhooks or manual verification' );
        
        return new WP_Error(
            'not_implemented',
            'NWC lookup_invoice requires Nostr protocol libraries. Please use webhooks or manual verification for now.'
        );
    }

    /**
     * Alternative: Check invoice via Lightning Address provider API
     *
     * This is a simpler approach that queries the Lightning Address provider
     * directly if they expose an API
     *
     * @param string $lightning_address Lightning address (e.g., user@coinos.io)
     * @param string $invoice BOLT11 invoice
     * @return bool|WP_Error True if paid, false if not, WP_Error on error
     */
    public function check_via_lightning_address_api( $lightning_address, $invoice ) {
        // Extract domain from Lightning Address
        $parts = explode( '@', $lightning_address );
        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'invalid_address', 'Invalid Lightning Address format' );
        }

        list( $username, $domain ) = $parts;

        // Coinos-specific check
        if ( $domain === 'coinos.io' ) {
            return $this->check_coinos_invoice_v2( $username, $invoice );
        }

        return new WP_Error( 'unsupported_provider', 'Invoice checking not supported for this Lightning Address provider' );
    }

    /**
     * Check Coinos invoice status (alternative method)
     *
     * @param string $username Coinos username
     * @param string $invoice  BOLT11 invoice
     * @return bool|WP_Error True if paid, false if not
     */
    private function check_coinos_invoice_v2( $username, $invoice ) {
        error_log( 'Checking Coinos invoice for user: ' . $username );

        // Try the invoice decode endpoint
        $api_url = 'https://coinos.io/api/decode';
        
        $response = wp_remote_post( $api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'text' => $invoice,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Coinos API error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        
        error_log( 'Coinos decode API response code: ' . $status_code );
        error_log( 'Coinos decode API response: ' . $body );

        if ( $status_code === 200 ) {
            $data = json_decode( $body, true );
            
            // Check if invoice has been paid
            if ( isset( $data['paid'] ) && $data['paid'] === true ) {
                error_log( '✓ Invoice is PAID!' );
                return true;
            }

            if ( isset( $data['status'] ) && in_array( $data['status'], array( 'paid', 'settled', 'confirmed' ) ) ) {
                error_log( '✓ Invoice is PAID (status: ' . $data['status'] . ')' );
                return true;
            }
        }

        error_log( 'Invoice not paid or API check inconclusive' );
        return false;
    }
}

