<?php
/**
 * Zap Rewards Admin Interface
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nostr_Outbox_Zap_Rewards_Admin {
    private static $instance = null;
    
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // AJAX handlers
        add_action( 'wp_ajax_test_zap_rewards_nwc', array( $this, 'ajax_test_nwc' ) );
        add_action( 'wp_ajax_retry_zap_payment', array( $this, 'ajax_retry_payment' ) );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Enable/disable rewards
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_enable_comments' );
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_enable_reviews' );
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_enable_purchases' );
        
        // Reward amounts
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_comment_amount' );
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_review_amount' );
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_purchase_percentage' );
        
        // Anti-spam
        register_setting( 'nostr-outbox-wordpress-zap', 'nostr_zap_rewards_daily_limit' );
    }

    /**
     * Render Zap Rewards tab
     */
    public function render_zap_tab() {
        $active_subtab = isset( $_GET['zaptab'] ) ? sanitize_text_field( $_GET['zaptab'] ) : 'settings';
        
        // Sub-tab navigation
        ?>
        <div class="dm-tabs">
            <a href="?page=nostr-outbox-wordpress&tab=zap&zaptab=settings" 
               class="dm-tab <?php echo $active_subtab === 'settings' ? 'active' : ''; ?>">
                ⚙️ <?php _e( 'Settings', 'nostr-outbox-wordpress' ); ?>
            </a>
            <a href="?page=nostr-outbox-wordpress&tab=zap&zaptab=pending" 
               class="dm-tab <?php echo $active_subtab === 'pending' ? 'active' : ''; ?>">
                ⏳ <?php _e( 'Pending Rewards', 'nostr-outbox-wordpress' ); ?>
            </a>
            <a href="?page=nostr-outbox-wordpress&tab=zap&zaptab=history" 
               class="dm-tab <?php echo $active_subtab === 'history' ? 'active' : ''; ?>">
                📜 <?php _e( 'History', 'nostr-outbox-wordpress' ); ?>
            </a>
        </div>

        <div class="dm-tab-content">
            <?php
            switch ( $active_subtab ) {
                case 'settings':
                    $this->render_settings_tab();
                    break;
                case 'pending':
                    $this->render_pending_tab();
                    break;
                case 'history':
                    $this->render_history_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab() {
        // Handle form submission
        if ( isset( $_POST['save_zap_settings'] ) && check_admin_referer( 'nostr_zap_settings', 'nostr_zap_nonce' ) ) {
            update_option( 'nostr_zap_rewards_enable_comments', isset( $_POST['enable_comments'] ) ? '1' : '' );
            update_option( 'nostr_zap_rewards_enable_reviews', isset( $_POST['enable_reviews'] ) ? '1' : '' );
            update_option( 'nostr_zap_rewards_enable_purchases', isset( $_POST['enable_purchases'] ) ? '1' : '' );
            
            update_option( 'nostr_zap_rewards_comment_amount', intval( $_POST['comment_amount'] ) );
            update_option( 'nostr_zap_rewards_review_amount', intval( $_POST['review_amount'] ) );
            update_option( 'nostr_zap_rewards_purchase_percentage', floatval( $_POST['purchase_percentage'] ) );
            update_option( 'nostr_zap_rewards_min_purchase_amount', floatval( $_POST['min_purchase_amount'] ) );
            
            update_option( 'nostr_zap_rewards_daily_limit', intval( $_POST['daily_limit'] ) );
            
            // Save Coinos API token if provided
            if ( isset( $_POST['coinos_api_token'] ) ) {
                update_option( 'nostr_zap_rewards_coinos_api_token', sanitize_text_field( $_POST['coinos_api_token'] ) );
            }
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        // Get current values
        $enable_comments = get_option( 'nostr_zap_rewards_enable_comments', '' );
        $enable_reviews = get_option( 'nostr_zap_rewards_enable_reviews', '' );
        $enable_purchases = get_option( 'nostr_zap_rewards_enable_purchases', '' );
        
        $comment_amount = get_option( 'nostr_zap_rewards_comment_amount', 100 );
        $review_amount = get_option( 'nostr_zap_rewards_review_amount', 500 );
        $purchase_percentage = get_option( 'nostr_zap_rewards_purchase_percentage', 1 );
        $min_purchase_amount = get_option( 'nostr_zap_rewards_min_purchase_amount', 10 );
        
        $daily_limit = get_option( 'nostr_zap_rewards_daily_limit', 5 );
        
        // Get stats
        global $wpdb;
        $total_rewards = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zap_rewards" );
        $today_rewards = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}zap_rewards WHERE DATE(created_at) = CURDATE()" );
        $total_sats = $wpdb->get_var( "SELECT SUM(amount) FROM {$wpdb->prefix}zap_rewards WHERE status = 'completed'" );
        
        ?>
        <div style="max-width: 900px; margin-top: 20px;">
            <h2><?php _e( '⚡ Zap Rewards Settings', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php _e( 'Reward your users with Bitcoin Lightning payments for engagement!', 'nostr-outbox-wordpress' ); ?></p>
            
            <!-- Quick Stats -->
            <div style="background: #fff; border: 1px solid #ccc; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0;">📊 <?php _e( 'Quick Stats', 'nostr-outbox-wordpress' ); ?></h3>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #f7931a;"><?php echo number_format( $total_rewards ); ?></div>
                        <div style="color: #666;"><?php _e( 'Total Rewards', 'nostr-outbox-wordpress' ); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #46b450;"><?php echo number_format( $today_rewards ); ?></div>
                        <div style="color: #666;"><?php _e( 'Rewards Today', 'nostr-outbox-wordpress' ); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #2196f3;"><?php echo number_format( $total_sats ?: 0 ); ?>⚡</div>
                        <div style="color: #666;"><?php _e( 'Total Sats Paid', 'nostr-outbox-wordpress' ); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Method Status -->
            <?php
            $coinos_token = get_option( 'nostr_zap_rewards_coinos_api_token', '' );
            $nwc_connection = get_option( 'nostr_login_pay_nwc_merchant_wallet', '' );
            $has_coinos = ! empty( $coinos_token );
            $has_nwc = ! empty( $nwc_connection );
            $is_connected = $has_coinos || $has_nwc;
            ?>
            <div style="background: <?php echo $is_connected ? '#f0fdf4' : '#fef2f2'; ?>; border-left: 4px solid <?php echo $is_connected ? '#46b450' : '#d63638'; ?>; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                <h3 style="margin-top: 0;">💳 <?php _e( 'Payment Method', 'nostr-outbox-wordpress' ); ?></h3>
                <?php if ( $has_coinos ) : ?>
                    <p style="margin: 0;"><strong style="color: #46b450;">✓ Coinos API</strong> <?php _e( '(Primary - Recommended)', 'nostr-outbox-wordpress' ); ?></p>
                    <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">
                        <?php _e( 'Token: ', 'nostr-outbox-wordpress' ); ?><code><?php echo esc_html( substr( $coinos_token, 0, 10 ) . '...' . substr( $coinos_token, -4 ) ); ?></code>
                    </p>
                <?php elseif ( $has_nwc ) : ?>
                    <p style="margin: 0;"><strong style="color: #f0ad4e;">⚠️ NWC Only</strong> <?php _e( '(Experimental - BIP-340 signing issues)', 'nostr-outbox-wordpress' ); ?></p>
                    <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">
                        <?php _e( 'Using NWC from', 'nostr-outbox-wordpress' ); ?> 
                        <a href="?page=nostr-outbox-wordpress&tab=nwc"><?php _e( 'NWC Settings', 'nostr-outbox-wordpress' ); ?></a>
                    </p>
                    <p style="margin: 5px 0 0 0; font-size: 13px; color: #d63638;">
                        <strong><?php _e( 'Recommended:', 'nostr-outbox-wordpress' ); ?></strong> <?php _e( 'Add Coinos API token below for reliable payments', 'nostr-outbox-wordpress' ); ?>
                    </p>
                <?php else : ?>
                    <p style="margin: 0;"><strong style="color: #d63638;">✗ Not Configured</strong></p>
                    <p style="margin: 10px 0 0 0;">
                        <?php _e( 'Add your Coinos API token below OR configure NWC in', 'nostr-outbox-wordpress' ); ?> 
                        <a href="?page=nostr-outbox-wordpress&tab=nwc"><?php _e( 'NWC Settings', 'nostr-outbox-wordpress' ); ?></a>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Settings Form -->
            <form method="post" style="background: #fff; padding: 25px; border: 1px solid #ccc; border-radius: 4px;">
                <?php wp_nonce_field( 'nostr_zap_settings', 'nostr_zap_nonce' ); ?>
                
                <h3><?php _e( 'Payment Configuration', 'nostr-outbox-wordpress' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e( 'Coinos API Token', 'nostr-outbox-wordpress' ); ?></th>
                        <td>
                            <div style="position: relative; max-width: 500px;">
                                <input type="password" id="coinos_api_token" name="coinos_api_token" value="<?php echo esc_attr( get_option( 'nostr_zap_rewards_coinos_api_token', '' ) ); ?>" style="width: 100%; padding-right: 45px;" placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...">
                                <button type="button" id="toggle_api_token" class="button" style="position: absolute; right: 5px; top: 1px; padding: 3px 10px;">
                                    👁️ <?php _e( 'Show', 'nostr-outbox-wordpress' ); ?>
                                </button>
                            </div>
                            <p class="description">
                                <strong><?php _e( '✅ Recommended:', 'nostr-outbox-wordpress' ); ?></strong> 
                                <?php _e( 'Get your API token from', 'nostr-outbox-wordpress' ); ?> 
                                <a href="https://coinos.io/docs" target="_blank">coinos.io/docs</a>.
                                <?php _e( 'This is the most reliable method for sending Lightning payments.', 'nostr-outbox-wordpress' ); ?>
                            </p>
                            <p class="description" style="margin-top: 5px;">
                                <strong><?php _e( 'Alternative:', 'nostr-outbox-wordpress' ); ?></strong>
                                <?php _e( 'Configure', 'nostr-outbox-wordpress' ); ?> 
                                <a href="?page=nostr-outbox-wordpress&tab=nwc"><?php _e( 'NWC (Nostr Wallet Connect)', 'nostr-outbox-wordpress' ); ?></a>
                                <?php _e( ' - experimental, may have signature issues.', 'nostr-outbox-wordpress' ); ?>
                            </p>
                            <script>
                            jQuery(document).ready(function($) {
                                $('#toggle_api_token').on('click', function() {
                                    var input = $('#coinos_api_token');
                                    var button = $(this);
                                    if (input.attr('type') === 'password') {
                                        input.attr('type', 'text');
                                        button.html('🙈 <?php _e( 'Hide', 'nostr-outbox-wordpress' ); ?>');
                                    } else {
                                        input.attr('type', 'password');
                                        button.html('👁️ <?php _e( 'Show', 'nostr-outbox-wordpress' ); ?>');
                                    }
                                });
                            });
                            </script>
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e( 'Reward Configuration', 'nostr-outbox-wordpress' ); ?></h3>
                <p style="color: #666; font-size: 14px;"><?php _e( '💡 Tip: 100 sats ≈ $0.10 USD (varies with Bitcoin price)', 'nostr-outbox-wordpress' ); ?></p>
                
                <table class="form-table">
                    <!-- Comment Rewards -->
                    <tr>
                        <th scope="row"><?php _e( 'Comment Rewards', 'nostr-outbox-wordpress' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="enable_comments" value="1" <?php checked( $enable_comments, '1' ); ?>>
                                    <?php _e( 'Enable comment rewards', 'nostr-outbox-wordpress' ); ?>
                                </label>
                                <br><br>
                                <label>
                                    <?php _e( 'Reward amount:', 'nostr-outbox-wordpress' ); ?>
                                    <input type="number" name="comment_amount" value="<?php echo esc_attr( $comment_amount ); ?>" min="1" step="1"> sats
                                </label>
                                <p class="description"><?php _e( 'Amount of satoshis rewarded for each approved comment', 'nostr-outbox-wordpress' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <!-- Review Rewards -->
                    <tr>
                        <th scope="row"><?php _e( 'Product Review Rewards', 'nostr-outbox-wordpress' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="enable_reviews" value="1" <?php checked( $enable_reviews, '1' ); ?>>
                                    <?php _e( 'Enable product review rewards', 'nostr-outbox-wordpress' ); ?>
                                </label>
                                <br><br>
                                <label>
                                    <?php _e( 'Reward amount:', 'nostr-outbox-wordpress' ); ?>
                                    <input type="number" name="review_amount" value="<?php echo esc_attr( $review_amount ); ?>" min="1" step="1"> sats
                                </label>
                                <p class="description"><?php _e( 'Amount of satoshis rewarded for each product review', 'nostr-outbox-wordpress' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <!-- Purchase Rewards -->
                    <tr>
                        <th scope="row"><?php _e( 'Purchase Rewards', 'nostr-outbox-wordpress' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="enable_purchases" value="1" <?php checked( $enable_purchases, '1' ); ?>>
                                    <?php _e( 'Enable purchase cashback rewards', 'nostr-outbox-wordpress' ); ?>
                                </label>
                                <br><br>
                                <label>
                                    <?php _e( 'Cashback percentage:', 'nostr-outbox-wordpress' ); ?>
                                    <input type="number" name="purchase_percentage" value="<?php echo esc_attr( $purchase_percentage ); ?>" min="0" max="100" step="0.01">%
                                </label>
                                <p class="description"><?php _e( 'Percentage of purchase amount to reward in satoshis', 'nostr-outbox-wordpress' ); ?></p>
                                <br>
                                <label>
                                    <?php _e( 'Minimum purchase amount for cashback:', 'nostr-outbox-wordpress' ); ?>
                                    <input type="number" name="min_purchase_amount" value="<?php echo esc_attr( $min_purchase_amount ); ?>" min="0" step="0.01"> <?php echo get_woocommerce_currency(); ?>
                                </label>
                                <p class="description">
                                    <?php _e( 'Orders below this amount will not earn cashback rewards. Prevents abuse on small orders.', 'nostr-outbox-wordpress' ); ?>
                                    <?php _e( 'Cash and check payments are automatically excluded from cashback.', 'nostr-outbox-wordpress' ); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <!-- Daily Limit -->
                    <tr>
                        <th scope="row"><?php _e( 'Anti-Spam Protection', 'nostr-outbox-wordpress' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <?php _e( 'Daily reward limit per user:', 'nostr-outbox-wordpress' ); ?>
                                    <input type="number" name="daily_limit" value="<?php echo esc_attr( $daily_limit ); ?>" min="1" step="1">
                                </label>
                                <p class="description"><?php _e( 'Maximum number of rewards a user can earn per day (comments and reviews)', 'nostr-outbox-wordpress' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="save_zap_settings" class="button button-primary">
                        <?php _e( 'Save Settings', 'nostr-outbox-wordpress' ); ?>
                    </button>
                </p>
            </form>
            
            <!-- How It Works -->
            <div style="background: #f0f9ff; border-left: 4px solid #2196f3; padding: 20px; margin-top: 20px; border-radius: 4px;">
                <h3 style="margin-top: 0;">❓ <?php _e( 'How It Works', 'nostr-outbox-wordpress' ); ?></h3>
                <ol style="margin: 0; padding-left: 20px;">
                    <li><?php _e( 'Users set their Lightning receiving address in "My Account → ⚡ Zap Rewards"', 'nostr-outbox-wordpress' ); ?></li>
                    <li><?php _e( 'They can enter: Coinos username, Lightning address (user@domain), or invoice', 'nostr-outbox-wordpress' ); ?></li>
                    <li><?php _e( 'When they earn rewards, payments are sent automatically via your NWC wallet', 'nostr-outbox-wordpress' ); ?></li>
                    <li><?php _e( 'Users see their reward history in their account dashboard', 'nostr-outbox-wordpress' ); ?></li>
                </ol>
            </div>
        </div>
        <?php
    }

    /**
     * Render pending rewards tab
     */
    private function render_pending_tab() {
        global $wpdb;
        
        $pending_rewards = $wpdb->get_results(
            "SELECT r.*, u.user_email, u.display_name
             FROM {$wpdb->prefix}zap_rewards r 
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.status IN ('pending', 'awaiting_approval', 'failed') 
             ORDER BY 
                CASE 
                    WHEN r.status = 'awaiting_approval' THEN 1
                    WHEN r.status = 'pending' THEN 2
                    WHEN r.status = 'failed' THEN 3
                END,
                r.created_at DESC"
        );
        
        ?>
        <div style="margin-top: 20px;">
            <h2><?php _e( '⏳ Pending Zap Rewards', 'nostr-outbox-wordpress' ); ?></h2>
            
            <?php if ( $pending_rewards ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th><?php _e( 'User', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Amount', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Type', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Address', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Status', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Date', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Actions', 'nostr-outbox-wordpress' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $pending_rewards as $reward ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $reward->display_name ); ?></strong><br>
                                    <small><?php echo esc_html( $reward->user_email ); ?></small>
                                </td>
                                <td><?php echo esc_html( $reward->amount ); ?> sats</td>
                                <td><?php echo esc_html( ucfirst( $reward->reward_type ) ); ?></td>
                                <td><code style="font-size: 11px;"><?php echo esc_html( substr( $reward->zap_address, 0, 30 ) ); ?>...</code></td>
                                <td>
                                    <?php if ( $reward->status === 'awaiting_approval' ) : ?>
                                        <span style="color: #2196f3; font-weight: bold;">⏳ <?php _e( 'Awaiting Approval', 'nostr-outbox-wordpress' ); ?></span>
                                    <?php elseif ( $reward->status === 'pending' ) : ?>
                                        <span style="color: #f0b849;">⏳ <?php _e( 'Processing', 'nostr-outbox-wordpress' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #d63638;">✗ <?php _e( 'Failed', 'nostr-outbox-wordpress' ); ?></span>
                                        <?php if ( ! empty( $reward->error_message ) ) : ?>
                                            <br><small><?php echo esc_html( $reward->error_message ); ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $reward->created_at ) ) ); ?></td>
                                <td>
                                    <?php if ( $reward->status === 'failed' || $reward->status === 'pending' ) : ?>
                                        <button type="button" class="button retry-zap-payment" data-reward-id="<?php echo esc_attr( $reward->id ); ?>">
                                            <?php echo $reward->status === 'failed' ? __( 'Retry', 'nostr-outbox-wordpress' ) : __( 'Force Retry', 'nostr-outbox-wordpress' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script>
                jQuery(document).ready(function($) {
                    $('.retry-zap-payment').on('click', function() {
                        const button = $(this);
                        const rewardId = button.data('reward-id');
                        
                        button.prop('disabled', true).text('<?php _e( 'Retrying...', 'nostr-outbox-wordpress' ); ?>');
                        
                        $.post(ajaxurl, {
                            action: 'retry_zap_payment',
                            reward_id: rewardId,
                            nonce: '<?php echo wp_create_nonce( 'retry-zap-payment' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                alert('<?php _e( 'Payment sent successfully!', 'nostr-outbox-wordpress' ); ?>');
                                location.reload();
                            } else {
                                alert('<?php _e( 'Payment failed:', 'nostr-outbox-wordpress' ); ?> ' + response.data);
                                button.prop('disabled', false).text('<?php _e( 'Retry', 'nostr-outbox-wordpress' ); ?>');
                            }
                        });
                    });
                });
                </script>
            <?php else : ?>
                <p><?php _e( 'No pending rewards found.', 'nostr-outbox-wordpress' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render history tab
     */
    private function render_history_tab() {
        global $wpdb;
        
        $limit = 100;
        $rewards = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, u.user_email, u.display_name
             FROM {$wpdb->prefix}zap_rewards r 
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.status = 'completed'
             ORDER BY r.created_at DESC
             LIMIT %d",
            $limit
        ) );
        
        ?>
        <div style="margin-top: 20px;">
            <h2><?php _e( '📜 Reward History', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php printf( __( 'Showing last %d completed rewards', 'nostr-outbox-wordpress' ), $limit ); ?></p>
            
            <?php if ( $rewards ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th><?php _e( 'Date', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'User', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Type', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Amount', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Preimage', 'nostr-outbox-wordpress' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rewards as $reward ) : ?>
                            <tr>
                                <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $reward->created_at ) ) ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( $reward->display_name ); ?></strong><br>
                                    <small><?php echo esc_html( $reward->user_email ); ?></small>
                                </td>
                                <td><?php echo esc_html( ucfirst( $reward->reward_type ) ); ?></td>
                                <td><strong><?php echo esc_html( $reward->amount ); ?> sats</strong></td>
                                <td><code style="font-size: 11px;"><?php echo esc_html( substr( $reward->block_hash, 0, 16 ) ); ?>...</code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php _e( 'No rewards sent yet.', 'nostr-outbox-wordpress' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Test NWC connection
     */
    public function ajax_test_nwc() {
        check_ajax_referer( 'test-zap-nwc', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        // Processor class should already be loaded by main plugin
        $processor = new Nostr_Outbox_Zap_Rewards_Processor();
        $result = $processor->test_connection();
        
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    /**
     * AJAX: Retry payment
     */
    public function ajax_retry_payment() {
        error_log( 'Zap Rewards Admin: ajax_retry_payment called' );
        
        try {
            check_ajax_referer( 'retry-zap-payment', 'nonce' );
            
            if ( ! current_user_can( 'manage_options' ) ) {
                error_log( 'Zap Rewards Admin: Permission denied' );
                wp_send_json_error( 'Permission denied' );
            }
            
            $reward_id = isset( $_POST['reward_id'] ) ? intval( $_POST['reward_id'] ) : 0;
            error_log( 'Zap Rewards Admin: Retry payment for reward ID: ' . $reward_id );
            
            if ( ! $reward_id ) {
                error_log( 'Zap Rewards Admin: Invalid reward ID' );
                wp_send_json_error( 'Invalid reward ID' );
            }
            
            // Check if processor class exists
            if ( ! class_exists( 'Nostr_Outbox_Zap_Rewards_Processor' ) ) {
                error_log( 'Zap Rewards Admin: Processor class not found!' );
                wp_send_json_error( 'Zap Rewards processor not loaded' );
            }
            
            error_log( 'Zap Rewards Admin: Creating processor instance...' );
            $processor = new Nostr_Outbox_Zap_Rewards_Processor();
            
            error_log( 'Zap Rewards Admin: Calling retry_payment...' );
            $result = $processor->retry_payment( $reward_id );
            
            error_log( 'Zap Rewards Admin: Result: ' . print_r( $result, true ) );
            
            if ( $result['success'] ) {
                wp_send_json_success( 'Payment sent successfully' );
            } else {
                wp_send_json_error( $result['error'] );
            }
        } catch ( Exception $e ) {
            error_log( 'Zap Rewards Admin: Exception in ajax_retry_payment: ' . $e->getMessage() );
            error_log( 'Zap Rewards Admin: Stack trace: ' . $e->getTraceAsString() );
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }
}

