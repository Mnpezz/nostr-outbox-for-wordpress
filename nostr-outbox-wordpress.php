<?php
/**
 * Plugin Name: NOW - Nostr Outbox for WordPress
 * Plugin URI: https://github.com/Mnpezz/nostr-outbox-for-wordpress
 * Description: Send WordPress and WooCommerce notifications via Nostr instead of email. Includes Lightning payments, Nostr login, NIP-05 verification, and encrypted direct messaging.
 * Version: 1.3.1
 * Author: mnpezz
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nostr-outbox-wordpress
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

 // If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants
define( 'NOW_VERSION', '1.3.1' );
define( 'NOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NOW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Legacy constants for backward compatibility
define( 'NOSTR_LOGIN_PAY_VERSION', '1.3.1' );
define( 'NOSTR_LOGIN_PAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOSTR_LOGIN_PAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NOSTR_LOGIN_PAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer autoloader (for PHP crypto libraries)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	define( 'NOSTR_LOGIN_PAY_HAS_COMPOSER', true );
} else {
	define( 'NOSTR_LOGIN_PAY_HAS_COMPOSER', false );
	// Show admin notice if crypto dependencies are missing
	add_action( 'admin_notices', function() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong>Nostr Login & Pay:</strong> Composer dependencies not found. 
				Automatic DM sending will not work until you run <code>composer install</code> in the plugin directory.
				Manual DM sending (browser-based) will continue to work.
			</p>
		</div>
		<?php
	} );
}

/**
 * Main plugin class
 */
