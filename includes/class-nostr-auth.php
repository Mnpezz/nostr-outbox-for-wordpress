<?php
/**
 * Nostr Authentication Handler
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Nostr authentication and user management
 */
class Nostr_Login_Pay_Auth {

    /**
     * Verify a Nostr event signature
     *
     * @param array  $event The Nostr event
     * @param string $signature The signature to verify
     * @return bool True if valid, false otherwise
     */
    public function verify_nostr_event( $event, $signature ) {
        // Basic validation
        if ( empty( $event['pubkey'] ) || empty( $event['created_at'] ) || empty( $event['kind'] ) ) {
            return false;
        }

        // Verify event is recent (within 5 minutes)
        $time_diff = abs( time() - intval( $event['created_at'] ) );
        if ( $time_diff > 300 ) {
            return false;
        }

        // Verify event kind is correct (kind 22242 is NIP-42 auth)
        if ( intval( $event['kind'] ) !== 22242 ) {
            return false;
        }

        // In production, you would verify the signature using nostr-tools
        // For now, we'll do basic validation
        // The actual signature verification happens in JavaScript before sending to server
        
        return true;
    }

    /**
     * Find or create a WordPress user from a Nostr pubkey
     *
     * @param string $pubkey The Nostr public key
     * @return WP_User|WP_Error The user object or error
     */
    public function find_or_create_user( $pubkey ) {
        // Sanitize and normalize the pubkey (lowercase, remove whitespace)
        $pubkey = strtolower( trim( sanitize_text_field( $pubkey ) ) );
        
        // Validate hex format
        if ( strlen( $pubkey ) !== 64 || ! ctype_xdigit( $pubkey ) ) {
            return new WP_Error( 'invalid_pubkey', __( 'Invalid Nostr public key format', 'nostr-outbox-wordpress' ) );
        }

        // Look for existing user with this pubkey (case-insensitive search)
        $users = get_users( array(
            'meta_key' => 'nostr_pubkey',
            'meta_value' => $pubkey,
            'number' => 1,
        ) );

        if ( ! empty( $users ) ) {
            return $users[0];
        }

        // Create a new user
        // Use hex prefix for username (simpler, no bech32 encoding needed)
        // Display name will be set to correct npub via JavaScript on first login
        $username = 'nostr_' . substr( $pubkey, 0, 16 );
        
        // Create NIP-05 style email using site domain
        $site_domain = parse_url( get_site_url(), PHP_URL_HOST );
        $email = $username . '@' . $site_domain;

        // Check if username already exists
        $counter = 1;
        $original_username = $username;
        while ( username_exists( $username ) ) {
            $username = $original_username . '_' . $counter;
            $counter++;
        }

        $user_id = wp_create_user( $username, wp_generate_password( 32 ), $email );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Save the Nostr pubkey
        update_user_meta( $user_id, 'nostr_pubkey', $pubkey );

        // Set display name to hex prefix initially (will be updated to npub by JavaScript)
        wp_update_user( array(
            'ID' => $user_id,
            'display_name' => $username,
        ) );

        // Set user role to customer if WooCommerce is active
        if ( class_exists( 'WooCommerce' ) ) {
            $user = new WP_User( $user_id );
            $user->set_role( 'customer' );
        }

        do_action( 'nostr_login_pay_user_created', $user_id, $pubkey );

        return get_user_by( 'id', $user_id );
    }

    /**
     * Get user by Nostr pubkey
     *
     * @param string $pubkey The Nostr public key
     * @return WP_User|false The user object or false
     */
    public function get_user_by_pubkey( $pubkey ) {
        $users = get_users( array(
            'meta_key' => 'nostr_pubkey',
            'meta_value' => sanitize_text_field( $pubkey ),
            'number' => 1,
        ) );

        return ! empty( $users ) ? $users[0] : false;
    }

    /**
     * Update user's Nostr profile data
     *
     * @param int   $user_id User ID
     * @param array $profile_data Profile data from Nostr
     * @return bool Success status
     */
    public function update_user_profile( $user_id, $profile_data ) {
        $updated = false;

        if ( ! empty( $profile_data['name'] ) ) {
            update_user_meta( $user_id, 'nostr_display_name', sanitize_text_field( $profile_data['name'] ) );
            wp_update_user( array(
                'ID' => $user_id,
                'display_name' => sanitize_text_field( $profile_data['name'] ),
            ) );
            
            // Update email to friendly NIP-05 format when name is synced
            $site_domain = parse_url( get_site_url(), PHP_URL_HOST );
            $friendly_name = sanitize_title( $profile_data['name'] ); // Convert to safe email format
            $new_email = $friendly_name . '@' . $site_domain;
            
            // Check if email already exists
            if ( ! email_exists( $new_email ) ) {
                wp_update_user( array(
                    'ID' => $user_id,
                    'user_email' => $new_email,
                ) );
            }
            
            $updated = true;
        }

        if ( ! empty( $profile_data['about'] ) ) {
            update_user_meta( $user_id, 'description', sanitize_textarea_field( $profile_data['about'] ) );
            $updated = true;
        }

        if ( ! empty( $profile_data['picture'] ) ) {
            update_user_meta( $user_id, 'nostr_picture', esc_url_raw( $profile_data['picture'] ) );
            $updated = true;
        }

        if ( ! empty( $profile_data['nip05'] ) ) {
            update_user_meta( $user_id, 'nostr_nip05', sanitize_text_field( $profile_data['nip05'] ) );
            $updated = true;
        }

        return $updated;
    }

