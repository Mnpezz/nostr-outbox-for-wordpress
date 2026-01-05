<?php
/**
 * Admin Settings Page
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles admin settings page
 */
class Nostr_Login_Pay_Admin_Settings {

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
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // Manually handle settings save to fix checkbox issues
        add_action( 'admin_init', array( $this, 'manual_save_settings' ), 999 );
    }

    /**
     * Sanitize relay URLs
     */
    public function sanitize_relays( $value ) {
        // Debug log
        error_log( 'Sanitize relays called with: ' . print_r( $value, true ) );
        
        $default_relays = array(
            'wss://relay.damus.io',
            'wss://relay.snort.social',
            'wss://nos.lol',
            'wss://relay.nostr.band',
        );
        
        if ( empty( $value ) || ! is_array( $value ) ) {
            error_log( 'Sanitize relays: value empty or not array, returning defaults' );
            return $default_relays;
        }

        // Process array
        $relays = array();
        foreach ( $value as $relay ) {
            $relay = trim( $relay );
            // Skip empty values and placeholders
            if ( empty( $relay ) || $relay === 'wss://relay.example.com' ) {
                continue;
            }
            // Validate wss:// or ws:// format
            if ( strpos( $relay, 'wss://' ) === 0 || strpos( $relay, 'ws://' ) === 0 ) {
                // Don't use esc_url_raw() - it strips wss:// URLs!
                // Just sanitize as text and validate the URL structure
                $sanitized = sanitize_text_field( $relay );
                if ( filter_var( $sanitized, FILTER_VALIDATE_URL ) || preg_match( '/^wss?:\/\/.+/', $sanitized ) ) {
                    $relays[] = $sanitized;
                    error_log( "Sanitize relays: added relay: $sanitized" );
                } else {
                    error_log( "Sanitize relays: skipping invalid URL structure: $relay" );
                }
            } else {
                // Log invalid relay
                error_log( 'Sanitize relays: skipping invalid relay (not wss/ws): ' . $relay );
            }
        }
        
        // If NO valid relays, return defaults
        // But if we have at least one valid relay, use those
        if ( empty( $relays ) ) {
            error_log( 'Sanitize relays: no valid relays, returning defaults' );
            return $default_relays;
        }
        
        error_log( 'Sanitize relays: returning ' . count( $relays ) . ' valid relays: ' . print_r( $relays, true ) );
        return $relays;
    }

    /**
     * Manually save settings to handle checkboxes properly
     */
    public function manual_save_settings() {
        // Handle clear BTC price cache action
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'clear_btc_cache' && isset( $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_GET['_wpnonce'], 'clear_btc_cache' ) && current_user_can( 'manage_options' ) ) {
                // Clear all BTC price caches
                global $wpdb;
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nwc_btc_price_%'" );
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_nwc_btc_price_%'" );
                
                wp_redirect( add_query_arg( array(
                    'page' => 'nostr-outbox-wordpress',
                    'tab' => 'nwc',
                    'btc_cache_cleared' => '1'
                ), admin_url( 'options-general.php' ) ) );
                exit;
            }
        }
        
        // Only run when saving our settings
        if ( ! isset( $_POST['option_page'] ) ) {
            return;
        }

        $option_page = $_POST['option_page'];

        if ( $option_page !== 'nostr_login_pay_general' && $option_page !== 'nostr_login_pay_nwc' ) {
            return;
        }

        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'nostr_login_pay_general-options' ) && ! wp_verify_nonce( $_POST['_wpnonce'], 'nostr_login_pay_nwc-options' ) ) {
            return;
        }

        // Define which checkboxes belong to which option page
        $checkboxes_by_page = array(
            'nostr_login_pay_general' => array(
                'nostr_login_pay_enable_login',
                'nostr_login_pay_enable_nwc',
                'nostr_login_pay_auto_create_account',
                'nostr_login_pay_sync_banner',
                'nostr_login_pay_sync_website',
                'nostr_login_pay_sync_username',
                'nostr_login_pay_sync_lud16',
                'nostr_login_pay_enable_chat',
                'nostr_login_pay_show_chat_avatar',
            ),
            'nostr_login_pay_nwc' => array(
                'nostr_login_pay_nwc_enable_payment_gateway',
            ),
        );

        // Only process checkboxes for the current option page
        if ( isset( $checkboxes_by_page[ $option_page ] ) ) {
            foreach ( $checkboxes_by_page[ $option_page ] as $checkbox ) {
                // If checkbox exists in POST (checked), set to '1', otherwise set to ''
                $value = isset( $_POST[ $checkbox ] ) ? '1' : '';
                update_option( $checkbox, $value );
            }
        }
    }

    /**
     * Initialize default option values if they don't exist
     */
    private function maybe_initialize_defaults() {
        $defaults = array(
            'nostr_login_pay_enable_login' => '1',
            'nostr_login_pay_enable_nwc' => '1',
            'nostr_login_pay_auto_create_account' => '1',
            'nostr_login_pay_nwc_enable_payment_gateway' => '1',
            'nostr_login_pay_default_role' => 'customer',
            'nostr_login_pay_relays' => "wss://relay.damus.io\nwss://relay.primal.net\nwss://nos.lol",
            'nostr_login_pay_nwc_payment_timeout' => 300,
            'nostr_login_pay_enable_chat' => '1',
            'nostr_login_pay_show_chat_avatar' => '1',
        );

        foreach ( $defaults as $option_name => $default_value ) {
            if ( get_option( $option_name ) === false ) {
                add_option( $option_name, $default_value );
            }
        }
    }


    /**
     * Sanitize NWC connection string
     */
    public function sanitize_nwc_connection( $value ) {
        // Return empty if no value
        if ( empty( $value ) ) {
            return '';
        }
        
        // Trim whitespace
        $value = trim( $value );
        
        // Decode URL encoding (Coinos provides URL-encoded strings like wss%3A%2F%2F)
        // Check if it contains URL encoding before decoding
        if ( strpos( $value, '%' ) !== false ) {
            $value = urldecode( $value );
            
            // Show success message about decoding
            add_settings_error(
                'nostr_login_pay_nwc_merchant_wallet',
                'nwc_decoded',
                __( 'Connection string was automatically decoded from URL format.', 'nostr-outbox-wordpress' ),
                'updated'
            );
        }
        
        // Fix common typo: "nostr walletconnect" should be "nostr+walletconnect"
        // This also fixes when urldecode() converts + to space
        if ( strpos( $value, 'nostr walletconnect://' ) === 0 ) {
            $value = str_replace( 'nostr walletconnect://', 'nostr+walletconnect://', $value );
            add_settings_error(
                'nostr_login_pay_nwc_merchant_wallet',
                'nwc_fixed',
                __( 'Connection string was automatically corrected (added missing + sign).', 'nostr-outbox-wordpress' ),
                'success'
            );
        }
        
        // Basic validation - check if it starts with the right prefix (case-insensitive)
        $value_lower = strtolower( $value );
        if ( strpos( $value_lower, 'nostr+walletconnect://' ) !== 0 && strpos( $value_lower, 'nostr+walletconnect://' ) === false ) {
            // Debug: show what we actually got
            $debug_prefix = substr( $value, 0, 50 );
            $debug_length = strlen( $value );
            add_settings_error(
                'nostr_login_pay_nwc_merchant_wallet',
                'invalid_nwc_format',
                sprintf( 
                    __( 'Invalid NWC format. Expected nostr+walletconnect://... but got (length %d): %s...', 'nostr-outbox-wordpress' ),
                    $debug_length,
                    esc_html( $debug_prefix )
                ),
                'error'
            );
            // Still save it for debugging purposes
            // return '';
        }
        
        // Check for required parameters
        if ( strpos( $value, 'relay=' ) === false || strpos( $value, 'secret=' ) === false ) {
            add_settings_error(
                'nostr_login_pay_nwc_merchant_wallet',
                'missing_params',
                __( 'Connection string appears to be missing required parameters (relay or secret).', 'nostr-outbox-wordpress' ),
                'error'
            );
            // Return empty if missing critical parameters
            return '';
        }
        
        // All validation passed
        add_settings_error(
            'nostr_login_pay_nwc_merchant_wallet',
            'nwc_saved',
            __( '✓ NWC connection saved successfully!', 'nostr-outbox-wordpress' ),
            'success'
        );
        
        return $value;
    }

    /**
     * Add settings page to admin menu
     */
    public function add_menu_page() {
        add_options_page(
            __( 'NOW - Nostr Outbox Settings', 'nostr-outbox-wordpress' ),
            __( 'NOW - Nostr Outbox', 'nostr-outbox-wordpress' ),
            'manage_options',
            'nostr-outbox-wordpress',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // General Settings - checkboxes handled manually
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_enable_login' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_enable_nwc' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_auto_create_account' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_sync_banner' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_sync_website' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_sync_username' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_sync_lud16' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_enable_chat' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_show_chat_avatar' );
        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_chat_avatar_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ) );

        register_setting( 'nostr_login_pay_general', 'nostr_login_pay_default_role', array(
            'type' => 'string',
            'default' => 'customer',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // Relay Settings
        register_setting( 'nostr_login_pay_relays', 'nostr_login_pay_relays', array(
            'type' => 'array',
            'sanitize_callback' => array( $this, 'sanitize_relays' ),
            'default' => array(
                'wss://relay.damus.io',
                'wss://relay.snort.social',
                'wss://nos.lol',
                'wss://relay.nostr.band',
            ),
        ) );

        register_setting( 'nostr_login_pay_relays', 'nostr_login_pay_redirect_after_login', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'account',
        ) );

        // NWC Settings - checkbox handled manually
        register_setting( 'nostr_login_pay_nwc', 'nostr_login_pay_nwc_enable_payment_gateway' );

        register_setting( 'nostr_login_pay_nwc', 'nostr_login_pay_lightning_address', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'nostr_login_pay_nwc', 'nostr_login_pay_nwc_merchant_wallet', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => array( $this, 'sanitize_nwc_connection' ),
        ) );

        register_setting( 'nostr_login_pay_nwc', 'nostr_login_pay_nwc_payment_timeout', array(
            'type' => 'integer',
            'default' => 300,
            'sanitize_callback' => 'absint',
        ) );

        // Add settings sections and fields
        add_settings_section(
            'nostr_login_pay_general_section',
            __( 'General Settings', 'nostr-outbox-wordpress' ),
            array( $this, 'render_general_section' ),
            'nostr-outbox-wordpress-general'
        );

        add_settings_field(
            'nostr_login_pay_enable_login',
            __( 'Enable Nostr Login', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_general_section',
            array( 'name' => 'nostr_login_pay_enable_login', 'label' => __( 'Allow users to login with Nostr', 'nostr-outbox-wordpress' ) )
        );

        // HIDDEN: NWC Integration checkbox removed - customer wallet connections disabled
        // This was for allowing customers to connect their own NWC wallets (not implemented)
        // Payment gateway is controlled by the "Enable Payment Gateway" setting in NWC Settings tab
        /*
        add_settings_field(
            'nostr_login_pay_enable_nwc',
            __( 'Enable NWC Integration', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_general_section',
            array( 'name' => 'nostr_login_pay_enable_nwc', 'label' => __( 'Allow users to connect NWC wallets', 'nostr-outbox-wordpress' ) )
        );
        */

        add_settings_field(
            'nostr_login_pay_auto_create_account',
            __( 'Auto-create Accounts', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_general_section',
            array( 'name' => 'nostr_login_pay_auto_create_account', 'label' => __( 'Automatically create WordPress accounts for new Nostr users', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_default_role',
            __( 'Default User Role', 'nostr-outbox-wordpress' ),
            array( $this, 'render_role_select_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_general_section',
            array( 'name' => 'nostr_login_pay_default_role' )
        );

        // Profile Sync Section
        add_settings_section(
            'nostr_login_pay_profile_sync',
            __( 'Profile Sync Details', 'nostr-outbox-wordpress' ),
            array( $this, 'render_profile_sync_section' ),
            'nostr-outbox-wordpress-general'
        );

        add_settings_field(
            'nostr_login_pay_sync_banner',
            __( 'Sync Banner', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_profile_sync',
            array( 'name' => 'nostr_login_pay_sync_banner', 'label' => __( 'Import banner image from Nostr', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_sync_website',
            __( 'Sync Website', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_profile_sync',
            array( 'name' => 'nostr_login_pay_sync_website', 'label' => __( 'Import website URL from Nostr', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_sync_username',
            __( 'Sync Username', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_profile_sync',
            array( 'name' => 'nostr_login_pay_sync_username', 'label' => __( 'Import name/display_name from Nostr', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_sync_lud16',
            __( 'Sync Lightning Address', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_profile_sync',
            array( 'name' => 'nostr_login_pay_sync_lud16', 'label' => __( 'Import lud16 lightning address from Nostr', 'nostr-outbox-wordpress' ) )
        );

        // HIDDEN: Nostr Relays field removed - not used by the plugin
        // Default relays are hardcoded in the plugin and work fine for most users
        // Advanced users can modify relays directly in code if needed
        /*
        add_settings_field(
            'nostr_login_pay_relays',
            __( 'Nostr Relays', 'nostr-outbox-wordpress' ),
            array( $this, 'render_textarea_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_general_section',
            array( 'name' => 'nostr_login_pay_relays', 'description' => __( 'One relay URL per line', 'nostr-outbox-wordpress' ) )
        );
        */
        
        // Chat Widget Section
        add_settings_section(
            'nostr_login_pay_chat_section',
            __( 'Chat Widget Settings', 'nostr-outbox-wordpress' ),
            array( $this, 'render_chat_section' ),
            'nostr-outbox-wordpress-general'
        );

        add_settings_field(
            'nostr_login_pay_enable_chat',
            __( 'Enable Chat Widget', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_chat_section',
            array( 'name' => 'nostr_login_pay_enable_chat', 'label' => __( 'Show the customer service chat widget on the frontend', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_show_chat_avatar',
            __( 'Show Customer Service Avatar', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_chat_section',
            array( 'name' => 'nostr_login_pay_show_chat_avatar', 'label' => __( 'Display an avatar in the chat header', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_chat_avatar_url',
            __( 'Custom Avatar URL', 'nostr-outbox-wordpress' ),
            array( $this, 'render_media_field' ),
            'nostr-outbox-wordpress-general',
            'nostr_login_pay_chat_section',
            array( 
                'name' => 'nostr_login_pay_chat_avatar_url', 
                'description' => __( 'Enter a URL for a custom customer service avatar or choose from media library. If empty, a default icon will be used.', 'nostr-outbox-wordpress' ) 
            )
        );

        // NWC Settings Section
        add_settings_section(
            'nostr_login_pay_nwc_section',
            __( 'NWC Payment Settings', 'nostr-outbox-wordpress' ),
            array( $this, 'render_nwc_section' ),
            'nostr-outbox-wordpress-nwc'
        );

        add_settings_field(
            'nostr_login_pay_nwc_enable_payment_gateway',
            __( 'Enable Payment Gateway', 'nostr-outbox-wordpress' ),
            array( $this, 'render_checkbox_field' ),
            'nostr-outbox-wordpress-nwc',
            'nostr_login_pay_nwc_section',
            array( 'name' => 'nostr_login_pay_nwc_enable_payment_gateway', 'label' => __( 'Enable NWC as a WooCommerce payment method', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_lightning_address',
            __( 'Lightning Address (Recommended)', 'nostr-outbox-wordpress' ),
            array( $this, 'render_lightning_address_field' ),
            'nostr-outbox-wordpress-nwc',
            'nostr_login_pay_nwc_section',
            array( 'name' => 'nostr_login_pay_lightning_address' )
        );

        // NWC Connection field - REQUIRED for auto-verification!
        add_settings_field(
            'nostr_login_pay_nwc_merchant_wallet',
            __( 'NWC Connection (For Auto-Verification)', 'nostr-outbox-wordpress' ),
            array( $this, 'render_nwc_connection_field' ),
            'nostr-outbox-wordpress-nwc',
            'nostr_login_pay_nwc_section',
            array( 'name' => 'nostr_login_pay_nwc_merchant_wallet' )
        );

        // HIDDEN: Payment timeout and webhook fields not needed for current functionality
        // Keeping code for future use if automatic verification is implemented
        /*
        add_settings_field(
            'nostr_login_pay_nwc_payment_timeout',
            __( 'Payment Timeout', 'nostr-outbox-wordpress' ),
            array( $this, 'render_number_field' ),
            'nostr-outbox-wordpress-nwc',
            'nostr_login_pay_nwc_section',
            array( 'name' => 'nostr_login_pay_nwc_payment_timeout', 'description' => __( 'Seconds to wait for payment confirmation', 'nostr-outbox-wordpress' ) )
        );

        add_settings_field(
            'nostr_login_pay_webhook_url',
            __( 'Webhook URL', 'nostr-outbox-wordpress' ),
            array( $this, 'render_webhook_url_field' ),
            'nostr-outbox-wordpress-nwc',
            'nostr_login_pay_nwc_section',
            array()
        );
        */
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Initialize default values if they don't exist
        $this->maybe_initialize_defaults();

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
        
        // Show success message if BTC cache was cleared
        if ( isset( $_GET['btc_cache_cleared'] ) ) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>✅ Bitcoin price cache cleared!</strong> The next order will fetch the current BTC price.</p>
            </div>
            <?php
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=nostr-outbox-wordpress&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'General', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=nwc" class="nav-tab <?php echo $active_tab === 'nwc' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'NWC Settings', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=relays" class="nav-tab <?php echo $active_tab === 'relays' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'Relays', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm" class="nav-tab <?php echo $active_tab === 'dm' ? 'nav-tab-active' : ''; ?>">
                    💬 <?php _e( 'DM Management', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=zap" class="nav-tab <?php echo $active_tab === 'zap' ? 'nav-tab-active' : ''; ?>">
                    💰 <?php _e( 'Zap Rewards', 'nostr-outbox-wordpress' ); ?>
                </a>
            </h2>

            <?php if ( $active_tab === 'relays' ) : ?>
                <?php $this->render_relays_tab(); ?>
            <?php elseif ( $active_tab === 'dm' ) : ?>
                <?php 
                if ( class_exists( 'Nostr_Login_Pay_DM_Admin' ) ) {
                    $dm_admin = Nostr_Login_Pay_DM_Admin::instance();
                    // Render the full DM admin interface with sub-tabs
                    $dm_admin->render_dm_tabs();
                }
                ?>
            <?php elseif ( $active_tab === 'zap' ) : ?>
                <?php 
                if ( class_exists( 'Nostr_Outbox_Zap_Rewards_Admin' ) ) {
                    $zap_admin = Nostr_Outbox_Zap_Rewards_Admin::instance();
                    $zap_admin->render_zap_tab();
                } else {
                    echo '<p>Zap Rewards not available.</p>';
                }
                ?>
            <?php else : ?>
            <form action="options.php" method="post">
                <?php
                if ( $active_tab === 'general' ) {
                    settings_fields( 'nostr_login_pay_general' );
                    do_settings_sections( 'nostr-outbox-wordpress-general' );
                } elseif ( $active_tab === 'nwc' ) {
                    settings_fields( 'nostr_login_pay_nwc' );
                    do_settings_sections( 'nostr-outbox-wordpress-nwc' );
                }
                submit_button( __( 'Save Settings', 'nostr-outbox-wordpress' ) );
                ?>
            </form>
            <?php endif; ?>
            
            <?php if ( $active_tab === 'nwc' ) : ?>
                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px;">
                    <h3 style="margin-top: 0;">🔧 Advanced Tools</h3>
                    <p style="margin: 5px 0 15px 0; color: #6b7280;">
                        The plugin caches Bitcoin prices for 5 minutes to improve performance. 
                        If you notice incorrect pricing, clear the cache to fetch fresh rates.
                    </p>
                    <a href="<?php echo wp_nonce_url( admin_url( 'options-general.php?page=nostr-outbox-wordpress&tab=nwc&action=clear_btc_cache' ), 'clear_btc_cache' ); ?>" 
                       class="button button-secondary" 
                       onclick="return confirm('Clear Bitcoin price cache and fetch fresh rates?');">
                        🔄 Clear BTC Price Cache
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render general section description
     */
    public function render_general_section() {
        echo '<p>' . esc_html__( 'Configure how Nostr login works on your site.', 'nostr-outbox-wordpress' ) . '</p>';
    }

    /**
     * Render chat section description
     */
    public function render_chat_section() {
        echo '<p>' . esc_html__( 'Configure the Nostr customer service chat widget.', 'nostr-outbox-wordpress' ) . '</p>';
    }

    /**
     * Render NWC section description
     */
    public function render_nwc_section() {
        ?>
        <p><?php esc_html_e( 'Configure Lightning Network payment settings for WooCommerce.', 'nostr-outbox-wordpress' ); ?></p>
        
        <div style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 15px; margin: 15px 0;">
            <h4 style="margin-top: 0; color: #1e40af;">⚡ Lightning Payment Setup</h4>
            <p style="margin: 0 0 10px 0;">
                Enter your <strong>Lightning Address</strong> below to accept instant Bitcoin payments.
            </p>
            <ul style="margin: 5px 0 0 20px;">
                <li>Customers scan QR code and pay with any Lightning wallet</li>
                <li>Browser extension users get instant automatic confirmation</li>
                <li>QR code payments verified manually via "Mark as Paid" button</li>
            </ul>
        </div>
        
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 15px 0;">
            <h4 style="margin-top: 0; color: #92400e;">🎯 Quick Setup with Coinos</h4>
            <ol style="margin: 10px 0 10px 20px;">
                <li>Sign up at <a href="https://coinos.io/" target="_blank" style="font-weight: bold;">coinos.io</a></li>
                <li>Your Lightning address is: <code>username@coinos.io</code></li>
                <li>Paste it in the "Lightning Address" field below</li>
                <li><strong>Done!</strong> You're ready to accept Lightning payments</li>
            </ol>
        </div>
        <?php
    }

    /**
     * Render checkbox field
     */
    public function render_checkbox_field( $args ) {
        $name = $args['name'];
        $label = isset( $args['label'] ) ? $args['label'] : '';
        $value = get_option( $name, '' );
        $is_checked = ( $value === '1' || $value === 1 || $value === true );
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $is_checked, true ); ?>>
            <?php echo esc_html( $label ); ?>
        </label>
        <?php
    }

    /**
     * Render media field with picker
     */
    public function render_media_field( $args ) {
        $name = $args['name'];
        $description = isset( $args['description'] ) ? $args['description'] : '';
        $value = get_option( $name, '' );
        ?>
        <div class="nostr-media-field-wrapper">
            <input type="text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
            <button type="button" class="button nostr-choose-media" data-target="<?php echo esc_attr( $name ); ?>">
                <?php _e( 'Choose from Media', 'nostr-outbox-wordpress' ); ?>
            </button>
            <?php if ( $description ) : ?>
                <p class="description"><?php echo esc_html( $description ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render text field
     */
    public function render_text_field( $args ) {
        $name = $args['name'];
        $description = isset( $args['description'] ) ? $args['description'] : '';
        $value = get_option( $name, '' );
        ?>
        <input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
        <?php if ( $description ) : ?>
            <p class="description"><?php echo esc_html( $description ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render number field
     */
    public function render_number_field( $args ) {
        $name = $args['name'];
        $description = isset( $args['description'] ) ? $args['description'] : '';
        $value = get_option( $name, 0 );
        ?>
        <input type="number" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="small-text">
        <?php if ( $description ) : ?>
            <p class="description"><?php echo esc_html( $description ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render textarea field
     */
    public function render_textarea_field( $args ) {
        $name = $args['name'];
        $description = isset( $args['description'] ) ? $args['description'] : '';
        $value = get_option( $name, '' );
        ?>
        <textarea name="<?php echo esc_attr( $name ); ?>" rows="5" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
        <?php if ( $description ) : ?>
            <p class="description"><?php echo esc_html( $description ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render role select field
     */
    public function render_role_select_field( $args ) {
        $name = $args['name'];
        $value = get_option( $name, 'customer' );
        $roles = wp_roles()->get_names();
        ?>
        <select name="<?php echo esc_attr( $name ); ?>">
            <?php foreach ( $roles as $role_value => $role_name ) : ?>
                <option value="<?php echo esc_attr( $role_value ); ?>" <?php selected( $value, $role_value ); ?>>
                    <?php echo esc_html( $role_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render Lightning Address field
     */
    public function render_lightning_address_field( $args ) {
        $name = $args['name'];
        $value = get_option( $name, '' );
        ?>
        <input 
            type="text" 
            name="<?php echo esc_attr( $name ); ?>" 
            value="<?php echo esc_attr( $value ); ?>" 
            class="regular-text" 
            style="width: 100%; max-width: 400px; font-size: 16px;" 
            placeholder="yourname@coinos.io"
        >
        
        <p class="description" style="margin-top: 10px;">
            <?php _e( 'Your Lightning address where you want to receive payments.', 'nostr-outbox-wordpress' ); ?>
        </p>
        
        <?php if ( ! empty( $value ) ) : ?>
            <div style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 12px; margin: 10px 0; max-width: 500px;">
                <strong style="color: #15803d;">✓ Lightning Address Configured</strong><br>
                <span style="font-size: 13px; color: #166534;">
                    Payments will be sent to: <code style="background: #dcfce7; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html( $value ); ?></code>
                </span>
            </div>
        <?php else : ?>
            <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px; margin: 10px 0; max-width: 500px;">
                <strong style="color: #92400e;">⚡ Enter your Lightning Address to start accepting payments</strong><br>
                <span style="font-size: 12px; color: #78350f;">
                    Get one free at <a href="https://coinos.io" target="_blank">coinos.io</a>, 
                    <a href="https://getalby.com" target="_blank">getalby.com</a>, or 
                    <a href="https://strike.me" target="_blank">strike.me</a>
                </span>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render NWC connection field with help text
     */
    public function render_nwc_connection_field( $args ) {
        $name = $args['name'];
        $value = get_option( $name, '' );
        
        // Validate the stored value
        $is_valid = false;
        if ( ! empty( $value ) ) {
            $is_valid = ( strpos( $value, 'nostr+walletconnect://' ) === 0 ) &&
                        ( strpos( $value, 'relay=' ) !== false ) &&
                        ( strpos( $value, 'secret=' ) !== false );
        }
        ?>
        <input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width: 100%; max-width: 600px; font-family: 'Courier New', monospace; font-size: 13px;" placeholder="nostr+walletconnect://...">
        
        <p class="description" style="margin-top: 10px;">
            <?php _e( '<strong>For Auto-Verification of QR Code Payments.</strong> This gives the plugin read-only access to check if invoices are paid.', 'nostr-outbox-wordpress' ); ?>
        </p>
        
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin: 10px 0; max-width: 600px;">
            <strong>⚡ Quick Setup (2 minutes):</strong><br>
            <span style="font-size: 12px; color: #92400e;">
                1. Go to <a href="https://coinos.io" target="_blank">coinos.io</a> → Settings → Plugins → NWC<br>
                2. Create connection with: <code>lookup_invoice</code> permission<br>
                3. Copy the connection string (starts with <code>nostr+walletconnect://</code>)<br>
                4. Paste above → <strong>QR payments now auto-complete in seconds!</strong> ✅
            </span>
        </div>

        <?php if ( $is_valid ) : ?>
            <div style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 12px; margin: 10px 0; max-width: 600px;">
                <strong style="color: #15803d;">✓ NWC Auto-Verification Enabled!</strong><br>
                <span style="font-size: 12px; color: #166534;">
                    ✅ Browser wallet payments: Auto-complete<br>
                    ✅ QR code payments: Auto-complete via NWC lookup_invoice<br>
                    Your store now has fully automated Lightning payments!
                </span>
            </div>
        <?php elseif ( ! empty( $value ) ) : ?>
            <div style="background: #fee; border-left: 4px solid #ef4444; padding: 12px; margin: 10px 0; max-width: 600px;">
                <strong style="color: #dc2626;">✗ Invalid NWC Connection</strong><br>
                <span style="font-size: 12px; color: #991b1b;">
                    The connection string is invalid. It must start with <code>nostr+walletconnect://</code> and include <code>relay=</code> and <code>secret=</code> parameters.
                </span>
            </div>
        <?php else : ?>
            <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin: 10px 0; max-width: 600px;">
                <strong style="color: #92400e;">⚠️ Manual Payment Verification Required</strong><br>
                <span style="font-size: 12px; color: #92400e;">
                    QR code payments require manual "Mark as Paid" button click.<br>
                    <strong>Add NWC above for instant auto-verification!</strong>
                </span>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render webhook URL field (read-only display)
     */
    public function render_webhook_url_field( $args ) {
        $webhook_url = rest_url( 'nostr-outbox-wordpress/v1/webhook/payment' );
        ?>
        <input 
            type="text" 
            value="<?php echo esc_url( $webhook_url ); ?>" 
            class="regular-text" 
            readonly
            onclick="this.select()"
            style="width: 100%; max-width: 600px; background: #f9f9f9; cursor: pointer;"
        >
        
        <p class="description" style="margin-top: 10px; color: #6b7280;">
            <?php _e( 'Not required. Webhooks are not currently configured.', 'nostr-outbox-wordpress' ); ?>
        </p>
        
        <div style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 12px; margin: 10px 0; max-width: 600px;">
            <strong style="color: #15803d;">✅ How Payment Verification Works:</strong><br>
            <span style="font-size: 12px; color: #166534;">
                <strong>Browser Extension Payments:</strong> Auto-complete instantly (no action needed!)<br>
                <strong>QR Code Payments:</strong> Use the "✓ Mark as Paid" button in order admin (30 seconds)
            </span>
        </div>
        <?php
    }

    /**
     * Render the Relays & Redirect tab
     */
    public function render_relays_tab() {
        // Get raw option first
        $relays_raw = get_option( 'nostr_login_pay_relays' );
        
        // Debug what we got from database
        error_log( 'Relays from DB (raw): ' . print_r( $relays_raw, true ) );
        error_log( 'Relays type: ' . gettype( $relays_raw ) );
        
        // Set defaults if empty or not array
        if ( ! $relays_raw || ! is_array( $relays_raw ) || empty( $relays_raw ) ) {
            $relays = array(
                'wss://relay.damus.io',
                'wss://relay.snort.social',
                'wss://nos.lol',
                'wss://relay.nostr.band',
            );
            error_log( 'Using default relays' );
        } else {
            $relays = $relays_raw;
            error_log( 'Using saved relays: ' . count( $relays ) . ' relays' );
        }

        $redirect = get_option( 'nostr_login_pay_redirect_after_login', 'account' );

        // Show success message after save
        if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
            $saved_relays = get_option( 'nostr_login_pay_relays', array() );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php _e( 'Settings saved!', 'nostr-outbox-wordpress' ); ?></strong>
                    <?php if ( is_array( $saved_relays ) ) : ?>
                        <br><span style="font-size: 12px; color: #666;">
                            <?php echo count( $saved_relays ); ?> relay(s) saved to database
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            <?php
        }
        
        // Debug output for admins
        if ( current_user_can( 'manage_options' ) && isset( $_GET['debug'] ) ) {
            echo '<div class="notice notice-info"><p><strong>Debug:</strong> Relays = <pre>' . print_r( $relays, true ) . '</pre></p></div>';
        }

        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'nostr_login_pay_relays' ); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php _e( 'Nostr Relays', 'nostr-outbox-wordpress' ); ?></label>
                    </th>
                    <td>
                        <div id="nostr-relay-list">
                            <?php 
                            // Debug: show what we're about to render
                            if ( current_user_can( 'manage_options' ) ) {
                                error_log( 'Rendering ' . count( $relays ) . ' relay inputs' );
                                foreach ( $relays as $idx => $r ) {
                                    error_log( "  Relay $idx: '" . $r . "'" );
                                }
                            }
                            
                            foreach ( $relays as $index => $relay ) : 
                                $relay_value = trim( $relay );
                            ?>
                                <div class="relay-input-group" style="margin-bottom: 10px;">
                                    <input 
                                        type="text" 
                                        name="nostr_login_pay_relays[]" 
                                        value="<?php echo esc_attr( $relay_value ); ?>" 
                                        style="width: 400px;" 
                                        placeholder="wss://relay.example.com"
                                        data-original="<?php echo esc_attr( $relay_value ); ?>"
                                    />
                                    <button type="button" class="button remove-relay" style="margin-left: 5px;"><?php _e( 'Remove', 'nostr-outbox-wordpress' ); ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ( current_user_can( 'manage_options' ) && isset( $_GET['debug'] ) ) : ?>
                        <div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffc107;">
                            <strong>Debug Info:</strong><br>
                            Relays array: <pre><?php print_r( $relays ); ?></pre>
                        </div>
                        <?php endif; ?>
                        <button type="button" class="button" id="add-relay-btn" style="margin-top: 10px;"><?php _e( 'Add Relay', 'nostr-outbox-wordpress' ); ?></button>
                        <p class="description">
                            <?php _e( 'Enter Nostr relay WebSocket URLs (wss://...). Relays are used for fetching profile data, sending DMs, and NIP-05 verification.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="nostr_login_pay_redirect_after_login"><?php _e( 'Redirect After Login', 'nostr-outbox-wordpress' ); ?></label>
                    </th>
                    <td>
                        <select name="nostr_login_pay_redirect_after_login" id="nostr_login_pay_redirect_after_login">
                            <option value="account" <?php selected( $redirect, 'account' ); ?>><?php _e( 'My Account Page', 'nostr-outbox-wordpress' ); ?></option>
                            <option value="admin" <?php selected( $redirect, 'admin' ); ?>><?php _e( 'Admin Dashboard', 'nostr-outbox-wordpress' ); ?></option>
                            <option value="home" <?php selected( $redirect, 'home' ); ?>><?php _e( 'Home Page', 'nostr-outbox-wordpress' ); ?></option>
                            <option value="shop" <?php selected( $redirect, 'shop' ); ?>><?php _e( 'Shop Page', 'nostr-outbox-wordpress' ); ?></option>
                        </select>
                        <p class="description">
                            <?php _e( 'Choose where to redirect users after they log in with Nostr.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button( __( 'Save Settings', 'nostr-outbox-wordpress' ) ); ?>
        </form>
        
        <hr style="margin: 40px 0;">
        
        <h2><?php _e( 'Diagnostics', 'nostr-outbox-wordpress' ); ?></h2>
        <div style="background: #f0f9ff; border: 1px solid #3b82f6; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">🔍 Check Saved Relays</h3>
            <p>Click below to see exactly what relays are stored in your database:</p>
            <button type="button" class="button" onclick="
                var relays = <?php echo json_encode( get_option( 'nostr_login_pay_relays', array() ) ); ?>;
                alert('Saved relays in database:\\n\\n' + JSON.stringify(relays, null, 2));
            ">Show Database Value</button>
            <p style="font-size: 12px; color: #666; margin: 10px 0 0 0;">
                <strong>Current count:</strong> <?php echo count( is_array( $relays ) ? $relays : array() ); ?> relay(s) saved
            </p>
        </div>
        
        <hr style="margin: 20px 0;">
        
        <h2><?php _e( 'Popular Nostr Relays', 'nostr-outbox-wordpress' ); ?></h2>
        <p><?php _e( 'Here are some popular, reliable Nostr relays you can add:', 'nostr-outbox-wordpress' ); ?></p>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><code>wss://relay.damus.io</code> - Damus relay (popular, free)</li>
            <li><code>wss://relay.snort.social</code> - Snort relay</li>
            <li><code>wss://nos.lol</code> - Fast, reliable relay</li>
            <li><code>wss://relay.nostr.band</code> - Nostr.band relay</li>
            <li><code>wss://relay.primal.net</code> - Primal relay</li>
            <li><code>wss://relay.current.fyi</code> - Current relay</li>
            <li><code>wss://nostr-pub.wellorder.net</code> - Wellorder relay</li>
            <li><code>wss://relay.bitcoiner.social</code> - Bitcoin community relay</li>
        </ul>
        
        <style>
        .relay-input-group { display: flex; align-items: center; }
        </style>
        
        <script>
        (function() {
            const container = document.getElementById('nostr-relay-list');
            const addBtn = document.getElementById('add-relay-btn');
            
            if (!container || !addBtn) {
                console.error('Relay container or button not found');
                return;
            }
            
            // Add new relay input
            addBtn.addEventListener('click', function() {
                const div = document.createElement('div');
                div.className = 'relay-input-group';
                div.style.marginBottom = '10px';
                div.innerHTML = '<input type="text" name="nostr_login_pay_relays[]" value="" style="width: 400px;" placeholder="wss://relay.example.com" />' +
                    '<button type="button" class="button remove-relay" style="margin-left: 5px;"><?php echo esc_js( __( 'Remove', 'nostr-outbox-wordpress' ) ); ?></button>';
                container.appendChild(div);
            });
            
            // Remove relay input
            container.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-relay')) {
                    const group = e.target.closest('.relay-input-group');
                    // Ensure at least one input remains
                    const remaining = container.querySelectorAll('.relay-input-group').length;
                    if (remaining > 1) {
                        group.remove();
                    } else {
                        alert('You must have at least one relay configured.');
                    }
                }
            });
            
            // Debug: Log current inputs on page load
            console.log('Relay inputs on load:', container.querySelectorAll('input[name="nostr_login_pay_relays[]"]').length);
            container.querySelectorAll('input[name="nostr_login_pay_relays[]"]').forEach(function(input, index) {
                console.log('Relay ' + index + ':', input.value);
            });
        })();
        </script>
        ?>
        <?php
    }

    /**
     * Render Profile Sync section
     */
    public function render_profile_sync_section() {
        echo '<p>' . esc_html__( 'Choose which details to automatically import from your Nostr profile when you log in.', 'nostr-outbox-wordpress' ) . '</p>';
    }
}