class Nostr_Login_And_Pay {

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
        $this->init();
    }

    /**
     * Initialize the plugin
     */
    private function init() {
        // Load plugin text domain
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // Include required files
        $this->includes();

        // Initialize components
        add_action( 'plugins_loaded', array( $this, 'init_components' ) );

        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Add login buttons to WooCommerce pages
        add_action( 'woocommerce_before_customer_login_form', array( $this, 'add_nostr_login_button_before_form' ) );
        
        // Add login button to WordPress login page - use higher priority to appear at top
        add_action( 'login_form', array( $this, 'add_nostr_login_button_wp_login' ), 5 );
        
        // Register AJAX handlers
        add_action( 'wp_ajax_nostr_verify_login', array( $this, 'ajax_verify_nostr_login' ) );
        add_action( 'wp_ajax_nostr_verify_nwc', array( $this, 'ajax_verify_nwc' ) );
        add_action( 'wp_ajax_nopriv_nostr_verify_login', array( $this, 'ajax_verify_nostr_login' ) ); // nopriv for non-logged users
        add_action( 'wp_ajax_nopriv_nostr_verify_nwc', array( $this, 'ajax_verify_nwc' ) );

        // Declare WooCommerce HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'nostr-outbox-wordpress', false, dirname( NOSTR_LOGIN_PAY_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Include required files
     */
    private function includes() {
        $includes = array(
            'includes/class-nostr-auth.php',
            'includes/class-nwc-wallet.php',
            'includes/class-nwc-client.php',
            'includes/class-lnurl-service.php',
            'includes/class-payment-webhook.php',
            'includes/class-nwc-php-client.php',
            'includes/class-admin-settings.php',
            'includes/class-user-profile.php',
            'includes/class-nip05-verification.php',
            'includes/class-nostr-notifications.php',
            'includes/class-nostr-profile-sync.php',
            'includes/class-nostr-connect.php',
            'includes/class-dm-admin.php',
            'includes/class-nostr-crypto-php.php',
            'includes/class-zap-rewards-processor.php',
            'includes/class-zap-rewards.php',
            'includes/class-zap-rewards-admin.php',
        );

        foreach ( $includes as $file ) {
            $filepath = NOSTR_LOGIN_PAY_PLUGIN_DIR . $file;
            if ( file_exists( $filepath ) ) {
                require_once $filepath;
            }
        }

        // WooCommerce gateway files are loaded separately in plugins_loaded hook
        // See nostr_login_pay_load_gateway() function below
    }

    /**
     * Initialize components
     */
    public function init_components() {
        // Initialize admin settings
        if ( is_admin() && class_exists( 'Nostr_Login_Pay_Admin_Settings' ) ) {
            Nostr_Login_Pay_Admin_Settings::instance();
        }

        // Relay settings integrated into main settings (removed separate class)

        // Initialize user profile fields
        if ( class_exists( 'Nostr_Login_Pay_User_Profile' ) ) {
            Nostr_Login_Pay_User_Profile::instance();
        }

        // Initialize NIP-05 verification
        if ( class_exists( 'Nostr_Login_Pay_NIP05' ) ) {
            Nostr_Login_Pay_NIP05::instance();
        }

        // Initialize Nostr notifications
        if ( class_exists( 'Nostr_Login_Pay_Notifications' ) ) {
            Nostr_Login_Pay_Notifications::instance();
        }

        // Initialize profile sync
        if ( class_exists( 'Nostr_Login_Pay_Profile_Sync' ) ) {
            Nostr_Login_Pay_Profile_Sync::instance();
        }

        // Initialize Nostr connect
        if ( class_exists( 'Nostr_Login_Pay_Connect' ) ) {
            Nostr_Login_Pay_Connect::instance();
        }

        // Initialize DM Admin
        if ( class_exists( 'Nostr_Login_Pay_DM_Admin' ) ) {
            Nostr_Login_Pay_DM_Admin::instance();
        }

        // Initialize Zap Rewards
        if ( class_exists( 'Nostr_Outbox_Zap_Rewards' ) ) {
            Nostr_Outbox_Zap_Rewards::instance();
        }

        // Initialize Zap Rewards Admin
        if ( is_admin() && class_exists( 'Nostr_Outbox_Zap_Rewards_Admin' ) ) {
            Nostr_Outbox_Zap_Rewards_Admin::instance();
        }
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Load nostr-login polyfill (for Mobile/Amber support)
        wp_enqueue_script(
            'nostr-login-polyfill',
            'https://www.unpkg.com/nostr-login@latest/dist/unpkg.js',
            array(),
            NOSTR_LOGIN_PAY_VERSION,
            false // Head
        );

        // Add attributes to nostr-login script
        add_filter( 'script_loader_tag', function( $tag, $handle ) {
            if ( 'nostr-login-polyfill' !== $handle ) {
                return $tag;
            }
            return str_replace( ' src', ' data-no-banner="true" src', $tag );
        }, 10, 2 );

        // Load nostr-tools FIRST (Alby SDK needs it!)
        // Using 1.17.0 - last v1.x version with bundle (compatible with Alby SDK)
        wp_enqueue_script(
            'nostr-tools',
            'https://unpkg.com/nostr-tools@1.17.0/lib/nostr.bundle.js',
            array(),
            '1.17.0',
            false // Load in head so it's ready for Alby SDK
        );
        
        // Enqueue Alby NWC SDK (depends on nostr-tools)
        wp_enqueue_script(
            'alby-nwc-sdk',
            'https://cdn.jsdelivr.net/npm/@getalby/sdk@3.6.1/dist/index.umd.js',
            array( 'nostr-tools' ), // Dependency!
            '3.6.1',
            false // Load in head
        );
        
        // Add minimal compatibility shim - v1.x has native Alby SDK support!
        wp_add_inline_script(
            'alby-nwc-sdk',
            "
            // Expose nostr-tools v1.x for Alby SDK (already compatible!)
            (function() {
                if (typeof window.NostrTools !== 'undefined') {
                    console.log('✅ nostr-tools v1.x loaded - native Alby SDK compatibility!');
                    window.nostrTools = window.NostrTools; // Expose for SDK
                    console.log('✅ Ready for NWC payments!');
                }
            })();
            ",
            'before'
        );

        // Enqueue main frontend script
        wp_enqueue_script(
            'nostr-outbox-wordpress-frontend',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/frontend.js',
            array( 'jquery', 'nostr-tools' ),
            NOSTR_LOGIN_PAY_VERSION,
            true
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script(
            'nostr-outbox-wordpress-frontend',
            'nostrLoginPay',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'nostr-outbox-wordpress-nonce' ),
                'siteUrl' => get_site_url(),
                'siteName' => get_bloginfo( 'name' ),
            )
        );

        // Enqueue frontend styles
        wp_enqueue_style(
            'nostr-outbox-wordpress-frontend',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            NOSTR_LOGIN_PAY_VERSION
        );

        // Enqueue DM sender script (for admins only, to process queued DMs)
        if ( current_user_can( 'manage_options' ) ) {
            wp_enqueue_script(
                'nostr-dm-sender',
                NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/nostr-dm-sender.js',
                array( 'nostr-tools' ),
                NOSTR_LOGIN_PAY_VERSION,
                true
            );
            
            wp_localize_script(
                'nostr-dm-sender',
                'nostrDMData',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'nostr-dm-sender' ),
                    'isAdmin' => '1',
                )
            );
        }

        // Enqueue Chat Widget if enabled
        $chat_enabled = get_option( 'nostr_login_pay_enable_chat' );
        if ( $chat_enabled ) {
            wp_enqueue_style(
                'nostr-chat-widget',
                NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/css/chat-widget.css',
                array(),
                NOSTR_LOGIN_PAY_VERSION
            );

            wp_enqueue_script(
                'nostr-chat-widget',
                NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/chat-widget.js',
                array( 'jquery', 'nostr-tools' ),
                NOSTR_LOGIN_PAY_VERSION,
                true
            );
            
            // Get support npub
            $support_npub = get_option( 'nostr_login_pay_support_npub' );
            if ( empty( $support_npub ) ) {
                // Try to get site identity
                $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
                if ( ! empty( $site_privkey ) && class_exists( 'Nostr_Login_Pay_Crypto_PHP' ) ) {
                    try {
                        $pubkey = Nostr_Login_Pay_Crypto_PHP::get_public_key( $site_privkey );
                        if ( $pubkey ) {
                            $support_npub = $pubkey;
                        }
                    } catch ( Exception $e ) {
                        // Ignore
                    }
                }
            }

            // Get relays
            $relays_option = get_option( 'nostr_login_pay_relays' );
            $relays = array();
            if ( ! empty( $relays_option ) ) {
                if ( is_array( $relays_option ) ) {
                    $relays = $relays_option;
                } else if ( is_string( $relays_option ) ) {
                    $relays = array_filter( array_map( 'trim', explode( "\n", $relays_option ) ) );
                }
            }
            if ( empty( $relays ) ) {
                $relays = array(
                    'wss://relay.damus.io',
                    'wss://relay.snort.social',
                    'wss://nos.lol',
                );
            }

            wp_localize_script(
                'nostr-chat-widget',
                'nostrChatData',
                array(
                    'enabled' => true,
                    'support_npub' => $support_npub,
                    'relays' => $relays,
                )
            );
        }
    }

    /**
     * Enqueue assets for WordPress login page
     */
    public function enqueue_login_assets() {
        // Load nostr-tools FIRST (Alby SDK needs it!)
        // Using 1.17.0 - last v1.x version with bundle (compatible with Alby SDK)
        wp_enqueue_script(
            'nostr-tools',
            'https://unpkg.com/nostr-tools@1.17.0/lib/nostr.bundle.js',
            array(),
            '1.17.0',
            false // Load in head
        );
        
        // Enqueue Alby NWC SDK (depends on nostr-tools)
        wp_enqueue_script(
            'alby-nwc-sdk',
            'https://cdn.jsdelivr.net/npm/@getalby/sdk@3.6.1/dist/index.umd.js',
            array( 'nostr-tools' ), // Dependency!
            '3.6.1',
            false // Load in head
        );
        
        // Add minimal compatibility shim - v1.x has native Alby SDK support!
        wp_add_inline_script(
            'alby-nwc-sdk',
            "
            // Expose nostr-tools v1.x for Alby SDK (already compatible!)
            (function() {
                if (typeof window.NostrTools !== 'undefined') {
                    console.log('✅ nostr-tools v1.x loaded - native Alby SDK compatibility!');
                    window.nostrTools = window.NostrTools; // Expose for SDK
                    console.log('✅ Ready for NWC payments!');
                }
            })();
            ",
            'before'
        );

        // Enqueue main frontend script
        wp_enqueue_script(
            'nostr-outbox-wordpress-frontend',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/frontend.js',
            array( 'jquery', 'nostr-tools' ),
            NOSTR_LOGIN_PAY_VERSION,
            true
        );

        // Localize script
        wp_localize_script(
            'nostr-outbox-wordpress-frontend',
            'nostrLoginPay',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'nostr-outbox-wordpress-nonce' ),
                'siteUrl' => get_site_url(),
                'siteName' => get_bloginfo( 'name' ),
            )
        );

        // Enqueue styles
        wp_enqueue_style(
            'nostr-outbox-wordpress-frontend',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            NOSTR_LOGIN_PAY_VERSION
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets( $hook ) {
        // Load nostr-tools on admin pages for key generation
        if ( strpos( $hook, 'nostr-outbox-wordpress' ) !== false || strpos( $hook, 'nostr-dms' ) !== false ) {
            wp_enqueue_script(
                'nostr-tools',
                'https://unpkg.com/nostr-tools@1.17.0/lib/nostr.bundle.js',
                array(),
                '1.17.0',
                false
            );
        }
        // Only load on our settings page
        if ( 'settings_page_nostr-outbox-wordpress' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'nostr-outbox-wordpress-admin',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            NOSTR_LOGIN_PAY_VERSION,
            true
        );

        wp_enqueue_style(
            'nostr-outbox-wordpress-admin',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            NOSTR_LOGIN_PAY_VERSION
        );

        // Enqueue Admin Conversations UI
        wp_enqueue_script(
            'nostr-admin-conversations',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/js/admin-conversations.js',
            array( 'jquery', 'nostr-tools' ),
            NOSTR_LOGIN_PAY_VERSION,
            true
        );

        wp_enqueue_style(
            'nostr-admin-conversations',
            NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/css/admin-conversations.css',
            array(),
            NOSTR_LOGIN_PAY_VERSION
        );

        // Localize data for conversations
        $sent_messages = get_option( 'nostr_dm_sent_log', array() );
        $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
        
        $relays_option = get_option( 'nostr_login_pay_relays' );
        $relays = array(
            'wss://relay.damus.io',
            'wss://nos.lol',
            'wss://relay.primal.net',
            'wss://blastr.f7z.io',
        );
        if ( ! empty( $relays_option ) ) {
             if ( is_array( $relays_option ) ) {
                 $relays = $relays_option;
             } else if ( is_string( $relays_option ) ) {
                 $relays = array_filter( array_map( 'trim', explode( "\n", $relays_option ) ) );
             }
         }

        wp_localize_script(
            'nostr-admin-conversations',
            'nostrChatAdminData',
            array(
                'sentMessages' => $sent_messages,
                'sitePrivkey' => $site_privkey,
                'relays' => $relays,
            )
        );
    }

    /**
     * Add Nostr login button to WooCommerce login form
     */
    public function add_nostr_login_button() {
        echo '<div class="nostr-login-container">';
        echo '<button type="button" class="nostr-login-button" id="nostr-login-btn">';
        echo '<svg width="20" height="20" viewBox="0 0 875 875" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0 0h875v875H0z"/><path d="M218.3 318.9c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V126.4c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#a)"/><path d="M218.3 556.1c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V363.6c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#b)"/><path d="M218.3 793.3c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V600.8c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#c)"/><defs><linearGradient id="a" x1="437.5" y1="96.7" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#8E2DE2"/><stop offset="1" stop-color="#4A00E0"/></linearGradient><linearGradient id="b" x1="437.5" y1="333.9" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#A259FF"/><stop offset="1" stop-color="#7B2CBF"/></linearGradient><linearGradient id="c" x1="437.5" y1="571.1" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#B583FF"/><stop offset="1" stop-color="#9D4EDD"/></linearGradient></defs></svg>';
        echo '<span>' . esc_html__( 'Login with Nostr', 'nostr-outbox-wordpress' ) . '</span>';
        echo '</button>';
        echo '<div class="nostr-login-status"></div>';
        echo '</div>';
    }

    /**
     * Add Nostr login button before login form
     */
    public function add_nostr_login_button_before_form() {
        // Check if Nostr login is enabled in settings
        if ( ! get_option( 'nostr_login_pay_enable_login', '1' ) ) {
            return;
        }
        
        if ( ! is_user_logged_in() ) {
            echo '<div class="nostr-login-wrapper">';
            echo '<div class="nostr-login-container">';
            echo '<button type="button" class="nostr-login-button" id="nostr-login-btn">';
            echo '<svg width="20" height="20" viewBox="0 0 875 875" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0 0h875v875H0z"/><path d="M218.3 318.9c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V126.4c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#a)"/><path d="M218.3 556.1c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V363.6c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#b)"/><path d="M218.3 793.3c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V600.8c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#c)"/><defs><linearGradient id="a" x1="437.5" y1="96.7" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#8E2DE2"/><stop offset="1" stop-color="#4A00E0"/></linearGradient><linearGradient id="b" x1="437.5" y1="333.9" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#A259FF"/><stop offset="1" stop-color="#7B2CBF"/></linearGradient><linearGradient id="c" x1="437.5" y1="571.1" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#B583FF"/><stop offset="1" stop-color="#9D4EDD"/></linearGradient></defs></svg>';
            echo '<span>' . esc_html__( 'Login with Nostr', 'nostr-outbox-wordpress' ) . '</span>';
            echo '</button>';
            echo '<div class="nostr-login-status"></div>';
            echo '</div>';
            echo '<div class="nostr-login-divider"><span>' . esc_html__( 'or', 'nostr-outbox-wordpress' ) . '</span></div>';
            echo '</div>';
        }
    }

    /**
     * Add Nostr login button to WordPress login page
     */
    public function add_nostr_login_button_wp_login() {
        // Check if Nostr login is enabled in settings
        if ( ! get_option( 'nostr_login_pay_enable_login', '1' ) ) {
            return;
        }
        
        // Note: This hook fires INSIDE the form, so we need to break out with JavaScript
        // or add via a different method. For now, we'll add it with inline styling.
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Move the Nostr button ABOVE the username field
            var $loginForm = $('#loginform');
            var $nostrButton = $('<div class="nostr-login-container" style="margin-bottom: 20px;">' +
                '<button type="button" class="nostr-login-button" id="nostr-login-btn" style="width: 100%; max-width: none;">' +
                '<svg width="20" height="20" viewBox="0 0 875 875" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0 0h875v875H0z"/><path d="M218.3 318.9c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V126.4c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#a)"/><path d="M218.3 556.1c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V363.6c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#b)"/><path d="M218.3 793.3c34.6-27.3 84.1-51.5 148.5-51.5 129.6 0 172.9 64.3 261.5 64.3 62.7 0 101.7-19.1 124.2-34.6V600.8c-22.5 15.5-61.5 34.6-124.2 34.6-88.6 0-131.9-64.3-261.5-64.3-64.4 0-113.9 24.2-148.5 51.5v170.7z" fill="url(#c)"/><defs><linearGradient id="a" x1="437.5" y1="96.7" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#8E2DE2"/><stop offset="1" stop-color="#4A00E0"/></linearGradient><linearGradient id="b" x1="437.5" y1="333.9" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#A259FF"/><stop offset="1" stop-color="#7B2CBF"/></linearGradient><linearGradient id="c" x1="437.5" y1="571.1" x2="437.5" y2="875" gradientUnits="userSpaceOnUse"><stop stop-color="#B583FF"/><stop offset="1" stop-color="#9D4EDD"/></linearGradient></defs></svg>' +
                '<span><?php esc_html_e( 'Login with Nostr', 'nostr-outbox-wordpress' ); ?></span>' +
                '</button>' +
                '<div class="nostr-login-status"></div>' +
                '</div>' +
                '<div class="nostr-login-divider" style="margin: 20px 0;"><span><?php esc_html_e( 'or', 'nostr-outbox-wordpress' ); ?></span></div>');
            
            $loginForm.prepend($nostrButton);
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for Nostr login verification
     */
    public function ajax_verify_nostr_login() {
        check_ajax_referer( 'nostr-outbox-wordpress-nonce', 'nonce' );

        if ( ! class_exists( 'Nostr_Login_Pay_Auth' ) ) {
            wp_send_json_error( array( 'message' => __( 'Authentication handler not available', 'nostr-outbox-wordpress' ) ) );
        }

        $pubkey = isset( $_POST['pubkey'] ) ? trim( $_POST['pubkey'] ) : '';
        $signature = isset( $_POST['signature'] ) ? sanitize_text_field( $_POST['signature'] ) : '';
        $event = isset( $_POST['event'] ) ? json_decode( stripslashes( $_POST['event'] ), true ) : array();

        if ( empty( $pubkey ) || empty( $signature ) || empty( $event ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing required data', 'nostr-outbox-wordpress' ) ) );
        }
        
        // Normalize pubkey to lowercase and validate
        $pubkey = strtolower( trim( $pubkey ) );
        if ( strlen( $pubkey ) !== 64 || ! ctype_xdigit( $pubkey ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid public key format', 'nostr-outbox-wordpress' ) ) );
        }
        
        // Verify event pubkey matches (if present)
        if ( ! empty( $event['pubkey'] ) ) {
            $event_pubkey = strtolower( trim( $event['pubkey'] ) );
            if ( $event_pubkey !== $pubkey ) {
                // Log warning but don't fail - some extensions might send different formats
                error_log( 'Nostr login - Pubkey mismatch: POST=' . $pubkey . ' Event=' . $event_pubkey );
            }
            // Use event pubkey if it's valid (more authoritative)
            if ( strlen( $event_pubkey ) === 64 && ctype_xdigit( $event_pubkey ) ) {
                $pubkey = $event_pubkey;
            }
        }
        
        // Log for debugging (remove in production)
        error_log( 'Nostr login - Final pubkey to store: ' . $pubkey );

        // Verify the Nostr signature
        $auth = new Nostr_Login_Pay_Auth();
        $verified = $auth->verify_nostr_event( $event, $signature );

        if ( ! $verified ) {
            wp_send_json_error( array( 'message' => __( 'Invalid signature', 'nostr-outbox-wordpress' ) ) );
        }

        // Find or create user
        $user = $auth->find_or_create_user( $pubkey );

        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array( 'message' => $user->get_error_message() ) );
        }

        // Log the user in
        wp_set_auth_cookie( $user->ID, true );
        do_action( 'wp_login', $user->user_login, $user );

        // Determine redirect URL based on settings
        $redirect_setting = get_option( 'nostr_login_pay_redirect_after_login', 'account' );
        
        switch ( $redirect_setting ) {
            case 'admin':
                $redirect_url = admin_url();
                break;
            case 'home':
                $redirect_url = home_url();
                break;
            case 'shop':
                $redirect_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url();
                break;
            case 'account':
            default:
                $redirect_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : admin_url();
                break;
        }

        wp_send_json_success( array(
            'message' => __( 'Successfully logged in!', 'nostr-outbox-wordpress' ),
            'redirect' => $redirect_url,
        ) );
    }

    /**
     * AJAX handler for NWC verification
     */
    public function ajax_verify_nwc() {
        check_ajax_referer( 'nostr-outbox-wordpress-nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        if ( ! class_exists( 'Nostr_Login_Pay_NWC_Wallet' ) ) {
            wp_send_json_error( array( 'message' => __( 'NWC wallet handler not available', 'nostr-outbox-wordpress' ) ) );
        }

        $nwc_url = isset( $_POST['nwc_url'] ) ? sanitize_text_field( $_POST['nwc_url'] ) : '';

        if ( empty( $nwc_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing NWC URL', 'nostr-outbox-wordpress' ) ) );
        }

        // Validate and save NWC connection
        $nwc = new Nostr_Login_Pay_NWC_Wallet();
        $result = $nwc->save_user_connection( get_current_user_id(), $nwc_url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'NWC wallet connected successfully!', 'nostr-outbox-wordpress' ),
            'balance' => $result['balance'],
        ) );
    }


    /**
     * Declare WooCommerce compatibility
     */
    public function declare_wc_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                __FILE__,
                true
            );
        }
    }
}