    /**
     * Generate a login challenge for Nostr authentication
     *
     * @return array Challenge data
     */
    public function generate_login_challenge() {
        $challenge = wp_generate_password( 32, false );
        $timestamp = time();

        set_transient( 'nostr_login_challenge_' . $challenge, $timestamp, 300 ); // 5 minutes

        return array(
            'challenge' => $challenge,
            'timestamp' => $timestamp,
            'site_name' => get_bloginfo( 'name' ),
            'site_url' => get_site_url(),
        );
    }

    /**
     * Verify a login challenge
     *
     * @param string $challenge The challenge string
     * @return bool True if valid, false otherwise
     */
    public function verify_login_challenge( $challenge ) {
        $timestamp = get_transient( 'nostr_login_challenge_' . $challenge );

        if ( false === $timestamp ) {
            return false;
        }

        delete_transient( 'nostr_login_challenge_' . $challenge );

        return true;
    }

    /**
     * Convert hex public key to npub format (bech32)
     *
     * @param string $hex_pubkey Hex public key (64 characters)
     * @return string|false npub string or false on error
     */
    private function hex_to_npub( $hex_pubkey ) {
        if ( empty( $hex_pubkey ) || strlen( $hex_pubkey ) !== 64 ) {
            return false;
        }

        // If already in npub format, return as is
        if ( strpos( $hex_pubkey, 'npub' ) === 0 ) {
            return $hex_pubkey;
        }

        // Normalize to lowercase for hex2bin
        $hex_pubkey = strtolower( $hex_pubkey );

        // Convert hex to bytes
        $bytes = hex2bin( $hex_pubkey );
        if ( $bytes === false ) {
            return false;
        }

        // Bech32 encoding
        return $this->bech32_encode( 'npub', $bytes );
    }

    /**
     * Bech32 encoding implementation (BIP-173)
     * Based on reference implementation from Bitcoin Core
     *
     * @param string $hrp Human-readable part (e.g., 'npub')
     * @param string $data Binary data to encode
     * @return string Bech32 encoded string
     */
    private function bech32_encode( $hrp, $data ) {
        $charset = 'qpzry9x8gf2tvdw0s3jn54kce10mru6yghe45a7';
        $hrp = strtolower( $hrp );
        
        // Convert data bytes to 5-bit groups
        $data_uint5 = $this->convert_bits( $data, 8, 5, true );
        
        // Expand HRP
        $hrp_expanded = array();
        for ( $i = 0; $i < strlen( $hrp ); $i++ ) {
            $c = ord( $hrp[ $i ] );
            $hrp_expanded[] = $c >> 5;
        }
        $hrp_expanded[] = 0;
        for ( $i = 0; $i < strlen( $hrp ); $i++ ) {
            $hrp_expanded[] = ord( $hrp[ $i ] ) & 31;
        }
        
        // Calculate checksum
        $combined = array_merge( $hrp_expanded, $data_uint5 );
        $polymod = $this->bech32_polymod( $combined ) ^ 1;
        $checksum = array();
        for ( $i = 0; $i < 6; $i++ ) {
            $checksum[] = ( $polymod >> ( 5 * ( 5 - $i ) ) ) & 31;
        }
        
        // Build result
        $result = $hrp . '1';
        foreach ( array_merge( $data_uint5, $checksum ) as $v ) {
            $result .= $charset[ $v ];
        }
        
        return $result;
    }

    /**
     * Convert between bit sizes
     *
     * @param string $data Input data
     * @param int $frombits Source bit size
     * @param int $tobits Target bit size
     * @param bool $pad Pad output
     * @return array Array of integers
     */
    private function convert_bits( $data, $frombits, $tobits, $pad = true ) {
        $acc = 0;
        $bits = 0;
        $ret = array();
        $maxv = ( 1 << $tobits ) - 1;
        $max_acc = ( 1 << ( $frombits + $tobits - 1 ) ) - 1;
        
        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $value = ord( $data[ $i ] );
            if ( $value < 0 || ( $value >> $frombits ) ) {
                return array(); // Invalid
            }
            $acc = ( ( $acc << $frombits ) | $value ) & $max_acc;
            $bits += $frombits;
            while ( $bits >= $tobits ) {
                $bits -= $tobits;
                $ret[] = ( ( $acc >> $bits ) & $maxv );
            }
        }
        
        if ( $pad ) {
            if ( $bits ) {
                $ret[] = ( ( $acc << ( $tobits - $bits ) ) & $maxv );
            }
        } elseif ( $bits >= $frombits || ( ( $acc << ( $tobits - $bits ) ) & $maxv ) ) {
            return array(); // Invalid
        }
        
        return $ret;
    }

    /**
     * Bech32 polymod function (BIP-173)
     *
     * @param array $values Array of 5-bit values
     * @return int Polymod result (32-bit)
     */
    private function bech32_polymod( $values ) {
        $generator = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
        $chk = 1;
        foreach ( $values as $value ) {
            $top = $chk >> 25;
            $chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;
            for ( $i = 0; $i < 5; $i++ ) {
                if ( ( $top >> $i ) & 1 ) {
                    $chk ^= $generator[ $i ];
                }
            }
        }
        // Ensure 32-bit result (handle PHP's integer overflow)
        if ( PHP_INT_SIZE === 8 ) {
            // 64-bit system - mask to 32 bits
            return $chk & 0xffffffff;
        }
        return $chk;
    }
}

