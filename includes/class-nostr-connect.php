<?php
/**
 * Nostr Connect - Allow Existing Users to Link Nostr Identity
 * 
 * Enables existing WordPress users to connect their Nostr identity
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles connecting Nostr identities to existing users
 */
class Nostr_Login_Pay_Connect {

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
        // Add connect button to user profile
        add_action( 'show_user_profile', array( $this, 'render_connect_section' ) );
        add_action( 'edit_user_profile', array( $this, 'render_connect_section' ) );
        
        // Add to WooCommerce account page
        add_action( 'woocommerce_account_dashboard', array( $this, 'render_woocommerce_connect_button' ), 30 );
        
        // AJAX handler for connecting Nostr
        add_action( 'wp_ajax_connect_nostr_identity', array( $this, 'ajax_connect_nostr' ) );
        add_action( 'wp_ajax_disconnect_nostr_identity', array( $this, 'ajax_disconnect_nostr' ) );
    }

    /**
     * Render connect section on user profile
     */
    public function render_connect_section( $user ) {
        $nostr_pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
        
        if ( $nostr_pubkey ) {
            // Already connected
            ?>
            <h2><?php _e( 'Nostr Identity', 'nostr-outbox-wordpress' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label><?php _e( 'Connection Status', 'nostr-outbox-wordpress' ); ?></label></th>
                    <td>
                        <p style="color: #15803d;">
                            <strong>✓ <?php _e( 'Connected', 'nostr-outbox-wordpress' ); ?></strong>
                        </p>
                        <p class="description">
                            <?php _e( 'Your account is linked to a Nostr identity. You can log in using your Nostr key.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                        <button type="button" class="button button-secondary" id="disconnect-nostr-btn" style="margin-top: 10px;">
                            <?php _e( 'Disconnect Nostr Identity', 'nostr-outbox-wordpress' ); ?>
                        </button>
                        <span id="disconnect-status" style="margin-left: 10px; color: #666;"></span>
                    </td>
                </tr>
            </table>
            
            <script>
            (function() {
                const btn = document.getElementById('disconnect-nostr-btn');
                const status = document.getElementById('disconnect-status');
                
                if (btn) {
                    btn.addEventListener('click', function() {
                        if (!confirm('<?php _e( 'Are you sure you want to disconnect your Nostr identity? You will no longer be able to log in with Nostr.', 'nostr-outbox-wordpress' ); ?>')) {
                            return;
                        }
                        
                        btn.disabled = true;
                        btn.textContent = '<?php _e( 'Disconnecting...', 'nostr-outbox-wordpress' ); ?>';
                        status.textContent = '';
                        
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'disconnect_nostr_identity',
                                nonce: '<?php echo wp_create_nonce( 'disconnect-nostr-identity' ); ?>',
                                user_id: '<?php echo $user->ID; ?>'
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                location.reload();
                            } else {
                                btn.disabled = false;
                                btn.textContent = '<?php _e( 'Disconnect Nostr Identity', 'nostr-outbox-wordpress' ); ?>';
                                status.textContent = '✗ ' + (data.data?.message || 'Error disconnecting');
                                status.style.color = '#d00';
                            }
                        })
                        .catch(e => {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Disconnect Nostr Identity', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✗ Error: ' + e.message;
                            status.style.color = '#d00';
                        });
                    });
                }
            })();
            </script>
            <?php
        } else {
            // Not connected
            ?>
            <h2><?php _e( 'Connect Nostr Identity', 'nostr-outbox-wordpress' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label><?php _e( 'Connection Status', 'nostr-outbox-wordpress' ); ?></label></th>
                    <td>
                        <p>
                            <?php _e( 'Link your Nostr identity to this WordPress account to enable Nostr login.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                        <button type="button" class="button button-primary" id="connect-nostr-btn">
                            <?php _e( 'Connect Nostr Identity', 'nostr-outbox-wordpress' ); ?>
                        </button>
                        <span id="connect-status" style="margin-left: 10px; color: #666;"></span>
                        <p class="description" style="margin-top: 10px;">
                            <?php _e( 'You\'ll need a Nostr browser extension like nos2x or Alby to connect.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <script>
            (function() {
                const btn = document.getElementById('connect-nostr-btn');
                const status = document.getElementById('connect-status');
                
                if (btn) {
                    btn.addEventListener('click', async function() {
                        // Check for window.nostr
                        if (typeof window.nostr === 'undefined') {
                            alert('<?php _e( 'No Nostr extension detected! Please install nos2x, Alby, or another NIP-07 compatible extension.', 'nostr-outbox-wordpress' ); ?>');
                            return;
                        }
                        
                        try {
                            btn.disabled = true;
                            btn.textContent = '<?php _e( 'Connecting...', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '<?php _e( 'Getting your public key...', 'nostr-outbox-wordpress' ); ?>';
                            
                            // Get public key from extension
                            const pubkey = await window.nostr.getPublicKey();
                            
                            status.textContent = '<?php _e( 'Verifying...', 'nostr-outbox-wordpress' ); ?>';
                            
                            // Create verification event
                            const event = {
                                kind: 22242,
                                created_at: Math.floor(Date.now() / 1000),
                                tags: [],
                                content: 'Connect Nostr to WordPress account'
                            };
                            
                            // Sign event
                            const signedEvent = await window.nostr.signEvent(event);
                            
                            // Send to server
                            const response = await fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'connect_nostr_identity',
                                    nonce: '<?php echo wp_create_nonce( 'connect-nostr-identity' ); ?>',
                                    user_id: '<?php echo $user->ID; ?>',
                                    pubkey: pubkey,
                                    event: JSON.stringify(signedEvent)
                                })
                            });
                            
                            const data = await response.json();
                            
                            if (data.success) {
                                status.textContent = '✓ Connected!';
                                status.style.color = '#0a0';
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                btn.disabled = false;
                                btn.textContent = '<?php _e( 'Connect Nostr Identity', 'nostr-outbox-wordpress' ); ?>';
                                status.textContent = '✗ ' + (data.data?.message || 'Error connecting');
                                status.style.color = '#d00';
                            }
                        } catch (error) {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Connect Nostr Identity', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✗ ' + error.message;
                            status.style.color = '#d00';
                        }
                    });
                }
            })();
            </script>
            <?php
        }
    }

    /**
     * Render connect button on WooCommerce account page
     */
    public function render_woocommerce_connect_button() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        if ( $nostr_pubkey ) {
            // Already connected - no need to show anything
            return;
        }
        
        ?>
        <div class="nostr-connect-section" style="margin-top: 20px; padding: 20px; background: #ede9fe; border: 1px solid #a78bfa; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #5b21b6;">
                🔗 <?php _e( 'Connect Your Nostr Identity', 'nostr-outbox-wordpress' ); ?>
            </h3>
            <p style="margin-bottom: 15px;">
                <?php _e( 'Link your Nostr identity to log in faster and enable Nostr-powered features!', 'nostr-outbox-wordpress' ); ?>
            </p>
            <button type="button" class="button button-primary" id="wc-connect-nostr-btn">
                <?php _e( 'Connect Nostr', 'nostr-outbox-wordpress' ); ?>
            </button>
            <span id="wc-connect-status" style="margin-left: 10px; color: #666;"></span>
            <p style="font-size: 12px; color: #6b21a8; margin: 10px 0 0 0;">
                <?php _e( 'Requires a Nostr extension like nos2x or Alby', 'nostr-outbox-wordpress' ); ?>
            </p>
        </div>
        
        <script>
        (function() {
            const btn = document.getElementById('wc-connect-nostr-btn');
            const status = document.getElementById('wc-connect-status');
            
            if (btn) {
                btn.addEventListener('click', async function() {
                    // Check for window.nostr
                    if (typeof window.nostr === 'undefined') {
                        alert('<?php _e( 'No Nostr extension detected! Please install nos2x, Alby, or another NIP-07 compatible extension.', 'nostr-outbox-wordpress' ); ?>');
                        return;
                    }
                    
                    try {
                        btn.disabled = true;
                        btn.textContent = '<?php _e( 'Connecting...', 'nostr-outbox-wordpress' ); ?>';
                        status.textContent = '<?php _e( 'Getting your public key...', 'nostr-outbox-wordpress' ); ?>';
                        
                        // Get public key from extension
                        const pubkey = await window.nostr.getPublicKey();
                        
                        status.textContent = '<?php _e( 'Verifying...', 'nostr-outbox-wordpress' ); ?>';
                        
                        // Create verification event
                        const event = {
                            kind: 22242,
                            created_at: Math.floor(Date.now() / 1000),
                            tags: [],
                            content: 'Connect Nostr to WordPress account'
                        };
                        
                        // Sign event
                        const signedEvent = await window.nostr.signEvent(event);
                        
                        // Send to server
                        const response = await fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'connect_nostr_identity',
                                nonce: '<?php echo wp_create_nonce( 'connect-nostr-identity' ); ?>',
                                user_id: '<?php echo $user_id; ?>',
                                pubkey: pubkey,
                                event: JSON.stringify(signedEvent)
                            })
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            status.textContent = '✓ Connected!';
                            status.style.color = '#15803d';
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Connect Nostr', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✗ ' + (data.data?.message || 'Error connecting');
                            status.style.color = '#dc2626';
                        }
                    } catch (error) {
                        btn.disabled = false;
                        btn.textContent = '<?php _e( 'Connect Nostr', 'nostr-outbox-wordpress' ); ?>';
                        status.textContent = '✗ ' + error.message;
                        status.style.color = '#dc2626';
                    }
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler to connect Nostr identity
     */
    public function ajax_connect_nostr() {
        check_ajax_referer( 'connect-nostr-identity', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : get_current_user_id();
        
        // Security check
        if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $pubkey = isset( $_POST['pubkey'] ) ? strtolower( trim( sanitize_text_field( $_POST['pubkey'] ) ) ) : '';
        $event = isset( $_POST['event'] ) ? json_decode( stripslashes( $_POST['event'] ), true ) : array();
        
        if ( empty( $pubkey ) ) {
            wp_send_json_error( array( 'message' => __( 'No public key provided', 'nostr-outbox-wordpress' ) ) );
        }

        // Validate pubkey format
        if ( strlen( $pubkey ) !== 64 || ! ctype_xdigit( $pubkey ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid public key format', 'nostr-outbox-wordpress' ) ) );
        }

        // Check if this pubkey is already connected to another account
        $existing_users = get_users( array(
            'meta_key' => 'nostr_pubkey',
            'meta_value' => $pubkey,
            'number' => 1,
        ) );

        if ( ! empty( $existing_users ) && $existing_users[0]->ID !== $user_id ) {
            wp_send_json_error( array( 'message' => __( 'This Nostr identity is already connected to another account', 'nostr-outbox-wordpress' ) ) );
        }

        // Save the pubkey
        update_user_meta( $user_id, 'nostr_pubkey', $pubkey );

        wp_send_json_success( array(
            'message' => __( 'Nostr identity connected successfully', 'nostr-outbox-wordpress' ),
        ) );
    }

    /**
     * AJAX handler to disconnect Nostr identity
     */
    public function ajax_disconnect_nostr() {
        check_ajax_referer( 'disconnect-nostr-identity', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : get_current_user_id();
        
        // Security check
        if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        // Remove the pubkey
        delete_user_meta( $user_id, 'nostr_pubkey' );

        wp_send_json_success( array(
            'message' => __( 'Nostr identity disconnected', 'nostr-outbox-wordpress' ),
        ) );
    }
}

