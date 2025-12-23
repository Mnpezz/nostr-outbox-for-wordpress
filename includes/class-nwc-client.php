<?php
/**
 * NWC (Nostr Wallet Connect) Protocol Client
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles NWC protocol communication via Nostr
 */
class Nostr_Login_Pay_NWC_Client {

    /**
     * Make an NWC request
     *
     * @param array  $connection NWC connection data (pubkey, relay, secret)
     * @param string $method     NWC method (make_invoice, pay_invoice, get_balance, etc.)
     * @param array  $params     Method parameters
     * @return array|WP_Error Response data or error
     */
    public function make_request( $connection, $method, $params = array() ) {
        error_log( "NWC Client: make_request called - method: $method, params: " . wp_json_encode( $params ) );
        
        // Validate connection
        if ( empty( $connection['pubkey'] ) || empty( $connection['relay'] ) || empty( $connection['secret'] ) ) {
            return new WP_Error( 'invalid_connection', 'Invalid NWC connection data' );
        }

        error_log( "NWC Client: Connection valid - relay: " . $connection['relay'] . ", wallet pubkey: " . $connection['pubkey'] );

        // Create NWC request event
        $event = $this->create_nwc_event( $connection, $method, $params );
        
        if ( is_wp_error( $event ) ) {
            error_log( "NWC Client: Failed to create event: " . $event->get_error_message() );
            return $event;
        }

        error_log( "NWC Client: Event created successfully, sending to relay..." );

        // Send to relay and wait for response
        $response = $this->send_to_relay( $connection['relay'], $event, $connection['pubkey'] );
        
        if ( is_wp_error( $response ) ) {
            error_log( "NWC Client: Relay communication failed: " . $response->get_error_message() );
            return $response;
        }

        error_log( "NWC Client: Got response from relay, parsing..." );

        // Parse and decrypt response
        return $this->parse_nwc_response( $response, $connection );
    }

    /**
     * Create an invoice
     *
     * @param array  $connection NWC connection data
     * @param int    $amount     Amount in satoshis
     * @param string $description Invoice description
     * @return array|WP_Error Invoice data or error
     */
    public function make_invoice( $connection, $amount, $description = '' ) {
        return $this->make_request( $connection, 'make_invoice', array(
            'amount' => $amount * 1000, // Convert to millisatoshis
            'description' => $description,
        ) );
    }

    /**
     * Check invoice status
     *
     * @param array  $connection   NWC connection data
     * @param string $payment_hash Payment hash to check
     * @return array|WP_Error Status data or error
     */
    public function lookup_invoice( $connection, $payment_hash ) {
        return $this->make_request( $connection, 'lookup_invoice', array(
            'payment_hash' => $payment_hash,
        ) );
    }

    /**
     * Pay a Lightning invoice
     *
     * @param array  $connection NWC connection data
     * @param string $invoice    Lightning invoice (payment request)
     * @return array|WP_Error Payment result or error
     */
    public function pay_invoice( $connection, $invoice ) {
        return $this->make_request( $connection, 'pay_invoice', array(
            'invoice' => $invoice,
        ) );
    }

    /**
     * Get wallet balance
     *
     * @param array $connection NWC connection data
     * @return array|WP_Error Balance data or error
     */
    public function get_balance( $connection ) {
        return $this->make_request( $connection, 'get_balance', array() );
    }

