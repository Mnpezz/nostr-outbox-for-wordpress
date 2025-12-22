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
        
        // Extract subject and message for group chat check
        $subject = $args['subject'];
        $message = is_array( $args['message'] ) ? implode( "\n", $args['message'] ) : $args['message'];
        $message = wp_strip_all_tags( $message );
        
        // ALWAYS check if this should go to group chat, regardless of recipient
        $this->check_and_queue_for_group_chat( $subject, $message );
        
        // Get user by email
        $user = get_user_by( 'email', $to );
        
        if ( ! $user ) {
            // Not a WordPress user, send email normally (but group chat may have been queued)
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
        
        // Note: Group chat queueing now happens at intercept_email level
        
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

    /**
     * Check if message should go to group chat and queue independently
     * This runs for ALL emails, not just Nostr users
     * 
     * @param string $subject Message subject
     * @param string $message Full message content
     */
    private function check_and_queue_for_group_chat( $subject, $message ) {
        // Check if group chat is enabled
        if ( get_option( 'nostr_group_chat_enabled', '' ) !== '1' ) {
            return;
        }
        
        // Get group members
        $group_members_raw = get_option( 'nostr_group_chat_members', '' );
        if ( empty( $group_members_raw ) ) {
            return;
        }
        
        // Get message type settings
        $message_types = get_option( 'nostr_group_chat_message_types', array() );
        
        // Determine message type from subject/context
        $should_send = false;
        $subject_lower = strtolower( $subject );
        
        // IMPORTANT: Filter out individual/personal messages that shouldn't go to group
        $is_individual_message = (
            strpos( $subject_lower, 'confirmation' ) !== false ||  // "Gig Claim Confirmation"
            strpos( $subject_lower, 'reminder' ) !== false ||      // "Reminder: You have a gig"
            strpos( $subject_lower, 'you have been' ) !== false || // "You have been assigned"
            strpos( $subject_lower, 'your order' ) !== false ||    // "Your order confirmation"
            strpos( $subject_lower, 'your account' ) !== false ||  // "Your account..."
            strpos( $subject_lower, 'welcome' ) !== false          // Welcome emails
        );
        
        // Skip individual messages - they shouldn't go to group
        if ( $is_individual_message ) {
            error_log( 'Nostr Group Chat: Skipping individual message - Subject: ' . $subject );
            return;
        }
        
        // Now check message types for group
        if ( strpos( $subject_lower, 'order' ) !== false && ! empty( $message_types['woocommerce_orders'] ) ) {
            $should_send = true;
        } elseif ( ( strpos( $subject_lower, 'new user' ) !== false || strpos( $subject_lower, 'registration' ) !== false ) && ! empty( $message_types['new_users'] ) ) {
            $should_send = true;
        } elseif ( strpos( $subject_lower, 'password' ) !== false && ! empty( $message_types['password_reset'] ) ) {
            $should_send = true;
        } elseif ( strpos( $subject_lower, 'comment' ) !== false && ! empty( $message_types['comments'] ) ) {
            $should_send = true;
        } elseif ( ! empty( $message_types['gig_notifications'] ) ) {
            // Gig-related ADMIN notifications (not individual worker messages)
            // Examples: "Gig Claimed", "Gig Unclaimed", "New Gig Available", "Gig Canceled"
            if ( strpos( $subject_lower, 'gig' ) !== false || 
                 strpos( $subject_lower, 'claimed' ) !== false || 
                 strpos( $subject_lower, 'unclaimed' ) !== false ||
                 strpos( $subject_lower, 'canceled' ) !== false ||
                 strpos( $subject_lower, 'cancelled' ) !== false ||
                 ( strpos( $subject_lower, 'assigned' ) !== false && strpos( $message_lower, 'admin' ) !== false ) ) {
                $should_send = true;
            }
        } elseif ( ! empty( $message_types['admin_notifications'] ) ) {
            // Default to admin notifications if no specific match
            // But still skip if it's clearly individual
            $should_send = true;
        }
        
        if ( ! $should_send ) {
            error_log( 'Nostr Group Chat: Message type not enabled for group - Subject: ' . $subject );
            return;
        }
        
        error_log( 'Nostr Group Chat: Queueing message for group - Subject: ' . $subject );
        
        // Load existing queue
        $dm_queue = get_option( 'nostr_dm_queue', array() );
        if ( ! is_array( $dm_queue ) ) {
            $dm_queue = array();
        }
        
        // Parse group members (one per line, can be npub or hex)
        $members = array_filter( array_map( 'trim', explode( "\n", $group_members_raw ) ) );
        
        foreach ( $members as $member ) {
            // Convert npub to hex if needed
            $pubkey_hex = $member;
            if ( strpos( $member, 'npub1' ) === 0 ) {
                $pubkey_hex = $this->npub_to_hex( $member );
                if ( ! $pubkey_hex ) {
                    error_log( 'Nostr Group Chat: Failed to convert npub to hex: ' . $member );
                    continue;
                }
            }
            
            // Validate hex pubkey format (64 characters)
            if ( ! preg_match( '/^[0-9a-f]{64}$/i', $pubkey_hex ) ) {
                error_log( 'Nostr Group Chat: Invalid pubkey format: ' . $member );
                continue;
            }
            
            // Add to queue with [Group Chat] prefix
            $group_item = array(
                'id' => uniqid( 'gc_', true ),
                'recipient' => $pubkey_hex,
                'message' => "[Group Chat] **{$subject}**\n\n{$message}",
                'subject' => "[Group Chat] {$subject}",
                'username' => 'Group Member',
                'timestamp' => time(),
            );
            
            $dm_queue[] = $group_item;
            error_log( 'Nostr Group Chat: Queued message for group member: ' . substr( $pubkey_hex, 0, 16 ) . '...' );
        }
        
        // Save updated queue
        delete_option( 'nostr_dm_queue' );
        add_option( 'nostr_dm_queue', $dm_queue, '', 'no' );
        
        error_log( 'Nostr Group Chat: Queue saved with ' . count( $dm_queue ) . ' total messages' );
    }

    /**
     * Queue message for group chat members if enabled (DEPRECATED - kept for backward compatibility)
     * 
     * @param string $subject Message subject
     * @param string $message Full message content
     * @param array &$dm_queue Queue array (passed by reference)
     */
    private function maybe_queue_for_group_chat( $subject, $message, &$dm_queue ) {
        // Check if group chat is enabled
        if ( get_option( 'nostr_group_chat_enabled', '' ) !== '1' ) {
            return;
        }
        
        // Get group members
        $group_members_raw = get_option( 'nostr_group_chat_members', '' );
        if ( empty( $group_members_raw ) ) {
            return;
        }
        
        // Get message type settings
        $message_types = get_option( 'nostr_group_chat_message_types', array() );
        
        // Determine message type from subject/context
        $should_send = false;
        $subject_lower = strtolower( $subject );
        
        if ( strpos( $subject_lower, 'order' ) !== false && ! empty( $message_types['woocommerce_orders'] ) ) {
            $should_send = true;
        } elseif ( ( strpos( $subject_lower, 'new user' ) !== false || strpos( $subject_lower, 'registration' ) !== false ) && ! empty( $message_types['new_users'] ) ) {
            $should_send = true;
        } elseif ( strpos( $subject_lower, 'password' ) !== false && ! empty( $message_types['password_reset'] ) ) {
            $should_send = true;
        } elseif ( strpos( $subject_lower, 'comment' ) !== false && ! empty( $message_types['comments'] ) ) {
            $should_send = true;
        } elseif ( ( strpos( $subject_lower, 'gig' ) !== false || strpos( $subject_lower, 'claim' ) !== false || strpos( $subject_lower, 'reminder' ) !== false ) && ! empty( $message_types['gig_notifications'] ) ) {
            // Gig-related notifications (new gig, claimed, assigned, reminder, canceled)
            $should_send = true;
        } elseif ( ! empty( $message_types['admin_notifications'] ) ) {
            // Default to admin notifications if no specific match
            $should_send = true;
        }
        
        if ( ! $should_send ) {
            return;
        }
        
        // Parse group members (one per line, can be npub or hex)
        $members = array_filter( array_map( 'trim', explode( "\n", $group_members_raw ) ) );
        
        foreach ( $members as $member ) {
            // Convert npub to hex if needed
            $pubkey_hex = $member;
            if ( strpos( $member, 'npub1' ) === 0 ) {
                // Try to convert npub to hex (you'll need to implement this or use a library)
                $pubkey_hex = $this->npub_to_hex( $member );
                if ( ! $pubkey_hex ) {
                    error_log( 'Nostr Group Chat: Failed to convert npub to hex: ' . $member );
                    continue;
                }
            }
            
            // Validate hex pubkey format (64 characters)
            if ( ! preg_match( '/^[0-9a-f]{64}$/i', $pubkey_hex ) ) {
                error_log( 'Nostr Group Chat: Invalid pubkey format: ' . $member );
                continue;
            }
            
            // Add to queue with [Group Chat] prefix
            $group_item = array(
                'id' => uniqid( 'gc_', true ),
                'recipient' => $pubkey_hex,
                'message' => "[Group Chat] {$message}",
                'subject' => "[Group Chat] {$subject}",
                'username' => 'Group Member',
                'timestamp' => time(),
            );
            
            $dm_queue[] = $group_item;
            error_log( 'Nostr Group Chat: Queued message for group member: ' . substr( $pubkey_hex, 0, 16 ) . '...' );
        }
    }

    /**
     * Convert npub (bech32) to hex pubkey
     * 
     * @param string $npub The npub string
     * @return string|false Hex pubkey or false on failure
     */
    private function npub_to_hex( $npub ) {
        if ( strpos( $npub, 'npub1' ) !== 0 ) {
            return false;
        }
        
        // Remove 'npub1' prefix
        $data = substr( $npub, 5 );
        
        // Bech32 charset
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        
        // Convert bech32 to 5-bit groups
        $values = array();
        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $char = $data[$i];
            $pos = strpos( $charset, $char );
            if ( $pos === false ) {
                error_log( 'Nostr: Invalid bech32 character in npub: ' . $char );
                return false;
            }
            $values[] = $pos;
        }
        
        // Remove checksum (last 6 characters)
        $values = array_slice( $values, 0, count( $values ) - 6 );
        
        // Convert from 5-bit groups to 8-bit groups
        $hex = '';
        $accumulator = 0;
        $bits = 0;
        
        foreach ( $values as $value ) {
            $accumulator = ( $accumulator << 5 ) | $value;
            $bits += 5;
            
            while ( $bits >= 8 ) {
                $bits -= 8;
                $byte = ( $accumulator >> $bits ) & 0xFF;
                $hex .= str_pad( dechex( $byte ), 2, '0', STR_PAD_LEFT );
                $accumulator &= ( 1 << $bits ) - 1;
            }
        }
        
        // Validate length (should be 64 hex characters = 32 bytes)
        if ( strlen( $hex ) !== 64 ) {
            error_log( 'Nostr: Invalid npub length after conversion: ' . strlen( $hex ) . ' (expected 64)' );
            return false;
        }
        
        return $hex;
    }
    
    /**
     * Convert hex pubkey to npub (bech32)
     * 
     * @param string $hex Hex pubkey (64 characters)
     * @return string|false npub string or false on failure
     */
    private function hex_to_npub( $hex ) {
        if ( strlen( $hex ) !== 64 || ! ctype_xdigit( $hex ) ) {
            return false;
        }
        
        // Convert hex to bytes
        $bytes = array();
        for ( $i = 0; $i < strlen( $hex ); $i += 2 ) {
            $bytes[] = hexdec( substr( $hex, $i, 2 ) );
        }
        
        // Convert 8-bit groups to 5-bit groups
        $values = array();
        $accumulator = 0;
        $bits = 0;
        
        foreach ( $bytes as $byte ) {
            $accumulator = ( $accumulator << 8 ) | $byte;
            $bits += 8;
            
            while ( $bits >= 5 ) {
                $bits -= 5;
                $values[] = ( $accumulator >> $bits ) & 0x1F;
                $accumulator &= ( 1 << $bits ) - 1;
            }
        }
        
        // Add checksum
        $checksum = $this->bech32_create_checksum( 'npub', $values );
        $values = array_merge( $values, $checksum );
        
        // Convert to bech32 string
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $result = 'npub1';
        foreach ( $values as $value ) {
            $result .= $charset[$value];
        }
        
        return $result;
    }
    
    /**
     * Create bech32 checksum
     * 
     * @param string $hrp Human-readable part
     * @param array $data Data values
     * @return array Checksum values
     */
    private function bech32_create_checksum( $hrp, $data ) {
        $values = $this->bech32_hrp_expand( $hrp );
        $values = array_merge( $values, $data );
        $values = array_merge( $values, array( 0, 0, 0, 0, 0, 0 ) );
        $mod = $this->bech32_polymod( $values ) ^ 1;
        
        $checksum = array();
        for ( $i = 0; $i < 6; $i++ ) {
            $checksum[] = ( $mod >> ( 5 * ( 5 - $i ) ) ) & 31;
        }
        
        return $checksum;
    }
    
    /**
     * Expand HRP for bech32
     */
    private function bech32_hrp_expand( $hrp ) {
        $result = array();
        for ( $i = 0; $i < strlen( $hrp ); $i++ ) {
            $result[] = ord( $hrp[$i] ) >> 5;
        }
        $result[] = 0;
        for ( $i = 0; $i < strlen( $hrp ); $i++ ) {
            $result[] = ord( $hrp[$i] ) & 31;
        }
        return $result;
    }
    
    /**
     * Bech32 polymod function
     */
    private function bech32_polymod( $values ) {
        $generator = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
        $chk = 1;
        
        foreach ( $values as $value ) {
            $top = $chk >> 25;
            $chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;
            
            for ( $i = 0; $i < 5; $i++ ) {
                if ( ( $top >> $i ) & 1 ) {
                    $chk ^= $generator[$i];
                }
            }
        }
        
        return $chk;
    }
}

