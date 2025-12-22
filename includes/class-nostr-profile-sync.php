<?php
/**
 * Nostr Profile Sync
 * 
 * Fetches and syncs user profile data from Nostr relays (NIP-01)
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles syncing user profiles from Nostr
 */
class Nostr_Login_Pay_Profile_Sync {

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
        // Add sync button to user profile
        add_action( 'show_user_profile', array( $this, 'render_sync_button' ) );
        add_action( 'edit_user_profile', array( $this, 'render_sync_button' ) );
        
        // Add to WooCommerce account page
        add_action( 'woocommerce_account_dashboard', array( $this, 'render_woocommerce_sync_button' ), 25 );
        
        // AJAX handler for syncing profile
        add_action( 'wp_ajax_sync_nostr_profile', array( $this, 'ajax_sync_profile' ) );
        add_action( 'wp_ajax_update_profile_from_nostr', array( $this, 'ajax_update_profile_from_nostr' ) );
        
        // Enqueue profile sync script
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_profile_sync_script' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_profile_sync_script' ) );
        
        // Auto-sync on login if profile is stale (optional)
        add_action( 'wp_login', array( $this, 'maybe_auto_sync_profile' ), 10, 2 );
        
        // Use Nostr avatar instead of Gravatar
        add_filter( 'get_avatar_url', array( $this, 'use_nostr_avatar' ), 10, 3 );
        add_filter( 'get_avatar', array( $this, 'use_nostr_avatar_html' ), 10, 5 );
    }

    /**
     * Render sync button on user profile
     */
    public function render_sync_button( $user ) {
        $nostr_pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        $last_sync = get_user_meta( $user->ID, 'nostr_profile_last_sync', true );
        $last_sync_display = $last_sync ? human_time_diff( $last_sync, time() ) . ' ago' : 'Never';
        $nostr_avatar = get_user_meta( $user->ID, 'nostr_avatar', true );
        $nostr_name = get_user_meta( $user->ID, 'nostr_display_name', true );
        
        ?>
        <h2><?php _e( 'Nostr Profile Sync', 'nostr-outbox-wordpress' ); ?></h2>
        <table class="form-table">
            <?php if ( ! empty( $nostr_avatar ) || ! empty( $nostr_name ) ) : ?>
            <tr>
                <th><label><?php _e( 'Synced Data', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <?php if ( ! empty( $nostr_avatar ) ) : ?>
                        <p>
                            <strong><?php _e( 'Avatar:', 'nostr-outbox-wordpress' ); ?></strong><br>
                            <img src="<?php echo esc_url( $nostr_avatar ); ?>" alt="Nostr Avatar" style="width: 96px; height: 96px; border-radius: 50%; margin-top: 5px;" />
                        </p>
                    <?php endif; ?>
                    <?php if ( ! empty( $nostr_name ) ) : ?>
                        <p>
                            <strong><?php _e( 'Display Name:', 'nostr-outbox-wordpress' ); ?></strong> <?php echo esc_html( $nostr_name ); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><label><?php _e( 'Profile Status', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <p>
                        <strong><?php _e( 'Last synced:', 'nostr-outbox-wordpress' ); ?></strong> <?php echo esc_html( $last_sync_display ); ?>
                    </p>
                    <button type="button" class="button button-primary" id="sync-nostr-profile-btn">
                        <?php _e( 'Sync Profile from Nostr', 'nostr-outbox-wordpress' ); ?>
                    </button>
                    <span id="sync-status" style="margin-left: 10px; color: #666;"></span>
                    <p class="description" style="margin-top: 10px;">
                        <?php _e( 'Fetch your display name, avatar, and bio from your Nostr profile (kind 0 metadata event).', 'nostr-outbox-wordpress' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <script>
        (function() {
            const btn = document.getElementById('sync-nostr-profile-btn');
            const status = document.getElementById('sync-status');
            
            if (btn) {
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    btn.textContent = '<?php _e( 'Syncing...', 'nostr-outbox-wordpress' ); ?>';
                    status.textContent = '<?php _e( 'Fetching from Nostr relays...', 'nostr-outbox-wordpress' ); ?>';
                    
                    // Use the global sync function
                    if (typeof window.syncNostrProfile !== 'function') {
                        btn.disabled = false;
                        btn.textContent = '<?php _e( 'Sync Profile from Nostr', 'nostr-outbox-wordpress' ); ?>';
                        status.textContent = '✗ Sync script not loaded';
                        status.style.color = '#d00';
                        return;
                    }
                    
                    const pubkey = '<?php echo esc_js( get_user_meta( $user->ID, 'nostr_pubkey', true ) ); ?>';
                    const relays = <?php echo json_encode( $this->get_relays() ); ?>;
                    
                    window.syncNostrProfile(pubkey, '<?php echo $user->ID; ?>', relays)
                        .then(() => {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Sync Profile from Nostr', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✓ Profile synced!';
                            status.style.color = '#0a0';
                            setTimeout(() => location.reload(), 1500);
                        })
                        .catch(e => {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Sync Profile from Nostr', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✗ ' + e.message;
                            status.style.color = '#d00';
                        });
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Render sync button on WooCommerce account page
     */
    public function render_woocommerce_sync_button() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        $last_sync = get_user_meta( $user_id, 'nostr_profile_last_sync', true );
        $last_sync_display = $last_sync ? human_time_diff( $last_sync, time() ) . ' ago' : 'Never';
        
        ?>
        <div class="nostr-profile-sync-section" style="margin-top: 20px; padding: 20px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #92400e;">
                🔄 <?php _e( 'Sync Nostr Profile', 'nostr-outbox-wordpress' ); ?>
            </h3>
            <p style="margin-bottom: 15px; font-size: 13px;">
                <?php _e( 'Update your WordPress profile with your latest Nostr display name, avatar, and bio.', 'nostr-outbox-wordpress' ); ?>
            </p>
            <p style="margin-bottom: 15px; font-size: 12px; color: #78350f;">
                <strong><?php _e( 'Last synced:', 'nostr-outbox-wordpress' ); ?></strong> <?php echo esc_html( $last_sync_display ); ?>
            </p>
            <button type="button" class="button button-primary" id="wc-sync-nostr-profile-btn">
                <?php _e( 'Sync Now', 'nostr-outbox-wordpress' ); ?>
            </button>
            <span id="wc-sync-status" style="margin-left: 10px; color: #666;"></span>
        </div>
        
        <script>
        (function() {
            const btn = document.getElementById('wc-sync-nostr-profile-btn');
            const status = document.getElementById('wc-sync-status');
            
            if (btn) {
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    btn.textContent = '<?php _e( 'Syncing...', 'nostr-outbox-wordpress' ); ?>';
                    status.textContent = '<?php _e( 'Fetching from Nostr relays...', 'nostr-outbox-wordpress' ); ?>';
                    
                    // Use the global sync function
                    if (typeof window.syncNostrProfile !== 'function') {
                        btn.disabled = false;
                        btn.textContent = '<?php _e( 'Sync Now', 'nostr-outbox-wordpress' ); ?>';
                        status.textContent = '✗ Sync script not loaded';
                        status.style.color = '#dc2626';
                        return;
                    }
                    
                    const pubkey = '<?php echo esc_js( get_user_meta( $user_id, 'nostr_pubkey', true ) ); ?>';
                    const relays = <?php echo json_encode( $this->get_relays() ); ?>;
                    
                    window.syncNostrProfile(pubkey, '<?php echo $user_id; ?>', relays)
                        .then(() => {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Sync Now', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✓ Profile synced!';
                            status.style.color = '#15803d';
                            setTimeout(() => location.reload(), 1500);
                        })
                        .catch(e => {
                            btn.disabled = false;
                            btn.textContent = '<?php _e( 'Sync Now', 'nostr-outbox-wordpress' ); ?>';
                            status.textContent = '✗ ' + e.message;
                            status.style.color = '#dc2626';
                        });
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Enqueue profile sync script
     */
    public function enqueue_profile_sync_script() {
        // Only load if user has Nostr pubkey
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );

        if ( empty( $nostr_pubkey ) ) {
            return;
        }

        wp_enqueue_script(
            'nostr-profile-sync',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/nostr-profile-sync.js',
            array( 'nostr-tools' ),
            NOSTR_LOGIN_PAY_VERSION,
            true
        );

        wp_localize_script(
            'nostr-profile-sync',
            'nostrProfileSyncData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'nostr-profile-sync' ),
            )
        );
    }

    /**
     * Get configured relays
     * 
     * @return array Array of relay URLs
     */
    private function get_relays() {
        $relays = get_option( 'nostr_login_pay_relays', array(
            'wss://relay.damus.io',
            'wss://relay.snort.social',
            'wss://nos.lol',
            'wss://relay.nostr.band',
        ) );

        // Ensure it's an array
        if ( ! is_array( $relays ) ) {
            $relays = array(
                'wss://relay.damus.io',
                'wss://relay.snort.social',
                'wss://nos.lol',
                'wss://relay.nostr.band',
            );
        }

        return $relays;
    }

    /**
     * AJAX handler to sync profile
     */
    public function ajax_sync_profile() {
        check_ajax_referer( 'sync-nostr-profile', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : get_current_user_id();
        
        // Security check - users can only sync their own profile unless admin
        if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        if ( empty( $nostr_pubkey ) ) {
            wp_send_json_error( array( 'message' => __( 'No Nostr public key found', 'nostr-outbox-wordpress' ) ) );
        }

        // The actual syncing will be done via JavaScript calling Nostr relays
        // We just mark the timestamp here
        update_user_meta( $user_id, 'nostr_profile_last_sync', time() );

        wp_send_json_success( array(
            'message' => __( 'Profile synced successfully', 'nostr-outbox-wordpress' ),
            'pubkey' => $nostr_pubkey,
        ) );
    }

    /**
     * AJAX handler to update profile from Nostr data
     */
    public function ajax_update_profile_from_nostr() {
        check_ajax_referer( 'nostr-profile-sync', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : get_current_user_id();
        
        // Security check
        if ( $user_id !== get_current_user_id() && ! current_user_can( 'edit_users' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $profile_data = isset( $_POST['profile_data'] ) ? json_decode( stripslashes( $_POST['profile_data'] ), true ) : array();

        if ( empty( $profile_data ) ) {
            wp_send_json_error( array( 'message' => __( 'No profile data provided', 'nostr-outbox-wordpress' ) ) );
        }

        $updated = false;

        // Update display name
        if ( ! empty( $profile_data['name'] ) ) {
            wp_update_user( array(
                'ID' => $user_id,
                'display_name' => sanitize_text_field( $profile_data['name'] ),
            ) );
            update_user_meta( $user_id, 'nostr_display_name', sanitize_text_field( $profile_data['name'] ) );
            
            // Update email to friendly NIP-05 format when name is synced
            $site_domain = parse_url( get_site_url(), PHP_URL_HOST );
            $friendly_name = sanitize_title( $profile_data['name'] ); // Convert to safe email format
            $new_email = $friendly_name . '@' . $site_domain;
            
            // Check if email already exists (for another user)
            $existing_user = email_exists( $new_email );
            if ( ! $existing_user || $existing_user == $user_id ) {
                wp_update_user( array(
                    'ID' => $user_id,
                    'user_email' => $new_email,
                ) );
                
                // Also update WooCommerce billing email if exists
                if ( class_exists( 'WooCommerce' ) ) {
                    update_user_meta( $user_id, 'billing_email', $new_email );
                }
                
                error_log( 'Nostr Profile Sync: Updated email for user ' . $user_id . ' to ' . $new_email );
            } else {
                error_log( 'Nostr Profile Sync: Email ' . $new_email . ' already exists for another user, skipping email update' );
            }
            
            $updated = true;
        }

        // Update bio/description
        if ( ! empty( $profile_data['about'] ) ) {
            update_user_meta( $user_id, 'description', sanitize_textarea_field( $profile_data['about'] ) );
            $updated = true;
        }

        // Update avatar URL
        if ( ! empty( $profile_data['picture'] ) ) {
            update_user_meta( $user_id, 'nostr_avatar', esc_url_raw( $profile_data['picture'] ) );
            $updated = true;
        }

        // Update NIP-05
        if ( ! empty( $profile_data['nip05'] ) ) {
            update_user_meta( $user_id, 'nostr_nip05', sanitize_text_field( $profile_data['nip05'] ) );
            $updated = true;
        }

        // Mark last sync time
        update_user_meta( $user_id, 'nostr_profile_last_sync', time() );

        if ( $updated ) {
            wp_send_json_success( array(
                'message' => __( 'Profile updated successfully', 'nostr-outbox-wordpress' ),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'No profile data to update', 'nostr-outbox-wordpress' ) ) );
        }
    }

    /**
     * Maybe auto-sync profile on login
     * 
     * @param string $user_login Username
     * @param WP_User $user User object
     */
    public function maybe_auto_sync_profile( $user_login, $user ) {
        $last_sync = get_user_meta( $user->ID, 'nostr_profile_last_sync', true );
        
        // Auto-sync if never synced or synced more than 7 days ago
        if ( empty( $last_sync ) || ( time() - $last_sync ) > ( 7 * DAY_IN_SECONDS ) ) {
            // Queue profile sync (will be picked up by JavaScript)
            set_transient( 'nostr_profile_sync_needed_' . $user->ID, true, 300 );
        }
    }

    /**
     * Use Nostr avatar URL instead of Gravatar
     * 
     * @param string $url Avatar URL
     * @param mixed $id_or_email User ID, email, or WP_User object
     * @param array $args Arguments
     * @return string Modified avatar URL
     */
    public function use_nostr_avatar( $url, $id_or_email, $args ) {
        // Get user ID
        $user_id = false;
        
        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) {
                $user_id = $user->ID;
            }
        } elseif ( $id_or_email instanceof WP_User ) {
            $user_id = $id_or_email->ID;
        } elseif ( $id_or_email instanceof WP_Post ) {
            $user_id = (int) $id_or_email->post_author;
        } elseif ( $id_or_email instanceof WP_Comment ) {
            $user_id = (int) $id_or_email->user_id;
        }
        
        if ( ! $user_id ) {
            return $url;
        }
        
        // Check if user has Nostr avatar
        $nostr_avatar = get_user_meta( $user_id, 'nostr_avatar', true );
        
        if ( ! empty( $nostr_avatar ) && filter_var( $nostr_avatar, FILTER_VALIDATE_URL ) ) {
            return $nostr_avatar;
        }
        
        return $url;
    }

    /**
     * Use Nostr avatar in HTML output
     * 
     * @param string $avatar Avatar HTML
     * @param mixed $id_or_email User ID, email, or WP_User object
     * @param int $size Avatar size
     * @param string $default Default avatar
     * @param string $alt Alt text
     * @return string Modified avatar HTML
     */
    public function use_nostr_avatar_html( $avatar, $id_or_email, $size, $default, $alt ) {
        // Get user ID
        $user_id = false;
        
        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) {
                $user_id = $user->ID;
            }
        } elseif ( $id_or_email instanceof WP_User ) {
            $user_id = $id_or_email->ID;
        }
        
        if ( ! $user_id ) {
            return $avatar;
        }
        
        // Check if user has Nostr avatar
        $nostr_avatar = get_user_meta( $user_id, 'nostr_avatar', true );
        
        if ( ! empty( $nostr_avatar ) && filter_var( $nostr_avatar, FILTER_VALIDATE_URL ) ) {
            $avatar = sprintf(
                '<img alt="%s" src="%s" class="avatar avatar-%d photo nostr-avatar" height="%d" width="%d" loading="lazy" decoding="async" />',
                esc_attr( $alt ),
                esc_url( $nostr_avatar ),
                (int) $size,
                (int) $size,
                (int) $size
            );
        }
        
        return $avatar;
    }
}