    /**
     * Create a Nostr event for NWC request
     *
     * @param array  $connection NWC connection data
     * @param string $method     NWC method
     * @param array  $params     Method parameters
     * @return array|WP_Error Event data or error
     */
    private function create_nwc_event( $connection, $method, $params ) {
        if ( ! class_exists( 'Nostr_Login_Pay_Crypto_PHP' ) ) {
            return new WP_Error( 'crypto_not_loaded', 'Crypto library not loaded' );
        }

        // NWC uses kind 23194 for requests
        $content = wp_json_encode( array(
            'method' => $method,
            'params' => $params,
        ) );

        error_log( "NWC Client: Encrypting content: $content" );

        // Encrypt the content using NIP-04
        $encrypted_content = Nostr_Login_Pay_Crypto_PHP::nip04_encrypt(
            $content,
            $connection['pubkey'],
            $connection['secret']
        );
        
        if ( ! $encrypted_content ) {
            return new WP_Error( 'encryption_failed', 'Failed to encrypt NWC request' );
        }

        error_log( "NWC Client: Encrypted content: " . substr( $encrypted_content, 0, 50 ) . '...' );

        // Get pubkey from secret
        $pubkey = Nostr_Login_Pay_Crypto_PHP::get_public_key( $connection['secret'] );
        if ( ! $pubkey ) {
            return new WP_Error( 'pubkey_derivation_failed', 'Failed to derive public key from secret' );
        }

        $event = array(
            'kind' => 23194,
            'created_at' => time(),
            'pubkey' => $pubkey,
            'tags' => array(
                array( 'p', $connection['pubkey'] ),
            ),
            'content' => $encrypted_content,
        );

        // Sign the event
        $signed_event = Nostr_Login_Pay_Crypto_PHP::sign_event( $event, $connection['secret'] );
        
        if ( ! $signed_event ) {
            return new WP_Error( 'signing_failed', 'Failed to sign NWC request event' );
        }

        error_log( "NWC Client: Signed event ID: " . $signed_event['id'] );
        
        return $signed_event;
    }

    /**
     * Send event to Nostr relay and wait for response
     *
     * @param string $relay_url Relay URL
     * @param array  $event     Signed event
     * @param string $pubkey    Wallet pubkey to listen for responses from
     * @return array|WP_Error Response event or error
     */
    private function send_to_relay( $relay_url, $event, $pubkey ) {
        if ( ! class_exists( 'WebSocket\Client' ) ) {
            error_log( 'NWC Client: WebSocket library not found. Using HTTP fallback.' );
            return $this->send_via_http_fallback( $relay_url, $event, $pubkey );
        }

        try {
            error_log( "NWC Client: Connecting to relay: $relay_url" );
            
            $client = new \WebSocket\Client( $relay_url, array(
                'timeout' => 30,
            ) );

            // Generate subscription ID
            $sub_id = bin2hex( random_bytes( 8 ) );
            $our_pubkey = $event['pubkey'];

            // Subscribe to responses from the wallet
            $subscription = array(
                'REQ',
                $sub_id,
                array(
                    'kinds' => array( 23195 ), // NWC response kind
                    'authors' => array( $pubkey ),
                    '#p' => array( $our_pubkey ),
                    'since' => time() - 5,
                ),
            );
            
            error_log( "NWC Client: Subscribing: " . wp_json_encode( $subscription ) );
            $client->send( wp_json_encode( $subscription ) );

            // Send the event
            $event_message = array( 'EVENT', $event );
            error_log( "NWC Client: Sending event: " . wp_json_encode( $event_message ) );
            $client->send( wp_json_encode( $event_message ) );

            // Wait for response (max 30 seconds)
            $start_time = time();
            $response_event = null;

            while ( time() - $start_time < 30 ) {
                try {
                    $message = $client->receive();
                    error_log( "NWC Client: Received message: " . $message );
                    
                    $data = json_decode( $message, true );
                    if ( ! $data || ! is_array( $data ) ) {
                        continue;
                    }

                    // Check for EVENT message
                    if ( $data[0] === 'EVENT' && $data[1] === $sub_id && isset( $data[2] ) ) {
                        $response_event = $data[2];
                        error_log( "NWC Client: Got response event!" );
                        break;
                    }

                    // Check for OK or NOTICE messages
                    if ( $data[0] === 'OK' ) {
                        error_log( "NWC Client: Event accepted: " . wp_json_encode( $data ) );
                    } elseif ( $data[0] === 'NOTICE' ) {
                        error_log( "NWC Client: Relay notice: " . $data[1] );
                    }
                } catch ( \WebSocket\ConnectionException $e ) {
                    // Timeout, continue waiting
                    usleep( 100000 ); // 100ms
                }
            }

            // Close subscription and connection
            $close_message = array( 'CLOSE', $sub_id );
            $client->send( wp_json_encode( $close_message ) );
            $client->close();

            if ( ! $response_event ) {
                return new WP_Error( 'timeout', 'No response from wallet relay' );
            }

            return $response_event;

        } catch ( Exception $e ) {
            error_log( 'NWC Client: WebSocket error: ' . $e->getMessage() );
            return new WP_Error( 'websocket_error', $e->getMessage() );
        }
    }

