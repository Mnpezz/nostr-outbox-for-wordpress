<?php
/**
 * NWC (Nostr Wallet Connect) Wallet Handler
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles NWC wallet connections and operations
 */
class Nostr_Login_Pay_NWC_Wallet {

    /**
     * Parse NWC connection string
     *
     * @param string $nwc_url The NWC connection URL (nostr+walletconnect://...)
     * @return array|WP_Error Parsed connection data or error
     */
    public function parse_nwc_url( $nwc_url ) {
        // NWC URL format: nostr+walletconnect://pubkey?relay=relay_url&secret=secret
        if ( ! preg_match( '/^nostr\+walletconnect:\/\/([a-f0-9]{64})\?(.+)$/', $nwc_url, $matches ) ) {
            return new WP_Error( 'invalid_nwc_url', __( 'Invalid NWC connection URL', 'nostr-login-pay' ) );
        }

        $pubkey = $matches[1];
        parse_str( $matches[2], $params );

        if ( empty( $params['relay'] ) || empty( $params['secret'] ) ) {
            return new WP_Error( 'invalid_nwc_params', __( 'Missing required NWC parameters', 'nostr-login-pay' ) );
        }

        return array(
            'pubkey' => $pubkey,
            'relay' => $params['relay'],
            'secret' => $params['secret'],
        );
    }

    /**
     * Save NWC connection for a user
     *
     * @param int    $user_id User ID
     * @param string $nwc_url NWC connection URL
     * @return array|WP_Error Connection info or error
     */
    public function save_user_connection( $user_id, $nwc_url ) {
        $parsed = $this->parse_nwc_url( $nwc_url );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        // Encrypt the secret before storing
        $encrypted_secret = $this->encrypt_secret( $parsed['secret'] );

        // Store connection data
        update_user_meta( $user_id, 'nwc_wallet_pubkey', $parsed['pubkey'] );
        update_user_meta( $user_id, 'nwc_wallet_relay', $parsed['relay'] );
        update_user_meta( $user_id, 'nwc_wallet_secret', $encrypted_secret );
        update_user_meta( $user_id, 'nwc_wallet_connected_at', time() );

        // Try to get wallet info
        $wallet_info = $this->get_wallet_info( $user_id );

        return array(
            'pubkey' => $parsed['pubkey'],
            'relay' => $parsed['relay'],
            'connected' => true,
            'balance' => isset( $wallet_info['balance'] ) ? $wallet_info['balance'] : 0,
        );
    }

    /**
     * Get NWC connection for a user
     *
     * @param int $user_id User ID
     * @return array|false Connection data or false if not connected
     */
    public function get_user_connection( $user_id ) {
        $pubkey = get_user_meta( $user_id, 'nwc_wallet_pubkey', true );
        $relay = get_user_meta( $user_id, 'nwc_wallet_relay', true );
        $encrypted_secret = get_user_meta( $user_id, 'nwc_wallet_secret', true );

        if ( empty( $pubkey ) || empty( $relay ) || empty( $encrypted_secret ) ) {
            return false;
        }

        $secret = $this->decrypt_secret( $encrypted_secret );

        return array(
            'pubkey' => $pubkey,
            'relay' => $relay,
            'secret' => $secret,
        );
    }

    /**
     * Get user's NWC connection as a full connection string
     *
     * @param int $user_id User ID
     * @return string|false Full NWC connection string or false if not connected
     */
    public function get_user_connection_string( $user_id ) {
        $connection = $this->get_user_connection( $user_id );

        if ( ! $connection ) {
            return false;
        }

        // Reconstruct the nostr+walletconnect:// URL
        $connection_string = sprintf(
            'nostr+walletconnect://%s?relay=%s&secret=%s',
            $connection['pubkey'],
            urlencode( $connection['relay'] ),
            $connection['secret']
        );

        return $connection_string;
    }

    /**
     * Disconnect NWC wallet for a user
     *
     * @param int $user_id User ID
     * @return bool Success status
     */
    public function disconnect_user_wallet( $user_id ) {
        delete_user_meta( $user_id, 'nwc_wallet_pubkey' );
        delete_user_meta( $user_id, 'nwc_wallet_relay' );
        delete_user_meta( $user_id, 'nwc_wallet_secret' );
        delete_user_meta( $user_id, 'nwc_wallet_connected_at' );
        delete_user_meta( $user_id, 'nwc_wallet_info' );

        return true;
    }

