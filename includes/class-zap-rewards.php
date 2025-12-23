<?php
/**
 * Zap Rewards - Reward users with Bitcoin Lightning for engagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nostr_Outbox_Zap_Rewards {
    private static $instance = null;
    
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // Comment rewards - hook into both post and approval
        add_action( 'comment_post', array( $this, 'reward_comment' ), 10, 2 );
        add_action( 'transition_comment_status', array( $this, 'reward_comment_on_approval' ), 10, 3 );
        
        // WooCommerce integration
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_review_order_before_submit', array( $this, 'add_zap_address_field' ) );
            add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_zap_address' ) );
            add_action( 'woocommerce_order_status_completed', array( $this, 'process_order_reward' ) );
            add_action( 'woocommerce_payment_complete', array( $this, 'process_order_reward' ) );
            
            // My Account endpoint
            add_action( 'init', array( $this, 'add_rewards_endpoint' ) );
            add_filter( 'woocommerce_account_menu_items', array( $this, 'add_rewards_menu_item' ) );
            add_action( 'woocommerce_account_zap-rewards_endpoint', array( $this, 'rewards_content' ) );
        }
        
        // AJAX handlers
        add_action( 'wp_ajax_update_lightning_address', array( $this, 'ajax_update_lightning_address' ) );
    }

    public function init() {
        $this->create_tables();
    }

    /**
     * Create database table for rewards
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}zap_rewards (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            zap_address varchar(255),
            reward_type varchar(20),
            amount bigint(20),
            status varchar(20),
            block_hash varchar(500),
            error_message text,
            comment_id bigint(20),
            order_id bigint(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY comment_id (comment_id),
            KEY order_id (order_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'nostr-zap-rewards',
            plugins_url( 'assets/js/zap-rewards.js', dirname( __FILE__ ) ),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_enqueue_style(
            'nostr-zap-rewards',
            plugins_url( 'assets/css/zap-rewards.css', dirname( __FILE__ ) ),
            array(),
            '1.0.0'
        );
        
        // Pass data to JavaScript
        $user_id = get_current_user_id();
        $has_address = false;
        $comment_amount = 0;
        $review_amount = 0;
        $reward_message = null;
        
        if ( $user_id ) {
            $zap_address = get_user_meta( $user_id, 'zap_address', true );
            $has_address = ! empty( $zap_address );
            $comment_amount = get_option( 'nostr_zap_rewards_comment_amount', 0 );
            $review_amount = get_option( 'nostr_zap_rewards_review_amount', 0 );
            
            // Check for pending messages
            if ( $success = get_transient( 'zap_rewards_success_' . $user_id ) ) {
                $reward_message = $success;
                delete_transient( 'zap_rewards_success_' . $user_id );
            } elseif ( $limit = get_transient( 'zap_rewards_limit_' . $user_id ) ) {
                $reward_message = $limit;
                delete_transient( 'zap_rewards_limit_' . $user_id );
            } elseif ( $error = get_transient( 'zap_rewards_error_' . $user_id ) ) {
                $reward_message = $error;
                delete_transient( 'zap_rewards_error_' . $user_id );
            }
        }
        
        // Check if comments need manual approval
        $comment_moderation = get_option( 'comment_moderation', 0 );
        $comment_previously_approved = get_option( 'comment_previously_approved', 1 );
        $comments_need_approval = ( $comment_moderation == 1 ) || ( $comment_previously_approved == 0 );
        
        wp_localize_script( 'nostr-zap-rewards', 'zapRewardsData', array(
            'hasAddress' => $has_address,
            'commentAmount' => $comment_amount,
            'reviewAmount' => $review_amount,
            'commentsEnabled' => get_option( 'nostr_zap_rewards_enable_comments' ),
            'reviewsEnabled' => get_option( 'nostr_zap_rewards_enable_reviews' ),
            'rewardMessage' => $reward_message,
            'needsApproval' => $comments_need_approval,
        ) );
    }

    /**
     * WooCommerce: Add rewards endpoint
     */
    public function add_rewards_endpoint() {
        add_rewrite_endpoint( 'zap-rewards', EP_ROOT | EP_PAGES );
    }

    /**
     * WooCommerce: Add rewards to account menu
     */
    public function add_rewards_menu_item( $items ) {
        $items['zap-rewards'] = '⚡ Zap Rewards';
        return $items;
    }

    /**
     * WooCommerce: Rewards page content
     */
    public function rewards_content() {
        $user_id = get_current_user_id();
        global $wpdb;
        
        $rewards = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}zap_rewards 
             WHERE user_id = %d 
             ORDER BY created_at DESC",
            $user_id
        ) );
        
        // Handle form submission
        if ( isset( $_POST['lightning_address_nonce'] ) && 
            wp_verify_nonce( $_POST['lightning_address_nonce'], 'update_lightning_address' ) ) {
            $lightning_address = sanitize_text_field( $_POST['lightning_address'] );
            update_user_meta( $user_id, 'zap_address', $lightning_address );
            echo '<div class="woocommerce-message">Lightning address updated successfully!</div>';
        }
        
        require plugin_dir_path( __FILE__ ) . '../templates/rewards-page.php';
    }

    /**
     * Handle comment reward
     */
    public function reward_comment( $comment_id, $comment_approved ) {
        error_log( "Zap Rewards: reward_comment() called - Comment ID: $comment_id, Approved: $comment_approved" );
        
        if ( ! get_option( 'nostr_zap_rewards_enable_comments' ) ) {
            return;
        }

        if ( $comment_approved === 1 ) {
            $this->process_comment_reward( $comment_id );
        } else {
            $this->create_pending_reward( $comment_id );
        }
    }

    /**
     * Reward comment when approved
     */
    public function reward_comment_on_approval( $new_status, $old_status, $comment ) {
        if ( $new_status !== 'approved' || $old_status === 'approved' ) {
            return;
        }
        
        global $wpdb;
        $existing_reward_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}zap_rewards 
             WHERE comment_id = %d 
             AND status = 'awaiting_approval'",
            $comment->comment_ID
        ) );
        
        if ( $existing_reward_id ) {
            $this->process_pending_comment_reward( $comment->comment_ID, $existing_reward_id );
        } else {
            $this->process_comment_reward( $comment->comment_ID );
        }
    }

    /**
     * Create pending reward for unapproved comment
     */
    private function create_pending_reward( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( ! $comment || $comment->user_id === 0 ) {
            return;
        }
        
        $user_id = $comment->user_id;
        $zap_address = get_user_meta( $user_id, 'zap_address', true );
        
        if ( empty( $zap_address ) ) {
            return;
        }
        
        // Determine if review or comment
        $is_review = false;
        $post_id = $comment->comment_post_ID;
        $post_type = get_post_type( $post_id );
        
        if ( $post_type === 'product' ) {
            $rating = get_comment_meta( $comment_id, 'rating', true );
            if ( ! empty( $rating ) ) {
                $is_review = true;
            }
        }
        
        $reward_type = $is_review ? 'review' : 'comment';
        $reward_amount = $is_review 
            ? get_option( 'nostr_zap_rewards_review_amount', 0 )
            : get_option( 'nostr_zap_rewards_comment_amount', 0 );
        
        if ( $reward_amount <= 0 ) {
            return;
        }
        
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'zap_rewards',
            array(
                'user_id' => $user_id,
                'zap_address' => $zap_address,
                'reward_type' => $reward_type,
                'amount' => $reward_amount,
                'status' => 'awaiting_approval',
                'comment_id' => $comment_id,
            )
        );
    }

    /**
     * Process pending comment reward
     */
    private function process_pending_comment_reward( $comment_id, $reward_id ) {
        global $wpdb;
        
        $reward = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}zap_rewards WHERE id = %d",
            $reward_id
        ) );
        
        if ( ! $reward ) {
            return;
        }
        
        $wpdb->update(
            $wpdb->prefix . 'zap_rewards',
            array( 'status' => 'pending' ),
            array( 'id' => $reward_id )
        );
        
        require_once plugin_dir_path( __FILE__ ) . 'class-zap-rewards-processor.php';
        $processor = new Nostr_Outbox_Zap_Rewards_Processor();
        $result = $processor->send_payment( $reward_id, $reward->zap_address, $reward->amount );
        
        if ( $result ) {
            set_transient( 'zap_rewards_success_' . $reward->user_id, array(
                'message' => "{$reward->amount} sats are on the way! ⚡",
                'type' => 'success',
                'amount' => $reward->amount,
            ), 60 );
        }
    }

    /**
     * Process comment reward
     */
    private function process_comment_reward( $comment_id ) {
        if ( ! get_option( 'nostr_zap_rewards_enable_comments' ) ) {
            return;
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment || $comment->user_id === 0 ) {
            return;
        }
        
        $user_id = $comment->user_id;

        // Check if already rewarded
        global $wpdb;
        $existing_reward = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}zap_rewards 
             WHERE user_id = %d 
             AND reward_type IN ('comment', 'review')
             AND comment_id = %d",
            $user_id,
            $comment_id
        ) );

        if ( $existing_reward > 0 ) {
            return;
        }

        // Check daily limit
        $daily_limit = get_option( 'nostr_zap_rewards_daily_limit', 5 );
        $today_rewards = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}zap_rewards 
             WHERE user_id = %d 
             AND reward_type IN ('comment', 'review')
             AND created_at >= CURDATE()",
            $user_id
        ) );

        if ( $today_rewards >= $daily_limit ) {
            set_transient( 'zap_rewards_limit_' . $user_id, array(
                'message' => "You've reached your daily reward limit ({$daily_limit} rewards per day). Come back tomorrow!",
                'type' => 'limit',
            ), 60 );
            return;
        }

        $zap_address = get_user_meta( $user_id, 'zap_address', true );
        if ( empty( $zap_address ) ) {
            return;
        }

        // Determine if review or comment
        $is_review = false;
        $post_id = $comment->comment_post_ID;
        $post_type = get_post_type( $post_id );
        
        if ( $post_type === 'product' ) {
            $rating = get_comment_meta( $comment_id, 'rating', true );
            if ( ! empty( $rating ) ) {
                $is_review = true;
            }
        }

        if ( $is_review && get_option( 'nostr_zap_rewards_enable_reviews' ) ) {
            $reward_amount = get_option( 'nostr_zap_rewards_review_amount', 0 );
            $reward_type = 'review';
        } else {
            $reward_amount = get_option( 'nostr_zap_rewards_comment_amount', 0 );
            $reward_type = 'comment';
        }
        
        if ( $reward_amount <= 0 ) {
            return;
        }

        // Process the reward
        require_once plugin_dir_path( __FILE__ ) . 'class-zap-rewards-processor.php';
        $processor = new Nostr_Outbox_Zap_Rewards_Processor();
        $result = $processor->process_reward( $user_id, $reward_type, $reward_amount, $zap_address, $comment_id );
        
        if ( $result ) {
            set_transient( 'zap_rewards_success_' . $user_id, array(
                'message' => "{$reward_amount} sats are on the way! ⚡",
                'type' => 'success',
                'amount' => $reward_amount,
            ), 60 );
        } else {
            set_transient( 'zap_rewards_error_' . $user_id, array(
                'message' => "Reward couldn't be sent. Please check your Lightning address in My Account.",
                'type' => 'error',
            ), 60 );
        }
    }

    /**
     * WooCommerce: Add Lightning address field to checkout
     */
    public function add_zap_address_field() {
        $user_id = get_current_user_id();
        $lightning_address = '';
        
        if ( $user_id ) {
            $lightning_address = get_user_meta( $user_id, 'zap_address', true );
        }
        
        echo '<div class="zap-rewards-checkout">';
        echo '<h3>⚡ Lightning Rewards</h3>';
        echo '<p>Enter your Coinos username or Lightning address to receive Bitcoin rewards for purchases!</p>';
        woocommerce_form_field( 'lightning_address', array(
            'type' => 'text',
            'class' => array( 'form-row-wide' ),
            'label' => 'Lightning Address (optional)',
            'placeholder' => 'your_coinos_username',
            'default' => $lightning_address,
        ) );
        echo '</div>';
    }

    /**
     * WooCommerce: Save Lightning address from checkout
     */
    public function save_zap_address( $order_id ) {
        if ( ! empty( $_POST['lightning_address'] ) ) {
            $lightning_address = sanitize_text_field( $_POST['lightning_address'] );
            update_post_meta( $order_id, '_lightning_address', $lightning_address );
            
            $user_id = get_current_user_id();
            if ( $user_id ) {
                update_user_meta( $user_id, 'zap_address', $lightning_address );
            }
        }
    }

    /**
     * WooCommerce: Process purchase reward
     */
    public function process_order_reward( $order_id ) {
        if ( ! get_option( 'nostr_zap_rewards_enable_purchases' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_user_id();
        if ( ! $user_id ) {
            return;
        }

        // Check if already rewarded
        if ( get_post_meta( $order_id, '_zap_reward_processed', true ) ) {
            return;
        }

        // Check minimum purchase amount
        $min_purchase_amount = floatval( get_option( 'nostr_zap_rewards_min_purchase_amount', 10 ) );
        $order_total = floatval( $order->get_total() );
        
        if ( $order_total < $min_purchase_amount ) {
            error_log( "Zap Rewards: Order #$order_id total ($order_total) is below minimum ($min_purchase_amount). Skipping cashback." );
            return;
        }

        // Exclude cash and check payments
        $payment_method = $order->get_payment_method();
        $excluded_methods = array( 'cod', 'cheque', 'bacs', 'cash', 'check' );
        
        if ( in_array( $payment_method, $excluded_methods ) ) {
            error_log( "Zap Rewards: Order #$order_id payment method ($payment_method) is excluded from cashback." );
            return;
        }

        // Get Lightning address
        $lightning_address = get_post_meta( $order_id, '_lightning_address', true );
        if ( empty( $lightning_address ) ) {
            $lightning_address = get_user_meta( $user_id, 'zap_address', true );
        }
        
        if ( empty( $lightning_address ) ) {
            return;
        }

        // Calculate reward
        $percentage = floatval( get_option( 'nostr_zap_rewards_purchase_percentage', 1 ) );
        if ( $percentage <= 0 ) {
            return;
        }

        $order_currency = $order->get_currency();
        
        // Get sats per currency
        require_once plugin_dir_path( __FILE__ ) . 'class-zap-rewards-processor.php';
        $processor = new Nostr_Outbox_Zap_Rewards_Processor();
        $sats_per_currency = $processor->get_sats_per_currency( $order_currency );
        
        $reward_sats = round( ( $order_total * $percentage / 100 ) * $sats_per_currency );

        if ( $reward_sats < 1 ) {
            return;
        }

        // Process the reward
        $result = $processor->process_reward( $user_id, 'purchase', $reward_sats, $lightning_address, $order_id );

        if ( $result ) {
            update_post_meta( $order_id, '_zap_reward_processed', true );
        }
    }

    /**
     * AJAX: Update lightning address
     */
    public function ajax_update_lightning_address() {
        $user_id = get_current_user_id();
        
        if ( ! $user_id ) {
            wp_send_json_error( 'Not logged in' );
        }
        
        $lightning_address = sanitize_text_field( $_POST['lightning_address'] ?? '' );
        
        if ( empty( $lightning_address ) ) {
            wp_send_json_error( 'Lightning address is required' );
        }
        
        update_user_meta( $user_id, 'zap_address', $lightning_address );
        wp_send_json_success( 'Lightning address updated' );
    }

    /**
     * Activate: Create tables and flush rewrite rules
     */
    public static function activate() {
        $instance = self::instance();
        $instance->create_tables();
        
        // Register the endpoint before flushing
        add_rewrite_endpoint( 'zap-rewards', EP_ROOT | EP_PAGES );
        
        // Flush rewrite rules to make the endpoint work
        flush_rewrite_rules();
    }
}