    /**
     * HTTP fallback for relays that support HTTP
     *
     * @param string $relay_url Relay URL
     * @param array  $event     Signed event
     * @param string $pubkey    Wallet pubkey
     * @return array|WP_Error Response event or error
     */
    private function send_via_http_fallback( $relay_url, $event, $pubkey ) {
        // Some relays support HTTP POST, try that
        // This is a simplified version, real implementation would need to poll for responses
        return new WP_Error(
            'websocket_required',
            'WebSocket library required for NWC. Install via: composer require textalk/websocket'
        );
    }

    /**
     * Parse NWC response event
     *
     * @param array $response_event Response event from relay
     * @param array $connection NWC connection data
     * @return array|WP_Error Parsed response or error
     */
    private function parse_nwc_response( $response_event, $connection ) {
        if ( empty( $response_event['content'] ) ) {
            return new WP_Error( 'empty_response', 'Empty response from wallet' );
        }

        if ( ! class_exists( 'Nostr_Login_Pay_Crypto_PHP' ) ) {
            return new WP_Error( 'crypto_not_loaded', 'Crypto library not loaded' );
        }

        error_log( "NWC Client: Parsing response event..." );
        error_log( "NWC Client: Response content: " . substr( $response_event['content'], 0, 100 ) . '...' );

        // Decrypt the response content using NIP-04
        $decrypted_content = Nostr_Login_Pay_Crypto_PHP::nip04_decrypt(
            $response_event['content'],
            $response_event['pubkey'], // Sender is the wallet
            $connection['secret']       // Our secret key
        );
        
        if ( ! $decrypted_content ) {
            error_log( "NWC Client: Decryption failed!" );
            return new WP_Error( 'decryption_failed', 'Failed to decrypt NWC response' );
        }

        error_log( "NWC Client: Decrypted content: $decrypted_content" );

        // Parse JSON response
        $response_data = json_decode( $decrypted_content, true );
        
        if ( ! $response_data ) {
            return new WP_Error(
                'invalid_response',
                'Failed to parse NWC response JSON: ' . json_last_error_msg()
            );
        }

        // Check for errors in response
        if ( isset( $response_data['error'] ) ) {
            $error_msg = isset( $response_data['error']['message'] ) 
                ? $response_data['error']['message'] 
                : ( isset( $response_data['error']['code'] ) 
                    ? 'Error code: ' . $response_data['error']['code'] 
                    : 'Unknown NWC error' );
            
            error_log( "NWC Client: Error in response: $error_msg" );
            
            return new WP_Error(
                'nwc_error',
                $error_msg,
                $response_data['error']
            );
        }

        // Return the result
        if ( isset( $response_data['result'] ) ) {
            error_log( "NWC Client: Success! Result: " . wp_json_encode( $response_data['result'] ) );
            return $response_data['result'];
        }

        return $response_data;
    }

    /**
     * Encrypt content for NWC (NIP-04 encryption)
     *
     * @param string $content Content to encrypt
     * @param string $secret  Our secret key
     * @param string $pubkey  Recipient pubkey
     * @return string|WP_Error Encrypted content or error
     */
    private function encrypt_content( $content, $secret, $pubkey ) {
        // NIP-04 encryption uses ECDH + AES-256-CBC
        // This requires secp256k1 cryptography
        
        // For now, return error indicating this needs implementation
        return new WP_Error(
            'not_implemented',
            __( 'NIP-04 encryption not yet implemented. Requires secp256k1 library.', 'nostr-outbox-wordpress' )
        );
    }

    /**
     * Sign a Nostr event
     *
     * @param array  $event  Event to sign
     * @param string $secret Private key (hex)
     * @return array|WP_Error Signed event or error
     */
    private function sign_event( $event, $secret ) {
        // Nostr event signing:
        // 1. Serialize event: [0, pubkey, created_at, kind, tags, content]
        // 2. Get SHA256 hash
        // 3. Sign with schnorr signature using secp256k1
        
        // For now, return error indicating this needs implementation
        return new WP_Error(
            'not_implemented',
            __( 'Nostr event signing not yet implemented. Requires secp256k1 library.', 'nostr-outbox-wordpress' )
        );
    }
}

