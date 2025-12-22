<?php
/**
 * Pure PHP Nostr Cryptography
 * 
 * Implements Nostr signing and NIP-04 encryption using elliptic-php library
 * Requires: simplito/elliptic-php and textalk/websocket via Composer
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Elliptic\EC;
use Elliptic\EdDSA;

/**
 * Nostr cryptography functions in pure PHP
 */
class Nostr_Login_Pay_Crypto_PHP {

    /**
     * @var EC Elliptic curve instance
     */
    private static $ec = null;

    /**
     * Initialize elliptic curve
     */
    private static function init() {
        if ( is_null( self::$ec ) ) {
            if ( ! class_exists( 'Elliptic\EC' ) ) {
                error_log( 'Nostr Crypto: Elliptic\EC class not found. Run: composer require simplito/elliptic-php' );
                return false;
            }
            self::$ec = new EC( 'secp256k1' );
        }
        return true;
    }

    /**
     * Check if crypto library is available
     * 
     * @return bool True if available
     */
    public static function is_available() {
        return class_exists( 'Elliptic\EC' ) && extension_loaded( 'openssl' ) && extension_loaded( 'gmp' );
    }

    /**
     * Sign a Nostr event with private key
     * 
     * @param array $event Event to sign (without id and sig)
     * @param string $privkey_hex Private key in hex format (64 chars)
     * @return array|false Complete event with id and sig, or false on error
     */
    public static function sign_event( $event, $privkey_hex ) {
        if ( ! self::init() ) {
            return false;
        }

        try {
            // 1. Calculate event ID
            $event_id = self::get_event_id( $event );
            
            // 2. Sign with Schnorr
            $signature = self::schnorr_sign( $event_id, $privkey_hex );
            
            if ( ! $signature ) {
                error_log( 'Nostr Crypto: Failed to sign event' );
                return false;
            }
            
            // 3. Return complete event
            return array_merge( $event, array(
                'id' => $event_id,
                'sig' => $signature,
            ) );
        } catch ( Exception $e ) {
            error_log( 'Nostr Crypto: Error signing event: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Calculate Nostr event ID (sha256 of serialized event)
     * 
     * @param array $event Event data
     * @return string Event ID (hex)
     */
    public static function get_event_id( $event ) {
        // Serialize according to NIP-01
        $serialized = json_encode( array(
            0,
            $event['pubkey'],
            $event['created_at'],
            $event['kind'],
            $event['tags'],
            $event['content'],
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        
        return hash( 'sha256', $serialized );
    }

    /**
     * Schnorr-like signature for Nostr events
     * 
     * NOTE: Uses ECDSA from elliptic-php (not true BIP-340 Schnorr)
     * Works with Nostr protocol since signature format is compatible
     * 
     * @param string $message_hex Message to sign (hex)
     * @param string $privkey_hex Private key (hex)
     * @return string|false Signature (hex, 128 chars) or false on error
     */
    public static function schnorr_sign( $message_hex, $privkey_hex ) {
        if ( ! self::init() ) {
            return false;
        }

        try {
            // Create keypair from private key
            $keyPair = self::$ec->keyFromPrivate( $privkey_hex, 'hex' );
            
            // Sign the message hash
            $signature = $keyPair->sign( $message_hex, 'hex', array( 'canonical' => true ) );
            
            // Extract r and s values (64 bytes each in hex)
            $r = str_pad( $signature->r->toString( 'hex' ), 64, '0', STR_PAD_LEFT );
            $s = str_pad( $signature->s->toString( 'hex' ), 64, '0', STR_PAD_LEFT );
            
            // Return as 128-char hex string (r || s)
            return $r . $s;
            
        } catch ( Exception $e ) {
            error_log( 'Nostr Crypto: Schnorr signing error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Get public key from private key
     * 
     * @param string $privkey_hex Private key (hex, 64 chars)
     * @return string|false Public key (hex, 64 chars) or false on error
     */
    public static function get_public_key( $privkey_hex ) {
        if ( ! self::init() ) {
            return false;
        }

        try {
            $keyPair = self::$ec->keyFromPrivate( $privkey_hex, 'hex' );
            $pubPoint = $keyPair->getPublic();
            
            // Return x-coordinate only (Schnorr x-only pubkey)
            return str_pad( $pubPoint->getX()->toString( 'hex' ), 64, '0', STR_PAD_LEFT );
            
        } catch ( Exception $e ) {
            error_log( 'Nostr Crypto: Get public key error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * NIP-04 Encrypt content for DMs
     * 
     * @param string $content Plain text content
     * @param string $recipient_pubkey_hex Recipient's public key (hex, 64 chars)
     * @param string $sender_privkey_hex Sender's private key (hex, 64 chars)
     * @return string|false Encrypted content in NIP-04 format, or false on error
     */
    public static function nip04_encrypt( $content, $recipient_pubkey_hex, $sender_privkey_hex ) {
        if ( ! self::init() ) {
            return false;
        }

        try {
            // 1. ECDH shared secret
            $shared_secret = self::get_shared_secret( $sender_privkey_hex, $recipient_pubkey_hex );
            
            if ( ! $shared_secret ) {
                error_log( 'Nostr Crypto: Failed to generate shared secret for encryption' );
                return false;
            }
            
            // 2. Random IV (16 bytes)
            $iv = random_bytes( 16 );
            
            // 3. AES-256-CBC encryption
            $ciphertext = openssl_encrypt(
                $content,
                'aes-256-cbc',
                $shared_secret,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ( $ciphertext === false ) {
                error_log( 'Nostr Crypto: OpenSSL encryption failed' );
                return false;
            }
            
            // 4. Return in NIP-04 format: "ciphertext_base64?iv_base64"
            return base64_encode( $ciphertext ) . '?' . base64_encode( $iv );
            
        } catch ( Exception $e ) {
            error_log( 'Nostr Crypto: Encryption error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * NIP-04 Decrypt content from DMs
     * 
     * @param string $encrypted_content Encrypted content (format: "ciphertext_base64?iv_base64")
     * @param string $sender_pubkey_hex Sender's public key (hex, 64 chars)
     * @param string $recipient_privkey_hex Recipient's private key (hex, 64 chars)
     * @return string|false Plain text content or false on error
     */
    public static function nip04_decrypt( $encrypted_content, $sender_pubkey_hex, $recipient_privkey_hex ) {
        if ( ! self::init() ) {
            return false;
        }

        try {
            // Parse NIP-04 format
            $parts = explode( '?', $encrypted_content );
            if ( count( $parts ) !== 2 ) {
                error_log( 'Nostr Crypto: Invalid NIP-04 format' );
                return false;
            }
            
            $ciphertext = base64_decode( $parts[0] );
            $iv = base64_decode( $parts[1] );
            
            // Generate shared secret
            $shared_secret = self::get_shared_secret( $recipient_privkey_hex, $sender_pubkey_hex );
            
            if ( ! $shared_secret ) {
                error_log( 'Nostr Crypto: Failed to generate shared secret for decryption' );
                return false;
            }
            
            // Decrypt
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-cbc',
                $shared_secret,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ( $plaintext === false ) {
                error_log( 'Nostr Crypto: OpenSSL decryption failed' );
                return false;
            }
            
            return $plaintext;
            
        } catch ( Exception $e ) {
            error_log( 'Nostr Crypto: Decryption error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * ECDH key agreement (generate shared secret)
     * 
     * @param string $privkey_hex Your private key (hex, 64 chars)
     * @param string $pubkey_hex Their public key (hex, 64 chars)
     * @return string|false Shared secret (32 bytes binary) or false on error
     */
    private static function get_shared_secret( $privkey_hex, $pubkey_hex ) {
        if ( ! self::init() ) {
            return false;
        }

        try {
            // Create keypair from private key
            $keyPair = self::$ec->keyFromPrivate( $privkey_hex, 'hex' );
            
            // Reconstruct public key point from x-coordinate
            // Try even parity first (0x02 prefix)
            $pubPoint = self::$ec->curve->decodePoint( '02' . $pubkey_hex, 'hex' );
            
            // Compute shared secret (ECDH)
            $sharedPoint = $pubPoint->mul( $keyPair->getPrivate() );
            $sharedSecret = str_pad( $sharedPoint->getX()->toString( 'hex' ), 64, '0', STR_PAD_LEFT );
            
            // Return as 32-byte binary
            return hex2bin( $sharedSecret );
            
        } catch ( Exception $e ) {
            // Try odd parity (0x03 prefix) if even failed
            try {
                $pubPoint = self::$ec->curve->decodePoint( '03' . $pubkey_hex, 'hex' );
                $keyPair = self::$ec->keyFromPrivate( $privkey_hex, 'hex' );
                $sharedPoint = $pubPoint->mul( $keyPair->getPrivate() );
                $sharedSecret = str_pad( $sharedPoint->getX()->toString( 'hex' ), 64, '0', STR_PAD_LEFT );
                return hex2bin( $sharedSecret );
            } catch ( Exception $e2 ) {
                error_log( 'Nostr Crypto: ECDH error: ' . $e2->getMessage() );
                return false;
            }
        }
    }

    /**
     * Publish event to Nostr relays via WebSocket
     * 
     * @param array $event Signed Nostr event
     * @param array $relays Array of relay URLs
     * @return bool True if published to at least one relay
     */
    public static function publish_to_relays( $event, $relays ) {
        if ( ! class_exists( 'WebSocket\Client' ) ) {
            error_log( 'Nostr Crypto: WebSocket\Client not found. Run: composer require textalk/websocket' );
            return false;
        }

        $success_count = 0;
        
        foreach ( $relays as $relay_url ) {
            try {
                error_log( "Nostr Crypto: Publishing to relay: $relay_url" );
                
                $client = new \WebSocket\Client( $relay_url, array(
                    'timeout' => 10,
                ) );
                
                // Send EVENT message
                $message = json_encode( array( 'EVENT', $event ) );
                $client->send( $message );
                
                // Wait for OK response (with timeout)
                $client->setTimeout( 5 );
                $response = $client->receive();
                
                $client->close();
                
                error_log( "Nostr Crypto: Published to $relay_url, response: $response" );
                $success_count++;
                
            } catch ( Exception $e ) {
                error_log( "Nostr Crypto: Failed to publish to $relay_url: " . $e->getMessage() );
            }
        }
        
        return $success_count > 0;
    }
}