/**
 * Returns the main instance of the plugin
 */
function nostr_login_and_pay() {
    return Nostr_Login_And_Pay::instance();
}

// Initialize the plugin
nostr_login_and_pay();

/**
 * Load gateway class files on plugins_loaded
 * This ensures WooCommerce is fully loaded before we try to extend WC_Payment_Gateway
 * Priority 12 ensures we load after other payment gateway plugins (like Coinos at priority 11)
 */
add_action( 'plugins_loaded', 'nostr_login_pay_load_gateway', 12 );
function nostr_login_pay_load_gateway() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        // WooCommerce not active - payment features won't work but don't block the plugin
        return;
    }

    // Load WooCommerce gateway class
    if ( file_exists( NOSTR_LOGIN_PAY_PLUGIN_DIR . 'includes/woocommerce/class-wc-gateway-nwc.php' ) ) {
        require_once NOSTR_LOGIN_PAY_PLUGIN_DIR . 'includes/woocommerce/class-wc-gateway-nwc.php';
    }
}

/**
 * Add NWC gateway to WooCommerce
 * This filter must be registered at top level
 * Using priority 10 to load alongside other payment gateways
 */
add_filter( 'woocommerce_payment_gateways', 'nostr_login_pay_add_gateway', 10 );
function nostr_login_pay_add_gateway( $gateways ) {
    if ( ! is_array( $gateways ) ) {
        $gateways = array();
    }
    $gateways[] = 'WC_Gateway_NWC';
    return $gateways;
}

