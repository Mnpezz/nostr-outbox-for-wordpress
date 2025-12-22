<?php
/**
 * Nostr Notifications - Replace Email with Nostr DMs
 * 
 * Intercepts WordPress emails and sends them as encrypted Nostr DMs instead
 * Compatible with 0xchat-app and other NIP-04/NIP-17 clients
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Nostr notifications via encrypted DMs
 */
class Nostr_Login_Pay_Notifications {

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
        // Hook into WordPress email system
        add_filter( 'wp_mail', array( $this, 'intercept_email' ), 10, 1 );
        
        // Add user preference settings
        add_action( 'show_user_profile', array( $this, 'render_notification_settings' ) );
        add_action( 'edit_user_profile', array( $this, 'render_notification_settings' ) );
        add_action( 'personal_options_update', array( $this, 'save_notification_settings' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_notification_settings' ) );
        
        // Add to WooCommerce account page
        add_action( 'woocommerce_account_dashboard', array( $this, 'render_woocommerce_notification_settings' ), 20 );
        
        // AJAX handler for updating preferences
        add_action( 'wp_ajax_update_nostr_notification_preference', array( $this, 'ajax_update_preference' ) );
        
        // AJAX handlers for DM queue management
        add_action( 'wp_ajax_get_nostr_dm_queue', array( $this, 'ajax_get_dm_queue' ) );
        add_action( 'wp_ajax_clear_nostr_dm_queue', array( $this, 'ajax_clear_dm_queue' ) );
    }

    /**
     * Intercept WordPress emails and send as Nostr DMs
     * 
     * @param array $args Email arguments
     * @return array Modified email arguments
     */
    public function intercept_email( $args ) {
        // Get recipient email
        $to = $args['to'];
        
        // Handle array of recipients
        if ( is_array( $to ) ) {
            $to = $to[0]; // For now, just handle first recipient
        }
        
        // Get user by email
        $user = get_user_by( 'email', $to );
        
        if ( ! $user ) {
            // Not a WordPress user, send email normally
            error_log( 'Nostr Notifications: Email to ' . $to . ' - not a WP user, sending email' );
            return $args;
        }
        
        // Debug: Log user info
        error_log( 'Nostr Notifications: Found user ID=' . $user->ID . ', login=' . $user->user_login . ', email=' . $user->user_email );
        
        // Check if user has Nostr pubkey
        $nostr_pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
        
        if ( empty( $nostr_pubkey ) ) {
            // No Nostr pubkey, send email normally
            error_log( 'Nostr Notifications: User ' . $user->user_login . ' (ID=' . $user->ID . ') - no pubkey found in meta, sending email' );
            error_log( 'Nostr Notifications: All user meta for debugging: ' . print_r( get_user_meta( $user->ID ), true ) );
            return $args;
        }
        
        // Check if user has DMs enabled (check for explicit false, default to true for Nostr users)
        $use_nostr_dm = get_user_meta( $user->ID, 'nostr_dm_notifications', true );
        
        // If preference not set yet (empty string), default to enabled for Nostr users
        if ( $use_nostr_dm === '' || $use_nostr_dm === false ) {
            $use_nostr_dm = '1';
            update_user_meta( $user->ID, 'nostr_dm_notifications', '1' );
            error_log( 'Nostr Notifications: Auto-enabled DMs for user ' . $user->user_login );
        }
        
        // Only send email if explicitly disabled (value is '0')
        if ( $use_nostr_dm === '0' ) {
            error_log( 'Nostr Notifications: User ' . $user->user_login . ' - DMs explicitly disabled, sending email' );
            return $args;
        }
        
        // User has Nostr and DMs enabled, send DM instead of email
        if ( ! empty( $nostr_pubkey ) ) {
            // Send as Nostr DM instead of email
            $subject = $args['subject'];
            $message = is_array( $args['message'] ) ? implode( "\n", $args['message'] ) : $args['message'];
            
            // Strip HTML if present
            $message = wp_strip_all_tags( $message );
            
            // Send the DM
            $sent = $this->send_nostr_dm( $nostr_pubkey, $subject, $message, $user->user_login );
            
            if ( $sent ) {
                // Log that we sent via Nostr
                error_log( 'Nostr DM queued for user ' . $user->user_login . ' (' . $nostr_pubkey . ') - Subject: ' . $subject );
                
                // Prevent email from being sent - set to empty array
                $args['to'] = array();
                
                return $args;
            } else {
                error_log( 'Nostr Notifications: Failed to queue DM for user ' . $user->user_login . ', sending email as fallback' );
                return $args;
            }
        }
        
        // Shouldn't reach here, but return args just in case
        return $args;
    }

    /**
     * Send a Nostr encrypted DM (NIP-04)
     * 
     * @param string $recipient_pubkey Recipient's Nostr public key (hex)
     * @param string $subject Email subject
     * @param string $message Email message
     * @param string $recipient_username Optional username for logging
     * @return bool Success status
     */
    private function send_nostr_dm( $recipient_pubkey, $subject, $message, $recipient_username = '' ) {
        // Get site's Nostr credentials
        $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
        
        if ( empty( $site_privkey ) ) {
            // Generate a new private key for the site if one doesn't exist
            $site_privkey = $this->generate_private_key();
            update_option( 'nostr_login_pay_site_privkey', $site_privkey );
        }
        
        // Combine subject and message
        $full_message = "**{$subject}**\n\n{$message}";
        
        // Queue the DM to be sent (using options for persistence across sessions)
        $dm_queue = get_option( 'nostr_dm_queue', array() );
        error_log( 'Nostr DM: Retrieved queue from DB, current size: ' . ( is_array( $dm_queue ) ? count( $dm_queue ) : gettype( $dm_queue ) ) );
        
        // CRITICAL: If queue is corrupted, reset it and log the issue
        if ( ! is_array( $dm_queue ) ) {
            error_log( '🚨 Nostr DM: Queue was corrupted (type: ' . gettype( $dm_queue ) . ')! Deleting and resetting...' );
            delete_option( 'nostr_dm_queue' ); // Force delete the corrupted option
            $dm_queue = array();
        }
        
        $new_item = array(
            'id' => uniqid( 'dm_', true ), // Unique ID for individual deletion
            'recipient' => $recipient_pubkey,
            'message' => $full_message,
            'subject' => $subject,
            'username' => $recipient_username,
            'timestamp' => time(),
        );
        
        $dm_queue[] = $new_item;
        
        error_log( 'Nostr DM: About to save queue with size: ' . count( $dm_queue ) );
        
        // Delete the option first to ensure a clean save
        delete_option( 'nostr_dm_queue' );
        
        // Now add it fresh (this ensures we never get a "false" from update_option on corrupted data)
        $save_result = add_option( 'nostr_dm_queue', $dm_queue, '', 'no' );
        
        error_log( 'Nostr DM: Save returned: ' . var_export( $save_result, true ) );
        
        // Verify it was saved
        $verify_queue = get_option( 'nostr_dm_queue', array() );
        error_log( 'Nostr DM: VERIFICATION - Queue size after save: ' . ( is_array( $verify_queue ) ? count( $verify_queue ) : 'not array!' ) );
        
        if ( ! is_array( $verify_queue ) || count( $verify_queue ) === 0 ) {
            error_log( '🚨 Nostr DM: CRITICAL - Queue save FAILED! Attempting direct DB insert...' );
            
            // Last resort: try direct database insert
            global $wpdb;
            $option_name = 'nostr_dm_queue';
            $serialized_value = maybe_serialize( $dm_queue );
            
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
            
            if ( $existing ) {
                $result = $wpdb->update(
                    $wpdb->options,
                    array( 'option_value' => $serialized_value ),
                    array( 'option_name' => $option_name ),
                    array( '%s' ),
                    array( '%s' )
                );
            } else {
                $result = $wpdb->insert(
                    $wpdb->options,
                    array(
                        'option_name' => $option_name,
                        'option_value' => $serialized_value,
                        'autoload' => 'no',
                    ),
                    array( '%s', '%s', '%s' )
                );
            }
            
            error_log( '🔧 Nostr DM: Direct DB operation result: ' . var_export( $result, true ) );
            
            // Verify again
            $verify_queue_2 = get_option( 'nostr_dm_queue', array() );
            error_log( '🔍 Nostr DM: After direct DB - Queue size: ' . count( $verify_queue_2 ) );
        }
        
        error_log( 'Nostr DM: Queued message to ' . $recipient_username . ' (' . substr( $recipient_pubkey, 0, 16 ) . '...) - Final queue size: ' . count( $verify_queue ) );
        
        // Trigger a background process to send DMs
        // WP-Cron will handle actual sending
        return true;
    }

    /**
     * Generate a new Nostr private key
     * 
     * @return string 64-character hex private key
     */
    private function generate_private_key() {
        // Generate 32 random bytes
        $bytes = random_bytes( 32 );
        return bin2hex( $bytes );
    }

    /**
     * Render notification settings on user profile
     */
    public function render_notification_settings( $user ) {
        $nostr_pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        $use_nostr_dm = get_user_meta( $user->ID, 'nostr_dm_notifications', true );
        
        ?>
        <h2><?php _e( 'Nostr Notifications', 'nostr-outbox-wordpress' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="nostr_dm_notifications"><?php _e( 'Notification Method', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="nostr_dm_notifications" id="nostr_dm_notifications" value="1" <?php checked( $use_nostr_dm, '1' ); ?> />
                        <?php _e( 'Send notifications as Nostr encrypted DMs instead of email', 'nostr-outbox-wordpress' ); ?>
                    </label>
                    <p class="description">
                        <?php _e( 'When enabled, you\'ll receive notifications via Nostr direct messages instead of email. Works with 0xchat-app and other Nostr clients that support encrypted DMs (NIP-04).', 'nostr-outbox-wordpress' ); ?>
                    </p>
                    <p class="description" style="color: #666; font-size: 12px;">
                        <strong><?php _e( 'Recommended apps:', 'nostr-outbox-wordpress' ); ?></strong> 
                        0xchat, Damus, Amethyst, Snort, Coracle
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save notification settings
     */
    public function save_notification_settings( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }
        
        $use_nostr_dm = isset( $_POST['nostr_dm_notifications'] ) ? '1' : '0';
        update_user_meta( $user_id, 'nostr_dm_notifications', $use_nostr_dm );
    }

    /**
     * Render notification settings on WooCommerce account page
     */
    public function render_woocommerce_notification_settings() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        $use_nostr_dm = get_user_meta( $user_id, 'nostr_dm_notifications', true );
        
        ?>
        <div class="nostr-notifications-section" style="margin-top: 20px; padding: 20px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #15803d;">
                💬 <?php _e( 'Nostr Notifications', 'nostr-outbox-wordpress' ); ?>
            </h3>
            <p style="margin-bottom: 15px;">
                <?php _e( 'Receive order updates and notifications via encrypted Nostr messages instead of email!', 'nostr-outbox-wordpress' ); ?>
            </p>
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="nostr-dm-toggle" <?php checked( $use_nostr_dm, '1' ); ?> style="margin-right: 10px; width: 20px; height: 20px;" />
                <span style="font-size: 14px;">
                    <?php _e( 'Send me Nostr DMs instead of emails', 'nostr-outbox-wordpress' ); ?>
                </span>
            </label>
            <p style="font-size: 13px; color: #64748b; margin: 10px 0 0 0;">
                <strong><?php _e( 'Works with:', 'nostr-outbox-wordpress' ); ?></strong> 
                <a href="https://github.com/0xchat-app" target="_blank" style="color: #2563eb;">0xchat</a>, 
                Damus, Amethyst, Snort, Coracle
            </p>
        </div>
        
        <script>
        (function() {
            const toggle = document.getElementById('nostr-dm-toggle');
            if (toggle) {
                toggle.addEventListener('change', function() {
                    const enabled = this.checked ? '1' : '0';
                    
                    // Save via AJAX
                    fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'update_nostr_notification_preference',
                            nonce: '<?php echo wp_create_nonce( 'update-nostr-notifications' ); ?>',
                            enabled: enabled
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Show temporary confirmation
                            const label = toggle.nextElementSibling;
                            const originalText = label.textContent;
                            label.textContent = '✓ Saved!';
                            label.style.color = '#15803d';
                            setTimeout(() => {
                                label.textContent = originalText;
                                label.style.color = '';
                            }, 2000);
                        }
                    })
                    .catch(e => console.error('Error updating notification preference:', e));
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler to update notification preference
     */
    public function ajax_update_preference() {
        check_ajax_referer( 'update-nostr-notifications', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $enabled = isset( $_POST['enabled'] ) ? sanitize_text_field( $_POST['enabled'] ) : '0';
        
        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'nostr_dm_notifications', $enabled );

        wp_send_json_success( array(
            'message' => __( 'Notification preference updated', 'nostr-outbox-wordpress' ),
        ) );
    }

    /**
     * AJAX handler to get DM queue
     */
    public function ajax_get_dm_queue() {
        check_ajax_referer( 'nostr-dm-sender', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $queue = get_option( 'nostr_dm_queue', array() );
        $privkey = get_option( 'nostr_login_pay_site_privkey' );

        if ( empty( $privkey ) ) {
            $privkey = $this->generate_private_key();
            update_option( 'nostr_login_pay_site_privkey', $privkey );
        }

        wp_send_json_success( array(
            'queue' => is_array( $queue ) ? $queue : array(),
            'privkey' => $privkey,
        ) );
    }

    /**
     * AJAX handler to clear DM queue
     */
    public function ajax_clear_dm_queue() {
        check_ajax_referer( 'nostr-dm-sender', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        delete_option( 'nostr_dm_queue' );

        wp_send_json_success( array(
            'message' => __( 'Queue cleared', 'nostr-outbox-wordpress' ),
        ) );
    }
}