    /**
     * Get wallet info (balance, etc.)
     *
     * @param int $user_id User ID
     * @return array|WP_Error Wallet info or error
     */
    public function get_wallet_info( $user_id ) {
        $connection = $this->get_user_connection( $user_id );

        if ( ! $connection ) {
            return new WP_Error( 'no_connection', __( 'No NWC wallet connected', 'nostr-login-pay' ) );
        }

        // In a real implementation, you would call the NWC API here
        // For now, return cached data if available
        $cached_info = get_user_meta( $user_id, 'nwc_wallet_info', true );

        if ( ! empty( $cached_info ) && is_array( $cached_info ) ) {
            return $cached_info;
        }

        // Default return
        return array(
            'balance' => 0,
            'currency' => 'sats',
            'connected' => true,
        );
    }

    /**
     * Create an invoice using NWC
     *
     * @param int   $user_id User ID
     * @param int   $amount Amount in satoshis
     * @param string $description Invoice description
     * @return array|WP_Error Invoice data or error
     */
    public function make_invoice( $user_id, $amount, $description = '' ) {
        $connection = $this->get_user_connection( $user_id );

        if ( ! $connection ) {
            return new WP_Error( 'no_connection', __( 'No NWC wallet connected', 'nostr-login-pay' ) );
        }

        // In a real implementation, you would:
        // 1. Create a Nostr event with kind 23194 (NWC request)
        // 2. Include method: "make_invoice" and params: { "amount": amount, "description": description }
        // 3. Sign the event with the user's secret
        // 4. Send to the relay
        // 5. Listen for the response event

        // For now, return a placeholder
        return array(
            'invoice' => 'lnbc' . $amount . 'n...', // Placeholder BOLT11 invoice
            'payment_hash' => bin2hex( random_bytes( 32 ) ),
            'amount' => $amount,
            'description' => $description,
        );
    }

    /**
     * Pay an invoice using NWC
     *
     * @param int    $user_id User ID
     * @param string $invoice BOLT11 invoice string
     * @return array|WP_Error Payment result or error
     */
    public function pay_invoice( $user_id, $invoice ) {
        $connection = $this->get_user_connection( $user_id );

        if ( ! $connection ) {
            return new WP_Error( 'no_connection', __( 'No NWC wallet connected', 'nostr-login-pay' ) );
        }

        // In a real implementation, you would:
        // 1. Create a Nostr event with kind 23194 (NWC request)
        // 2. Include method: "pay_invoice" and params: { "invoice": invoice }
        // 3. Sign the event with the user's secret
        // 4. Send to the relay
        // 5. Listen for the response event

        // For now, return a placeholder
        return array(
            'preimage' => bin2hex( random_bytes( 32 ) ),
            'paid' => true,
        );
    }

    /**
     * Get wallet balance
     *
     * @param int $user_id User ID
     * @return int|WP_Error Balance in satoshis or error
     */
    public function get_balance( $user_id ) {
        $info = $this->get_wallet_info( $user_id );

        if ( is_wp_error( $info ) ) {
            return $info;
        }

        return isset( $info['balance'] ) ? intval( $info['balance'] ) : 0;
    }

    /**
     * Encrypt a secret for storage
     *
     * @param string $secret The secret to encrypt
     * @return string Encrypted secret
     */
    private function encrypt_secret( $secret ) {
        // Use WordPress salts for encryption
        $key = wp_salt( 'auth' );
        
        // Simple encryption using openssl
        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv = openssl_random_pseudo_bytes( 16 );
            $encrypted = openssl_encrypt( $secret, 'AES-256-CBC', $key, 0, $iv );
            return base64_encode( $iv . '::' . $encrypted );
        }

        // Fallback to base64 encoding (not secure, but better than nothing)
        return base64_encode( $secret );
    }

    /**
     * Decrypt a secret from storage
     *
     * @param string $encrypted The encrypted secret
     * @return string Decrypted secret
     */
    private function decrypt_secret( $encrypted ) {
        $key = wp_salt( 'auth' );

        if ( function_exists( 'openssl_decrypt' ) && strpos( $encrypted, '::' ) !== false ) {
            $decoded = base64_decode( $encrypted );
            list( $iv, $encrypted_data ) = explode( '::', $decoded, 2 );
            return openssl_decrypt( $encrypted_data, 'AES-256-CBC', $key, 0, $iv );
        }

        // Fallback
        return base64_decode( $encrypted );
    }
}