/**
 * Register blocks support for NWC gateway
 */
add_action( 'woocommerce_blocks_loaded', 'nostr_login_pay_register_blocks_support' );
function nostr_login_pay_register_blocks_support() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    require_once NOSTR_LOGIN_PAY_PLUGIN_DIR . 'includes/woocommerce/class-wc-gateway-nwc-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new WC_Gateway_NWC_Blocks_Support() );
        }
    );
}

/**
 * Pass payment method data to frontend for blocks
 */
add_filter( 'woocommerce_blocks_payment_method_data_registration', 'nostr_login_pay_add_payment_method_data' );
function nostr_login_pay_add_payment_method_data( $payment_method_data ) {
    $gateway_settings = get_option( 'woocommerce_nwc_settings', array() );
    
    if ( ! empty( $gateway_settings['enabled'] ) && $gateway_settings['enabled'] === 'yes' ) {
        $payment_method_data['nwc_data'] = array(
            'title' => isset( $gateway_settings['title'] ) ? $gateway_settings['title'] : 'Lightning Network',
            'description' => isset( $gateway_settings['description'] ) ? $gateway_settings['description'] : 'Pay with Bitcoin Lightning Network via NWC',
            'icon' => NOSTR_LOGIN_PAY_PLUGIN_URL . 'assets/images/lightning-icon.svg',
            'supports' => array( 'products' ),
        );
    }
    
    return $payment_method_data;
}

