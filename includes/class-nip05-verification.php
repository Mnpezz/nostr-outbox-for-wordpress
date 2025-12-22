<?php
/**
 * NIP-05 Verification Service
 * 
 * Provides NIP-05 identity verification for WordPress users
 * Users get verified as: username@yourdomain.com
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles NIP-05 identity verification
 */
class Nostr_Login_Pay_NIP05 {

    /**
     * The single instance of the class
     */
    private static $instance = null;

    /**
     * Main Instance
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Serve .well-known/nostr.json endpoint
        add_action( 'init', array( $this, 'handle_nostr_json_request' ) );
        
        // Add NIP-05 field to user profile
        add_action( 'show_user_profile', array( $this, 'render_nip05_field' ) );
        add_action( 'edit_user_profile', array( $this, 'render_nip05_field' ) );
        
        // Add NIP-05 to WooCommerce account page
        add_action( 'woocommerce_account_dashboard', array( $this, 'render_woocommerce_nip05' ), 15 );
    }

    /**
     * Handle /.well-known/nostr.json requests
     */
    public function handle_nostr_json_request() {
        $request_uri = $_SERVER['REQUEST_URI'];
        
        // Check if this is a request to /.well-known/nostr.json
        if ( strpos( $request_uri, '/.well-known/nostr.json' ) !== false ) {
            $this->serve_nostr_json();
            exit;
        }
    }

    /**
     * Serve the nostr.json file
     */
    private function serve_nostr_json() {
        // Get optional name parameter for single user lookup
        $name = isset( $_GET['name'] ) ? sanitize_user( $_GET['name'] ) : '';
        
        $names = array();
        $relays = array();
        
        if ( ! empty( $name ) ) {
            // Single user lookup - try to find by NIP-05 name OR username
            $user = $this->get_user_by_nip05_name( $name );
            if ( ! $user ) {
                $user = get_user_by( 'login', $name );
            }
            
            if ( $user ) {
                $pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
                if ( $pubkey ) {
                    $nip05_name = $this->get_nip05_name( $user );
                    $names[ $nip05_name ] = $pubkey;
                    $relays[ $pubkey ] = $this->get_user_relays( $user->ID );
                }
            }
        } else {
            // Return all users with Nostr pubkeys
            $users = get_users( array(
                'meta_key' => 'nostr_pubkey',
                'number' => 1000, // Limit to prevent huge responses
            ) );
            
            foreach ( $users as $user ) {
                $pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
                if ( $pubkey ) {
                    $nip05_name = $this->get_nip05_name( $user );
                    $names[ $nip05_name ] = $pubkey;
                    $relays[ $pubkey ] = $this->get_user_relays( $user->ID );
                }
            }
        }
        
        $response = array(
            'names' => $names,
            'relays' => $relays,
        );
        
        header( 'Content-Type: application/json' );
        header( 'Access-Control-Allow-Origin: *' );
        echo json_encode( $response );
    }

    /**
     * Get NIP-05 friendly name for a user
     * Priority: custom nip05_name > synced Nostr name > display name > username
     * 
     * @param WP_User $user WordPress user object
     * @return string Friendly name for NIP-05
     */
    private function get_nip05_name( $user ) {
        // 1. Check for custom NIP-05 name (user can set this)
        $custom_name = get_user_meta( $user->ID, 'nip05_custom_name', true );
        if ( ! empty( $custom_name ) ) {
            return sanitize_user( $custom_name, true );
        }
        
        // 2. Check for synced Nostr display name
        $nostr_name = get_user_meta( $user->ID, 'nostr_display_name', true );
        if ( ! empty( $nostr_name ) && ! strpos( $nostr_name, 'nostr_' ) === 0 ) {
            // Use synced name if it's not the hex-based username
            return sanitize_user( $nostr_name, true );
        }
        
        // 3. Use display name if it's not an npub or hex username
        $display_name = $user->display_name;
        if ( ! empty( $display_name ) && 
             strpos( $display_name, 'npub1' ) !== 0 && 
             strpos( $display_name, 'nostr_' ) !== 0 ) {
            return sanitize_user( $display_name, true );
        }
        
        // 4. Fall back to WordPress username
        return $user->user_login;
    }

    /**
     * Get user by NIP-05 name (checks custom name and synced name)
     * 
     * @param string $name NIP-05 name to search for
     * @return WP_User|false User object or false
     */
    private function get_user_by_nip05_name( $name ) {
        // Try to find user with this custom NIP-05 name
        $users = get_users( array(
            'meta_key' => 'nip05_custom_name',
            'meta_value' => $name,
            'number' => 1,
        ) );
        
        if ( ! empty( $users ) ) {
            return $users[0];
        }
        
        // Try to find by synced Nostr display name
        $users = get_users( array(
            'meta_key' => 'nostr_display_name',
            'meta_value' => $name,
            'number' => 1,
        ) );
        
        if ( ! empty( $users ) ) {
            return $users[0];
        }
        
        return false;
    }

    /**
     * Get relay list for a user
     * 
     * @param int $user_id User ID
     * @return array Array of relay URLs
     */
    private function get_user_relays( $user_id ) {
        // Get custom user relays or fall back to default
        $user_relays = get_user_meta( $user_id, 'nostr_relays', true );
        
        if ( ! empty( $user_relays ) && is_array( $user_relays ) ) {
            return $user_relays;
        }
        
        // Default relays
        return $this->get_default_relays();
    }

    /**
     * Get default relay list
     * 
     * @return array Array of relay URLs
     */
    private function get_default_relays() {
        $default = array(
            'wss://relay.damus.io',
            'wss://relay.snort.social',
            'wss://nos.lol',
            'wss://relay.nostr.band',
        );
        
        // Allow filtering via settings
        $saved_relays = get_option( 'nostr_login_pay_default_relays', array() );
        
        return ! empty( $saved_relays ) ? $saved_relays : $default;
    }

    /**
     * Render NIP-05 field on user profile
     */
    public function render_nip05_field( $user ) {
        $nostr_pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        $domain = parse_url( home_url(), PHP_URL_HOST );
        $nip05_name = $this->get_nip05_name( $user );
        $nip05 = $nip05_name . '@' . $domain;
        
        ?>
        <h2><?php _e( 'NIP-05 Verification', 'nostr-outbox-wordpress' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label><?php _e( 'NIP-05 Identifier', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <code><?php echo esc_html( $nip05 ); ?></code>
                    <p class="description">
                        <?php _e( 'Use this identifier to verify your Nostr identity. Add it to your Nostr profile in the "nip05" field.', 'nostr-outbox-wordpress' ); ?>
                    </p>
                    <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $nip05 ); ?>'); alert('Copied to clipboard!');">
                        <?php _e( 'Copy NIP-05', 'nostr-outbox-wordpress' ); ?>
                    </button>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'Verification Status', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <span id="nip05-status" style="color: #666;">
                        <?php _e( 'Checking...', 'nostr-outbox-wordpress' ); ?>
                    </span>
                    <script>
                    (function() {
                        // Test NIP-05 verification
                        const domain = '<?php echo esc_js( $domain ); ?>';
                        const username = '<?php echo esc_js( $user->user_login ); ?>';
                        const expectedPubkey = '<?php echo esc_js( $nostr_pubkey ); ?>';
                        
                        fetch('https://' + domain + '/.well-known/nostr.json?name=' + username)
                            .then(r => r.json())
                            .then(data => {
                                const statusEl = document.getElementById('nip05-status');
                                if (data.names && data.names[username] === expectedPubkey) {
                                    statusEl.innerHTML = '✓ <span style="color: #0a0;">Verified</span>';
                                } else {
                                    statusEl.innerHTML = '✗ <span style="color: #d00;">Not verified</span>';
                                }
                            })
                            .catch(e => {
                                document.getElementById('nip05-status').innerHTML = '⚠ <span style="color: #f90;">Error checking verification</span>';
                            });
                    })();
                    </script>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render NIP-05 on WooCommerce account page
     */
    public function render_woocommerce_nip05() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        $user = wp_get_current_user();
        $domain = parse_url( home_url(), PHP_URL_HOST );
        $nip05_name = $this->get_nip05_name( $user );
        $nip05 = $nip05_name . '@' . $domain;
        
        ?>
        <div class="nostr-nip05-section" style="margin-top: 20px; padding: 20px; background: #f0f9ff; border: 1px solid #3b82f6; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #1e40af;">
                ✓ <?php _e( 'NIP-05 Verified Identity', 'nostr-outbox-wordpress' ); ?>
            </h3>
            <p>
                <strong><?php _e( 'Your NIP-05 Identifier:', 'nostr-outbox-wordpress' ); ?></strong><br>
                <code style="font-size: 14px; padding: 5px 10px; background: white; border-radius: 4px;"><?php echo esc_html( $nip05 ); ?></code>
                <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $nip05 ); ?>'); this.textContent = 'Copied!';" style="margin-left: 10px; padding: 4px 8px; font-size: 12px;">
                    <?php _e( 'Copy', 'nostr-outbox-wordpress' ); ?>
                </button>
            </p>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 0;">
                <strong><?php _e( 'How to use:', 'nostr-outbox-wordpress' ); ?></strong><br>
                <?php _e( 'Add this identifier to your Nostr profile (in the "nip05" field) to verify your identity across Nostr clients.', 'nostr-outbox-wordpress' ); ?>
            </p>
        </div>
        <?php
    }
}