/**
 * Activation hook - set default options
 */
function nostr_login_pay_activate() {
    // Set default options if they don't exist
    if ( get_option( 'nostr_login_pay_enable_login' ) === false ) {
        add_option( 'nostr_login_pay_enable_login', '1' );
    }
    if ( get_option( 'nostr_login_pay_enable_nwc' ) === false ) {
        add_option( 'nostr_login_pay_enable_nwc', '1' );
    }
    if ( get_option( 'nostr_login_pay_auto_create_account' ) === false ) {
        add_option( 'nostr_login_pay_auto_create_account', '1' );
    }
    if ( get_option( 'nostr_login_pay_nwc_enable_payment_gateway' ) === false ) {
        add_option( 'nostr_login_pay_nwc_enable_payment_gateway', '1' );
    }
    if ( get_option( 'nostr_login_pay_default_role' ) === false ) {
        add_option( 'nostr_login_pay_default_role', 'customer' );
    }
    if ( get_option( 'nostr_login_pay_relays' ) === false ) {
        add_option( 'nostr_login_pay_relays', "wss://relay.damus.io\nwss://relay.primal.net\nwss://nos.lol" );
    }
    if ( get_option( 'nostr_login_pay_nwc_payment_timeout' ) === false ) {
        add_option( 'nostr_login_pay_nwc_payment_timeout', 300 );
    }
    
    // Create Zap Rewards database table
    if ( class_exists( 'Nostr_Outbox_Zap_Rewards' ) ) {
        Nostr_Outbox_Zap_Rewards::activate();
    }
    
    // Flush rewrite rules to register custom endpoints
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'nostr_login_pay_activate' );

/**
 * Deactivation hook - clean up
 */
function nostr_login_pay_deactivate() {
    // Flush rewrite rules to clean up custom endpoints
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'nostr_login_pay_deactivate' );
