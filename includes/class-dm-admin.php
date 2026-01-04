<?php
/**
 * DM Admin Interface
 * 
 * Comprehensive admin UI for managing Nostr DMs
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles DM admin interface and management
 */
class Nostr_Login_Pay_DM_Admin {

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
        // Add DM Management page to admin menu
        add_action( 'admin_menu', array( $this, 'add_dm_admin_page' ) );
        
        // Add DM queue widget to dashboard
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
        
        // Add queue status to admin bar
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_status' ), 999 );
        
        // AJAX handlers
        add_action( 'wp_ajax_regenerate_site_nsec', array( $this, 'ajax_regenerate_nsec' ) );
        add_action( 'wp_ajax_send_manual_dm', array( $this, 'ajax_send_manual_dm' ) );
        add_action( 'wp_ajax_get_dm_outbox', array( $this, 'ajax_get_outbox' ) );
        add_action( 'wp_ajax_get_dm_inbox', array( $this, 'ajax_get_inbox' ) );
        add_action( 'wp_ajax_clear_dm_queue', array( $this, 'ajax_clear_queue' ) );
        add_action( 'wp_ajax_delete_dm_queue_item', array( $this, 'ajax_delete_queue_item' ) );
        add_action( 'wp_ajax_get_dm_queue_for_processing', array( $this, 'ajax_get_queue_for_processing' ) );
        add_action( 'wp_ajax_mark_dm_as_sent', array( $this, 'ajax_mark_dm_as_sent' ) );
        
        // WP-Cron for DM processing
        add_action( 'nostr_process_dm_queue', array( $this, 'process_dm_queue_cron' ) );
        
        // Schedule cron if not scheduled
        if ( ! wp_next_scheduled( 'nostr_process_dm_queue' ) ) {
            wp_schedule_event( time(), 'every_5_minutes', 'nostr_process_dm_queue' );
        }
        
        // Add custom cron schedule
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
    }

    /**
     * Add custom cron schedule (every 5 minutes)
     */
    public function add_cron_schedule( $schedules ) {
        $schedules['every_5_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display'  => __( 'Every 5 Minutes', 'nostr-outbox-wordpress' ),
        );
        return $schedules;
    }

    /**
     * Add DM management page to admin menu
     * NOTE: Integrated into main settings as tabs instead
     */
    public function add_dm_admin_page() {
        // Don't add separate menu - integrated into main settings
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'nostr_dm_queue_widget',
            '💬 Nostr DM Queue',
            array( $this, 'render_dashboard_widget' )
        );
    }

    /**
     * Add queue status to admin bar
     */
    public function add_admin_bar_status( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $queue = get_option( 'nostr_dm_queue', array() );
        $count = is_array( $queue ) ? count( $queue ) : 0;
        
        $title = '💬 DM Queue: ' . $count;
        if ( $count > 0 ) {
            $title = '<span style="color: #f59e0b;">' . $title . '</span>';
        }
        
        $wp_admin_bar->add_node( array(
            'id'    => 'nostr-dm-queue',
            'title' => $title,
            'href'  => admin_url( 'options-general.php?page=nostr-outbox-wordpress&tab=dm&dmtab=queue' ),
        ) );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $queue = get_option( 'nostr_dm_queue', array() );
        $count = is_array( $queue ) ? count( $queue ) : 0;
        
        $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
        $has_keys = ! empty( $site_privkey );
        
        ?>
        <div style="padding: 10px;">
            <p style="font-size: 14px; margin: 10px 0;">
                <strong>Queue Status:</strong> 
                <?php if ( $count > 0 ) : ?>
                    <span style="color: #f59e0b; font-weight: bold;"><?php echo $count; ?> message(s) waiting</span>
                <?php else : ?>
                    <span style="color: #10b981;">No messages in queue</span>
                <?php endif; ?>
            </p>
            
            <p style="font-size: 14px; margin: 10px 0;">
                <strong>Site Keys:</strong> 
                <?php if ( $has_keys ) : ?>
                    <span style="color: #10b981;">✓ Configured</span>
                <?php else : ?>
                    <span style="color: #ef4444;">✗ Not generated yet</span>
                <?php endif; ?>
            </p>
            
            <p style="font-size: 14px; margin: 10px 0;">
                <strong>Processing:</strong> 
                <span style="color: #6b7280;">WP-Cron (every 5 minutes)</span>
            </p>
            
            <div style="margin-top: 15px;">
                <a href="<?php echo admin_url( 'options-general.php?page=nostr-outbox-wordpress&tab=dm' ); ?>" class="button button-primary">
                    Manage DMs
                </a>
                <?php if ( $count > 0 ) : ?>
                    <button type="button" class="button" onclick="nostrTriggerDMProcess()">
                        Send Now
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        function nostrTriggerDMProcess() {
            if (confirm('Process DM queue now?')) {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'trigger_dm_process',
                        nonce: '<?php echo wp_create_nonce( 'trigger-dm-process' ); ?>'
                    })
                })
                .then(r => r.json())
                .then(data => {
                    alert(data.success ? 'Queue processed!' : 'Error: ' + data.data.message);
                    location.reload();
                });
            }
        }
        </script>
        <?php
    }

    /**
     * Render DM tabs (called from main settings page)
     */
    public function render_dm_tabs() {
        $dm_tab = isset( $_GET['dmtab'] ) ? sanitize_text_field( $_GET['dmtab'] ) : 'messages';
        
        ?>
        <div style="margin-top: 20px;">
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=messages" class="nav-tab <?php echo $dm_tab === 'messages' ? 'nav-tab-active' : ''; ?>">
                    💬 <?php _e( 'Messages', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=keys" class="nav-tab <?php echo $dm_tab === 'keys' ? 'nav-tab-active' : ''; ?>">
                    🔑 <?php _e( 'Site Keys', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=queue" class="nav-tab <?php echo $dm_tab === 'queue' ? 'nav-tab-active' : ''; ?>">
                    📋 <?php _e( 'Queue', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=outbox" class="nav-tab <?php echo $dm_tab === 'outbox' ? 'nav-tab-active' : ''; ?>">
                    📤 <?php _e( 'Outbox', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=inbox" class="nav-tab <?php echo $dm_tab === 'inbox' ? 'nav-tab-active' : ''; ?>">
                    📥 <?php _e( 'Inbox', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=compose" class="nav-tab <?php echo $dm_tab === 'compose' ? 'nav-tab-active' : ''; ?>">
                    ✍️ <?php _e( 'Compose', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=groupchat" class="nav-tab <?php echo $dm_tab === 'groupchat' ? 'nav-tab-active' : ''; ?>">
                    👥 <?php _e( 'Group Chat', 'nostr-outbox-wordpress' ); ?>
                </a>
            </h2>

            <?php
            switch ( $dm_tab ) {
                case 'messages':
                    $this->render_conversations_tab();
                    break;
                case 'keys':
                    $this->render_keys_tab();
                    break;
                case 'queue':
                    $this->render_queue_tab();
                    break;
                case 'outbox':
                    $this->render_outbox_tab();
                    break;
                case 'inbox':
                    $this->render_inbox_tab();
                    break;
                case 'compose':
                    $this->render_compose_tab();
                    break;
                case 'groupchat':
                    $this->render_groupchat_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render Conversations Tab (New Unified Interface)
     */
    public function render_conversations_tab() {
        ?>
        <div id="nostr-conversations-root">
            <div style="padding: 20px; text-align: center; color: #646970;">
                <span class="spinner is-active" style="float:none; margin:0 10px 0 0;"></span>
                <?php _e( 'Loading conversations...', 'nostr-outbox-wordpress' ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render DM admin page (legacy - no longer used)
     */
    public function render_dm_admin_page_legacy() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'keys';

        ?>
        <div class="wrap">
            <h1><?php _e( 'Nostr Direct Messages', 'nostr-outbox-wordpress' ); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=nostr-dms&tab=keys" class="nav-tab <?php echo $active_tab === 'keys' ? 'nav-tab-active' : ''; ?>">
                    🔑 <?php _e( 'Site Keys', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-dms&tab=outbox" class="nav-tab <?php echo $active_tab === 'outbox' ? 'nav-tab-active' : ''; ?>">
                    📤 <?php _e( 'Outbox', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-dms&tab=inbox" class="nav-tab <?php echo $active_tab === 'inbox' ? 'nav-tab-active' : ''; ?>">
                    📥 <?php _e( 'Inbox', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-dms&tab=compose" class="nav-tab <?php echo $active_tab === 'compose' ? 'nav-tab-active' : ''; ?>">
                    ✍️ <?php _e( 'Compose', 'nostr-outbox-wordpress' ); ?>
                </a>
                <a href="?page=nostr-dms&tab=queue" class="nav-tab <?php echo $active_tab === 'queue' ? 'nav-tab-active' : ''; ?>">
                    📋 <?php _e( 'Queue', 'nostr-outbox-wordpress' ); ?>
                </a>
            </h2>

            <?php
            switch ( $active_tab ) {
                case 'keys':
                    $this->render_keys_tab();
                    break;
                case 'outbox':
                    $this->render_outbox_tab();
                    break;
                case 'inbox':
                    $this->render_inbox_tab();
                    break;
                case 'compose':
                    $this->render_compose_tab();
                    break;
                case 'queue':
                    $this->render_queue_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render Site Keys tab
     */
    public function render_keys_tab() {
        $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
        
        if ( empty( $site_privkey ) ) {
            // Generate new keys
            $site_privkey = bin2hex( random_bytes( 32 ) );
            update_option( 'nostr_login_pay_site_privkey', $site_privkey );
        }
        
        ?>
        <div style="max-width: 800px; margin-top: 20px;">
            <h2><?php _e( 'Site Nostr Identity', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php _e( 'Your WordPress site has its own Nostr identity for sending DMs to users.', 'nostr-outbox-wordpress' ); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( 'Private Key (nsec)', 'nostr-outbox-wordpress' ); ?></th>
                    <td>
                        <input 
                            type="password" 
                            id="site-nsec" 
                            value="<?php echo esc_attr( $site_privkey ); ?>" 
                            readonly 
                            style="width: 500px; font-family: monospace;"
                        />
                        <button type="button" class="button" onclick="toggleNsecVisibility()">
                            <span id="nsec-toggle-text">Show</span>
                        </button>
                        <button type="button" class="button" onclick="copyToClipboard('site-nsec')">
                            Copy
                        </button>
                        <p class="description">
                            <?php _e( '⚠️ Keep this private! This is your site\'s Nostr private key.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e( 'Public Key (npub)', 'nostr-outbox-wordpress' ); ?></th>
                    <td>
                        <input 
                            type="text" 
                            id="site-npub" 
                            value="" 
                            readonly 
                            style="width: 500px; font-family: monospace;"
                        />
                        <button type="button" class="button" onclick="copyToClipboard('site-npub')">
                            Copy
                        </button>
                        <p class="description">
                            <?php _e( 'This is your site\'s public Nostr identity. Users can follow or message this npub.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e( 'Hex Public Key', 'nostr-outbox-wordpress' ); ?></th>
                    <td>
                        <input 
                            type="text" 
                            id="site-pubkey-hex" 
                            value="" 
                            readonly 
                            style="width: 500px; font-family: monospace; font-size: 11px;"
                        />
                        <button type="button" class="button" onclick="copyToClipboard('site-pubkey-hex')">
                            Copy
                        </button>
                    </td>
                </tr>
            </table>
            
            <div style="margin-top: 30px; padding: 20px; background: #fef3c7; border-left: 4px solid #f59e0b;">
                <h3 style="margin-top: 0;"><?php _e( '⚙️ Advanced Actions', 'nostr-outbox-wordpress' ); ?></h3>
                <p><?php _e( 'Regenerate your site\'s Nostr keys. This will change your site\'s identity!', 'nostr-outbox-wordpress' ); ?></p>
                <button type="button" class="button button-secondary" onclick="regenerateKeys()">
                    🔄 <?php _e( 'Regenerate Keys', 'nostr-outbox-wordpress' ); ?>
                </button>
                <p class="description">
                    <?php _e( '⚠️ Warning: Users will see DMs from a new sender after regenerating.', 'nostr-outbox-wordpress' ); ?>
                </p>
            </div>
        </div>
        
        <script>
        // Generate npub on page load
        (function() {
            if (typeof window.NostrTools === 'undefined') {
                console.error('NostrTools not loaded');
                return;
            }
            
            const privkey = '<?php echo esc_js( $site_privkey ); ?>';
            try {
                const pubkey = window.NostrTools.getPublicKey(privkey);
                const npub = window.NostrTools.nip19.npubEncode(pubkey);
                
                document.getElementById('site-npub').value = npub;
                document.getElementById('site-pubkey-hex').value = pubkey;
            } catch(e) {
                console.error('Error generating npub:', e);
            }
        })();
        
        function toggleNsecVisibility() {
            const input = document.getElementById('site-nsec');
            const button = document.getElementById('nsec-toggle-text');
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = 'Hide';
            } else {
                input.type = 'password';
                button.textContent = 'Show';
            }
        }
        
        function copyToClipboard(elementId) {
            const input = document.getElementById(elementId);
            input.select();
            document.execCommand('copy');
            alert('Copied to clipboard!');
        }
        
        function regenerateKeys() {
            if (!confirm('Are you SURE you want to regenerate your site\'s Nostr keys?\n\nThis will change your site\'s identity and users will see DMs from a new sender.')) {
                return;
            }
            
            if (!confirm('Last chance! This action cannot be undone.\n\nRegenerate keys?')) {
                return;
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'regenerate_site_nsec',
                    nonce: '<?php echo wp_create_nonce( 'regenerate-site-nsec' ); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Keys regenerated! Page will reload.');
                    location.reload();
                } else {
                    alert('Error: ' + (data.data?.message || 'Unknown error'));
                }
            });
        }
        </script>
        <?php
    }

    /**
     * Render Outbox tab
     */
    public function render_outbox_tab() {
        ?>
        <div style="margin-top: 20px;">
            <h2><?php _e( 'Sent Messages', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php _e( 'View recently sent Nostr DMs from your site.', 'nostr-outbox-wordpress' ); ?></p>
            
            <div id="dm-outbox-container">
                <p><?php _e( 'Loading...', 'nostr-outbox-wordpress' ); ?></p>
            </div>
        </div>
        
        <script>
        // Load outbox
        (function() {
            fetch(ajaxurl + '?action=get_dm_outbox&nonce=<?php echo wp_create_nonce( 'get-dm-outbox' ); ?>')
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('dm-outbox-container');
                    if (data.success && data.data.messages) {
                        const messages = data.data.messages;
                        if (messages.length === 0) {
                            container.innerHTML = '<p>No messages sent yet.</p>';
                        } else {
                            let html = '<table class="wp-list-table widefat fixed striped">';
                            html += '<thead><tr><th>Recipient</th><th>Subject</th><th>Time</th><th>Event ID</th><th>Status</th></tr></thead><tbody>';
                            messages.forEach(msg => {
                                const recipientDisplay = msg.recipient_display || (msg.recipient.substring(0, 16) + '...');
                                const eventIdShort = msg.event_id ? (msg.event_id.substring(0, 8) + '...') : 'N/A';
                                html += `<tr>
                                    <td>
                                        <strong>${recipientDisplay}</strong><br>
                                        <code style="font-size: 11px; color: #666;">${msg.recipient.substring(0, 16)}...</code>
                                    </td>
                                    <td>${msg.subject || 'No subject'}</td>
                                    <td>${msg.time}</td>
                                    <td><code style="font-size: 11px;">${eventIdShort}</code></td>
                                    <td><span style="color: #10b981;">✓ Sent</span></td>
                                </tr>`;
                            });
                            html += '</tbody></table>';
                            container.innerHTML = html;
                        }
                    } else {
                        container.innerHTML = '<p>Error loading messages.</p>';
                    }
                });
        })();
        </script>
        <?php
    }

    /**
     * Render Inbox tab
     */
    public function render_inbox_tab() {
        $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
        if ( empty( $site_privkey ) ) {
            $site_privkey = bin2hex( random_bytes( 32 ) );
            update_option( 'nostr_login_pay_site_privkey', $site_privkey );
        }
        
        $relays = get_option( 'nostr_login_pay_relays', array(
            'wss://relay.damus.io',
            'wss://relay.snort.social',
            'wss://nos.lol',
            'wss://relay.nostr.band',
        ) );
        ?>
        <div style="margin-top: 20px;">
            <h2><?php _e( '📥 Incoming Messages', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php _e( 'DMs received by your site. Users can reply to site notifications here.', 'nostr-outbox-wordpress' ); ?></p>
            
            <p>
                <button type="button" class="button" onclick="fetchInboxMessages()">
                    🔄 <?php _e( 'Refresh Inbox', 'nostr-outbox-wordpress' ); ?>
                </button>
                <span id="inbox-status" style="margin-left: 15px; color: #666;"></span>
            </p>
            
            <div id="dm-inbox-container">
                <p><?php _e( 'Click "Refresh Inbox" to check for new messages...', 'nostr-outbox-wordpress' ); ?></p>
            </div>
        </div>
        
        <script>
        const INBOX_RELAYS = <?php echo json_encode( $relays ); ?>;
        const SITE_PRIVKEY = '<?php echo esc_js( $site_privkey ); ?>';
        let SITE_PUBKEY = '';
        
        // Get site pubkey from privkey
        if (typeof window.NostrTools !== 'undefined' && window.NostrTools.getPublicKey) {
            SITE_PUBKEY = window.NostrTools.getPublicKey(SITE_PRIVKEY);
            console.log('Site pubkey for inbox:', SITE_PUBKEY);
        }
        
        async function fetchInboxMessages() {
            if (!window.NostrTools || !window.NostrTools.SimplePool) {
                document.getElementById('dm-inbox-container').innerHTML = '<p style="color: #dc2626;">⚠️ nostr-tools not loaded. Cannot fetch messages.</p>';
                return;
            }
            
            if (!SITE_PUBKEY) {
                document.getElementById('dm-inbox-container').innerHTML = '<p style="color: #dc2626;">⚠️ Site public key not available.</p>';
                return;
            }
            
            document.getElementById('inbox-status').textContent = '🔄 Checking relays...';
            document.getElementById('dm-inbox-container').innerHTML = '<p>📡 Connecting to relays and fetching DMs...</p>';
            
            try {
                const pool = new window.NostrTools.SimplePool();
                
                // Filter for kind 4 (DM) events sent TO our site pubkey
                const filter = {
                    kinds: [4],
                    '#p': [SITE_PUBKEY],
                    limit: 50,
                    since: Math.floor(Date.now() / 1000) - (7 * 24 * 60 * 60) // Last 7 days
                };
                
                console.log('Fetching DMs with filter:', filter);
                
                const events = await pool.list(INBOX_RELAYS, [filter]);
                console.log(`Found ${events.length} DM events`);
                
                if (events.length === 0) {
                    document.getElementById('dm-inbox-container').innerHTML = '<p style="color: #666;">📭 No messages found. (Last 7 days)</p>';
                    document.getElementById('inbox-status').textContent = '✓ No messages';
                    pool.close(INBOX_RELAYS);
                    return;
                }
                
                // Decrypt and display messages
                const messages = [];
                for (const event of events) {
                    try {
                        // Decrypt content (NIP-04)
                        const senderPubkey = event.pubkey;
                        const decrypted = await window.NostrTools.nip04.decrypt(
                            SITE_PRIVKEY,
                            senderPubkey,
                            event.content
                        );
                        
                        messages.push({
                            id: event.id,
                            from: senderPubkey,
                            content: decrypted,
                            time: new Date(event.created_at * 1000).toLocaleString(),
                            timestamp: event.created_at
                        });
                    } catch (e) {
                        console.error('Failed to decrypt DM:', e);
                    }
                }
                
                // Sort by timestamp (newest first)
                messages.sort((a, b) => b.timestamp - a.timestamp);
                
                // Display messages
                let html = '<table class="wp-list-table widefat fixed striped">';
                html += '<thead><tr><th style="width: 200px;">From</th><th>Message</th><th style="width: 150px;">Time</th><th style="width: 80px;">Reply</th></tr></thead><tbody>';
                
                messages.forEach(msg => {
                    const fromShort = msg.from.substring(0, 16) + '...';
                    const contentPreview = msg.content.length > 200 ? 
                        msg.content.substring(0, 200) + '...' : 
                        msg.content;
                    const contentEscaped = contentPreview.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                    
                    html += `<tr>
                        <td>
                            <code style="font-size: 11px;" title="${msg.from}">${fromShort}</code>
                        </td>
                        <td style="max-width: 500px; word-wrap: break-word;">
                            ${contentEscaped}
                        </td>
                        <td>${msg.time}</td>
                        <td>
                            <button type="button" class="button button-small" onclick="replyToMessage('${msg.from}', '${msg.id}')">
                                ↩️ Reply
                            </button>
                        </td>
                    </tr>`;
                });
                
                html += '</tbody></table>';
                document.getElementById('dm-inbox-container').innerHTML = html;
                document.getElementById('inbox-status').innerHTML = `✓ ${messages.length} message(s) found`;
                
                pool.close(INBOX_RELAYS);
                
            } catch (e) {
                console.error('Error fetching inbox:', e);
                document.getElementById('dm-inbox-container').innerHTML = '<p style="color: #dc2626;">⚠️ Error fetching messages: ' + e.message + '</p>';
                document.getElementById('inbox-status').textContent = '✗ Error';
            }
        }
        
        function replyToMessage(recipientPubkey, replyToEventId) {
            // Navigate to compose tab with recipient pre-filled
            const url = new URL(window.location.href);
            url.searchParams.set('dmtab', 'compose');
            url.searchParams.set('reply_to', recipientPubkey);
            url.searchParams.set('reply_event', replyToEventId);
            window.location.href = url.toString();
        }
        
        // Auto-load if coming from a fresh page load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-fetch on load (optional)
            // fetchInboxMessages();
        });
        </script>
        <?php
    }

    /**
     * Render Compose tab
     */
    public function render_compose_tab() {
        // Get all users with Nostr pubkeys
        $users = get_users( array(
            'meta_key' => 'nostr_pubkey',
            'fields' => array( 'ID', 'display_name', 'user_login' ),
        ) );
        
        ?>
        <div style="max-width: 800px; margin-top: 20px;">
            <h2><?php _e( 'Send Manual DM', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php _e( 'Send a direct message to any user or npub.', 'nostr-outbox-wordpress' ); ?></p>
            
            <form id="compose-dm-form" style="margin-top: 20px;">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dm-recipient"><?php _e( 'To', 'nostr-outbox-wordpress' ); ?></label>
                        </th>
                        <td>
                            <select id="dm-recipient-select" style="width: 400px;">
                                <option value="">-- <?php _e( 'Select User', 'nostr-outbox-wordpress' ); ?> --</option>
                                <?php foreach ( $users as $user ) : ?>
                                    <?php $pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true ); ?>
                                    <option value="<?php echo esc_attr( $pubkey ); ?>">
                                        <?php echo esc_html( $user->display_name . ' (@' . $user->user_login . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom">-- <?php _e( 'Enter custom npub/pubkey', 'nostr-outbox-wordpress' ); ?> --</option>
                            </select>
                            
                            <div id="dm-custom-recipient" style="display: none; margin-top: 10px;">
                                <input 
                                    type="text" 
                                    id="dm-recipient-custom" 
                                    placeholder="npub1... or hex pubkey"
                                    style="width: 400px; font-family: monospace;"
                                />
                            </div>
                            
                            <?php
                            // Show groups section if any groups exist
                            $groups = get_option( 'nostr_group_chats', array() );
                            $enabled_groups = array_filter( $groups, function( $g ) { return ! empty( $g['enabled'] ) && $g['enabled'] === '1'; } );
                            if ( ! empty( $enabled_groups ) ) :
                            ?>
                                <div style="margin-top: 20px; padding: 15px; background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
                                    <h4 style="margin: 0 0 10px 0;">👥 <?php _e( 'Or send to groups:', 'nostr-outbox-wordpress' ); ?></h4>
                                    <p style="margin: 0 0 10px 0; color: #666; font-size: 13px;">
                                        <?php _e( 'Select one or more groups. Recipient selection above will be ignored if groups are selected.', 'nostr-outbox-wordpress' ); ?>
                                    </p>
                                    <?php foreach ( $enabled_groups as $group ) : ?>
                                        <?php
                                        $member_count = count( array_filter( explode( "\n", $group['members'] ) ) );
                                        ?>
                                        <label style="display: block; margin-bottom: 8px; padding: 8px; background: white; border-radius: 4px; cursor: pointer;">
                                            <input type="checkbox" name="group_recipients[]" value="<?php echo esc_attr( $group['id'] ); ?>" class="group-checkbox">
                                            <strong><?php echo esc_html( $group['name'] ); ?></strong>
                                            <span style="color: #666; font-size: 12px;">(<?php echo $member_count; ?> members)</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="dm-subject"><?php _e( 'Subject', 'nostr-outbox-wordpress' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="dm-subject" style="width: 400px;" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="dm-message"><?php _e( 'Message', 'nostr-outbox-wordpress' ); ?></label>
                        </th>
                        <td>
                            <textarea id="dm-message" rows="10" style="width: 100%; max-width: 600px;"></textarea>
                            <p class="description">
                                <?php _e( 'Message will be encrypted before sending (NIP-04).', 'nostr-outbox-wordpress' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        📤 <?php _e( 'Send DM', 'nostr-outbox-wordpress' ); ?>
                    </button>
                    <button type="button" class="button" onclick="clearComposeForm()">
                        <?php _e( 'Clear', 'nostr-outbox-wordpress' ); ?>
                    </button>
                </p>
                
                <div id="compose-status" style="margin-top: 15px;"></div>
            </form>
        </div>
        
        <script>
        // Show/hide custom recipient input
        document.getElementById('dm-recipient-select').addEventListener('change', function() {
            const customDiv = document.getElementById('dm-custom-recipient');
            customDiv.style.display = this.value === 'custom' ? 'block' : 'none';
        });
        
        // Check for reply_to parameter (from inbox)
        const urlParams = new URLSearchParams(window.location.search);
        const replyTo = urlParams.get('reply_to');
        const replyEvent = urlParams.get('reply_event');
        
        if (replyTo) {
            // Pre-fill custom recipient
            document.getElementById('dm-recipient-select').value = 'custom';
            document.getElementById('dm-custom-recipient').style.display = 'block';
            document.getElementById('dm-recipient-custom').value = replyTo;
            document.getElementById('dm-subject').value = 'Re: Your message';
            
            // Show notice
            const form = document.getElementById('compose-dm-form');
            const notice = document.createElement('div');
            notice.className = 'notice notice-info';
            notice.style.marginBottom = '20px';
            notice.innerHTML = '<p>📧 <strong>Replying to:</strong> <code>' + replyTo.substring(0, 16) + '...</code></p>';
            form.insertBefore(notice, form.firstChild);
        }
        
        // Handle form submission
        document.getElementById('compose-dm-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const select = document.getElementById('dm-recipient-select');
            let recipient = select.value;
            
            if (recipient === 'custom') {
                recipient = document.getElementById('dm-recipient-custom').value.trim();
            }
            
            // Check if any groups are selected
            const groupCheckboxes = document.querySelectorAll('.group-checkbox:checked');
            const selectedGroups = Array.from(groupCheckboxes).map(cb => cb.value);
            
            const subject = document.getElementById('dm-subject').value.trim();
            const message = document.getElementById('dm-message').value.trim();
            const status = document.getElementById('compose-status');
            
            // Validation: need either a recipient or at least one group
            if (!recipient && selectedGroups.length === 0) {
                status.innerHTML = '<div class="notice notice-error"><p>Please select a recipient or at least one group.</p></div>';
                return;
            }
            
            if (!message) {
                status.innerHTML = '<div class="notice notice-error"><p>Please enter a message.</p></div>';
                return;
            }
            
            status.innerHTML = '<div class="notice notice-info"><p>Sending...</p></div>';
            
            const formData = new URLSearchParams({
                    action: 'send_manual_dm',
                    nonce: '<?php echo wp_create_nonce( 'send-manual-dm' ); ?>',
                recipient: recipient || '',
                    subject: subject,
                message: message,
                selected_groups: selectedGroups.join(',')
            });
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const count = data.data?.count || 1;
                    const groupCount = selectedGroups.length;
                    let msg;
                    
                    if (groupCount > 0 && recipient) {
                        msg = `<strong>✓ Message sent to 1 user and ${count} members across ${groupCount} group(s)!</strong> Messages queued and will be sent within 5 minutes.`;
                    } else if (groupCount > 0) {
                        msg = `<strong>✓ Message sent to ${count} members across ${groupCount} group(s)!</strong> Messages queued and will be sent within 5 minutes.`;
                    } else {
                        msg = '<strong>✓ DM queued!</strong> It will be sent within 5 minutes.';
                    }
                    
                    status.innerHTML = `<div class="notice notice-success"><p>${msg}</p></div>`;
                    clearComposeForm();
                } else {
                    status.innerHTML = '<div class="notice notice-error"><p>Error: ' + (data.data?.message || 'Unknown error') + '</p></div>';
                }
            });
        });
        
        function clearComposeForm() {
            document.getElementById('dm-recipient-select').value = '';
            document.getElementById('dm-recipient-custom').value = '';
            document.getElementById('dm-subject').value = '';
            document.getElementById('dm-message').value = '';
            document.getElementById('dm-custom-recipient').style.display = 'none';
            
            // Uncheck all group checkboxes
            document.querySelectorAll('.group-checkbox').forEach(cb => cb.checked = false);
        }
        </script>
        <?php
    }

    /**
     * Render Group Chat tab
     */
    public function render_groupchat_tab() {
        // Handle delete group
        if ( isset( $_GET['delete_group'] ) && check_admin_referer( 'delete-group-' . $_GET['delete_group'] ) ) {
            $groups = get_option( 'nostr_group_chats', array() );
            $group_id = sanitize_text_field( $_GET['delete_group'] );
            if ( isset( $groups[ $group_id ] ) ) {
                unset( $groups[ $group_id ] );
                update_option( 'nostr_group_chats', $groups );
                echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Group deleted successfully.', 'nostr-outbox-wordpress' ) . '</p></div>';
            }
        }

        // Handle form submission (add/edit group)
        if ( isset( $_POST['nostr_group_chat_submit'] ) && check_admin_referer( 'nostr_group_chat_save' ) ) {
            $groups = get_option( 'nostr_group_chats', array() );
            
            $group_id = ! empty( $_POST['group_id'] ) ? sanitize_text_field( $_POST['group_id'] ) : uniqid( 'group_' );
            
            $new_group = array(
                'id' => $group_id,
                'name' => sanitize_text_field( $_POST['group_name'] ),
                'enabled' => isset( $_POST['group_enabled'] ) ? '1' : '0',
                'members' => sanitize_textarea_field( $_POST['group_members'] ),
                'message_types' => isset( $_POST['message_types'] ) ? array_map( 'sanitize_text_field', $_POST['message_types'] ) : array(),
                'created_at' => isset( $groups[ $group_id ]['created_at'] ) ? $groups[ $group_id ]['created_at'] : time(),
            );
            
            $groups[ $group_id ] = $new_group;
            update_option( 'nostr_group_chats', $groups );
            
            echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Group saved successfully.', 'nostr-outbox-wordpress' ) . '</p></div>';
        }

        // Get all groups
        $groups = get_option( 'nostr_group_chats', array() );
        
        // Check if editing a specific group
        $edit_group_id = isset( $_GET['edit_group'] ) ? sanitize_text_field( $_GET['edit_group'] ) : '';
        $edit_group = ( $edit_group_id && isset( $groups[ $edit_group_id ] ) ) ? $groups[ $edit_group_id ] : null;
        
        ?>
        <div class="wrap nostr-group-chats">
            <h2 style="display: flex; align-items: center; gap: 10px;">
                👥 <?php _e( 'Group Chats', 'nostr-outbox-wordpress' ); ?>
                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=groupchat&add_group=1" class="page-title-action"><?php _e( 'Add New Group', 'nostr-outbox-wordpress' ); ?></a>
            </h2>
            <p><?php _e( 'Define groups of Nostr users to receive specific types of notifications.', 'nostr-outbox-wordpress' ); ?></p>
            
            <?php if ( isset( $_GET['add_group'] ) || $edit_group ) : ?>
                <div class="postbox" style="max-width: 800px; margin-top: 20px;">
                    <div class="postbox-header">
                        <h2 class="hndle">
                            <?php echo $edit_group ? __( 'Edit Group: ', 'nostr-outbox-wordpress' ) . esc_html( $edit_group['name'] ) : __( 'Add New Group', 'nostr-outbox-wordpress' ); ?>
                        </h2>
                    </div>
                    <div class="inside">
                        <form method="post" action="?page=nostr-outbox-wordpress&tab=dm&dmtab=groupchat">
                            <?php wp_nonce_field( 'nostr_group_chat_save' ); ?>
                            <input type="hidden" name="group_id" value="<?php echo $edit_group ? esc_attr( $edit_group['id'] ) : ''; ?>" />
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="group_name"><?php _e( 'Group Name', 'nostr-outbox-wordpress' ); ?></label></th>
                                    <td>
                                        <input name="group_name" type="text" id="group_name" value="<?php echo $edit_group ? esc_attr( $edit_group['name'] ) : ''; ?>" class="regular-text" required />
                                        <p class="description"><?php _e( 'Give this group a name for easy identification.', 'nostr-outbox-wordpress' ); ?></p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e( 'Status', 'nostr-outbox-wordpress' ); ?></th>
                                    <td>
                                        <label>
                                            <input name="group_enabled" type="checkbox" value="1" <?php checked( $edit_group ? $edit_group['enabled'] : '1', '1' ); ?> />
                                            <?php _e( 'Enable this group', 'nostr-outbox-wordpress' ); ?>
                                        </label>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><label for="group_members"><?php _e( 'Group Members', 'nostr-outbox-wordpress' ); ?></label></th>
                                    <td>
                                        <div style="margin-bottom: 10px; background: #f6f7f7; padding: 10px; border-radius: 4px; border: 1px solid #dcdcde;">
                                            <strong><?php _e( 'Quick Add Site Users:', 'nostr-outbox-wordpress' ); ?></strong><br>
                                            <select id="quick-add-user" style="max-width: 300px; margin-top: 5px;">
                                                <option value=""><?php _e( '-- Select a user with pubkey --', 'nostr-outbox-wordpress' ); ?></option>
                                                <?php
                                                $users = get_users( array( 'meta_key' => 'nostr_pubkey' ) );
                                                foreach ( $users as $user ) {
                                                    $pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
                                                    if ( ! empty( $pubkey ) ) {
                                                        printf( '<option value="%s">%s (@%s)</option>', esc_attr( $pubkey ), esc_html( $user->display_name ), esc_html( $user->user_login ) );
                                                    }
                                                }
                                                ?>
                                            </select>
                                            <button type="button" class="button" onclick="addSelectedUser()"><?php _e( 'Add', 'nostr-outbox-wordpress' ); ?></button>
                                        </div>
                                        
                                        <textarea name="group_members" id="group_members" rows="8" class="large-text code" placeholder="npub1... or hex pubkey (one per line)"><?php echo $edit_group ? esc_textarea( $edit_group['members'] ) : ''; ?></textarea>
                                        <p class="description">
                                            <?php _e( 'Enter Nostr public keys (npub or hex), one per line.', 'nostr-outbox-wordpress' ); ?>
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row"><?php _e( 'Message Types', 'nostr-outbox-wordpress' ); ?></th>
                                    <td>
                                        <p class="description" style="margin-bottom: 10px;">
                                            <?php _e( 'Select which types of notifications this group should receive:', 'nostr-outbox-wordpress' ); ?>
                                        </p>
                                        
                                        <?php
                                        $types = array(
                                            'woocommerce_orders' => array( 'label' => '🛍️ WooCommerce Orders', 'desc' => 'New orders and status updates' ),
                                            'new_users' => array( 'label' => '👤 New User Registrations', 'desc' => 'When new people join the site' ),
                                            'password_reset' => array( 'label' => '🔑 Password Resets', 'desc' => 'Security-related notifications' ),
                                            'comments' => array( 'label' => '💬 New Comments', 'desc' => 'When comments require moderation' ),
                                            'gig_notifications' => array( 'label' => '🛠️ Gig Notifications', 'desc' => 'New gigs, claims, and status changes' ),
                                            'admin_notifications' => array( 'label' => '📢 General Admin Notifications', 'desc' => 'Other system-wide administrator alerts' ),
                                        );
                                        
                                        $current_types = $edit_group ? $edit_group['message_types'] : array();
                                        
                                        foreach ( $types as $key => $type ) :
                                        ?>
                                            <div style="margin-bottom: 10px;">
                                                <label style="font-weight: 600;">
                                                    <input name="message_types[<?php echo esc_attr( $key ); ?>]" type="checkbox" value="1" <?php checked( isset( $current_types[ $key ] ) ? $current_types[ $key ] : '0', '1' ); ?> />
                                                    <?php echo esc_html( $type['label'] ); ?>
                                                </label><br>
                                                <span class="description" style="margin-left: 25px;"><?php echo esc_html( $type['desc'] ); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <input type="submit" name="nostr_group_chat_submit" id="submit" class="button button-primary" value="<?php echo $edit_group ? __( 'Update Group', 'nostr-outbox-wordpress' ) : __( 'Create Group', 'nostr-outbox-wordpress' ); ?>">
                                <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=groupchat" class="button"><?php _e( 'Cancel', 'nostr-outbox-wordpress' ); ?></a>
                            </p>
                        </form>
                    </div>
                </div>
                
                <script>
                function addSelectedUser() {
                    const select = document.getElementById('quick-add-user');
                    const textarea = document.getElementById('group_members');
                    const pubkey = select.value;
                    
                    if (!pubkey) return;
                    
                    const currentMembers = textarea.value.split('\n').map(m => m.trim()).filter(m => m !== '');
                    if (!currentMembers.includes(pubkey)) {
                        textarea.value += (textarea.value ? '\n' : '') + pubkey;
                    }
                    
                    select.value = '';
                }
                </script>
            <?php else : ?>
                <div style="margin-top: 20px;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-name"><?php _e( 'Group Name', 'nostr-outbox-wordpress' ); ?></th>
                                <th scope="col" class="manage-column column-status"><?php _e( 'Status', 'nostr-outbox-wordpress' ); ?></th>
                                <th scope="col" class="manage-column column-members"><?php _e( 'Members', 'nostr-outbox-wordpress' ); ?></th>
                                <th scope="col" class="manage-column column-types"><?php _e( 'Notification Types', 'nostr-outbox-wordpress' ); ?></th>
                                <th scope="col" class="manage-column column-actions"><?php _e( 'Actions', 'nostr-outbox-wordpress' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $groups ) ) : ?>
                                <tr>
                                    <td colspan="5"><?php _e( 'No group chats defined yet.', 'nostr-outbox-wordpress' ); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $groups as $id => $group ) : ?>
                                    <?php
                                    $member_lines = array_filter( explode( "\n", $group['members'] ) );
                                    $enabled_types = array();
                                    $types_map = array(
                                        'woocommerce_orders' => 'Orders',
                                        'new_users' => 'Users',
                                        'password_reset' => 'Password',
                                        'comments' => 'Comments',
                                        'gig_notifications' => 'Gigs',
                                        'admin_notifications' => 'Admin',
                                    );
                                    foreach ( $group['message_types'] as $key => $val ) {
                                        if ( $val === '1' && isset( $types_map[ $key ] ) ) {
                                            $enabled_types[] = $types_map[ $key ];
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $group['name'] ); ?></strong></td>
                                        <td>
                                            <?php if ( $group['enabled'] === '1' ) : ?>
                                                <span style="color: #0d9488; background: #f0fdfa; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;"><?php _e( 'ACTIVE', 'nostr-outbox-wordpress' ); ?></span>
                                            <?php else : ?>
                                                <span style="color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;"><?php _e( 'DISABLED', 'nostr-outbox-wordpress' ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo count( $member_lines ); ?> <?php _e( 'members', 'nostr-outbox-wordpress' ); ?></td>
                                        <td><?php echo ! empty( $enabled_types ) ? implode( ', ', $enabled_types ) : '<em>none</em>'; ?></td>
                                        <td>
                                            <a href="?page=nostr-outbox-wordpress&tab=dm&dmtab=groupchat&edit_group=<?php echo esc_attr( $id ); ?>" class="button button-small"><?php _e( 'Edit', 'nostr-outbox-wordpress' ); ?></a>
                                            <a href="<?php echo wp_nonce_url( '?page=nostr-outbox-wordpress&tab=dm&dmtab=groupchat&delete_group=' . $id, 'delete-group-' . $id ); ?>" class="button button-small" onclick="return confirm('<?php _e( 'Are you sure you want to delete this group?', 'nostr-outbox-wordpress' ); ?>')"><?php _e( 'Delete', 'nostr-outbox-wordpress' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Queue tab
     */
    public function render_queue_tab() {
        error_log( 'DM Admin: render_queue_tab called at ' . current_time( 'mysql' ) );
        $queue = get_option( 'nostr_dm_queue', array() );
        error_log( 'DM Admin: Queue retrieved for display, count: ' . ( is_array( $queue ) ? count( $queue ) : 'not array' ) );
        if ( is_array( $queue ) && ! empty( $queue ) ) {
            error_log( 'DM Admin: Queue contents: ' . print_r( $queue, true ) );
        }
        $count = is_array( $queue ) ? count( $queue ) : 0;
        
        ?>
        <div style="margin-top: 20px;">
            <h2><?php _e( 'DM Queue Status', 'nostr-outbox-wordpress' ); ?></h2>
            <p><?php _e( 'Messages waiting to be sent to Nostr relays.', 'nostr-outbox-wordpress' ); ?></p>
            
            <div style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0;">
                <h3 style="margin-top: 0;">📊 Queue Statistics</h3>
                <p><strong>Messages in queue:</strong> <?php echo $count; ?></p>
                <p><strong>Processing interval:</strong> Every 5 minutes (WP-Cron)</p>
                <p><strong>Next scheduled run:</strong> <?php echo date( 'Y-m-d H:i:s', wp_next_scheduled( 'nostr_process_dm_queue' ) ?: time() ); ?></p>
            </div>
            
            <?php if ( $count > 0 ) : ?>
                <h3><?php _e( 'Queued Messages', 'nostr-outbox-wordpress' ); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e( 'Recipient', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Subject', 'nostr-outbox-wordpress' ); ?></th>
                            <th><?php _e( 'Queued At', 'nostr-outbox-wordpress' ); ?></th>
                            <th style="width: 100px;"><?php _e( 'Actions', 'nostr-outbox-wordpress' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $queue as $dm ) : ?>
                            <?php $item_id = isset( $dm['id'] ) ? $dm['id'] : uniqid( 'dm_', true ); ?>
                            <tr id="dm-queue-item-<?php echo esc_attr( $item_id ); ?>">
                                <td>
                                    <?php if ( ! empty( $dm['username'] ) && $dm['username'] !== 'manual' ) : ?>
                                        <strong><?php echo esc_html( $dm['username'] ); ?></strong><br>
                                    <?php endif; ?>
                                    <code><?php echo esc_html( substr( $dm['recipient'], 0, 16 ) ); ?>...</code>
                                </td>
                                <td><?php echo esc_html( isset( $dm['subject'] ) ? $dm['subject'] : substr( $dm['message'], 0, 50 ) . '...' ); ?></td>
                                <td><?php echo human_time_diff( $dm['timestamp'], time() ); ?> ago</td>
                                <td>
                                    <button 
                                        type="button" 
                                        class="button button-small button-link-delete" 
                                        onclick="deleteQueueItem('<?php echo esc_js( $item_id ); ?>')"
                                        title="Delete this message">
                                        🗑️ Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p style="margin-top: 20px;">
                    <button type="button" class="button button-primary" onclick="processQueueNow()">
                        🚀 <?php _e( 'Process Queue Now', 'nostr-outbox-wordpress' ); ?>
                    </button>
                    <button type="button" class="button button-secondary" onclick="clearQueue()">
                        🗑️ <?php _e( 'Clear Queue', 'nostr-outbox-wordpress' ); ?>
                    </button>
                </p>
            <?php else : ?>
                <p style="color: #10b981; font-weight: bold;">✓ <?php _e( 'Queue is empty', 'nostr-outbox-wordpress' ); ?></p>
            <?php endif; ?>
        </div>
        
        <script>
        function processQueueNow() {
            if (!confirm('Process all queued messages now?')) {
                return;
            }
            
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Processing...';
            
            // Check if DM sender is available
            if (typeof window.NostrTools === 'undefined') {
                alert('NostrTools not loaded! Cannot send DMs.');
                btn.disabled = false;
                btn.textContent = '🚀 Process Queue Now';
                return;
            }
            
            // Get queue and site privkey
            fetch(ajaxurl + '?action=get_dm_queue_for_processing&nonce=<?php echo wp_create_nonce( 'process-dm-queue' ); ?>')
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.data.queue || data.data.queue.length === 0) {
                        alert('Queue is empty or error fetching queue');
                        btn.disabled = false;
                        btn.textContent = '🚀 Process Queue Now';
                        return;
                    }
                    
                    const queue = data.data.queue;
                    const privkey = data.data.privkey;
                    const relays = data.data.relays || [
                        'wss://relay.damus.io',
                        'wss://relay.snort.social',
                        'wss://nos.lol',
                        'wss://relay.nostr.band'
                    ];
                    
                    console.log('Processing', queue.length, 'DMs...');
                    
                    // Send each DM
                    return sendQueuedDMs(queue, privkey, relays);
                })
                .then(() => {
                    alert('✅ Queue processed successfully!\n\nMessages sent and logged to outbox.\n\nPage will reload to show updated queue.');
                    window.location.reload();
                })
                .catch(e => {
                    console.error('Error processing queue:', e);
                    alert('❌ Error processing queue: ' + e.message);
                    btn.disabled = false;
                    btn.textContent = '🚀 Process Queue Now';
                });
        }
        
        async function sendQueuedDMs(queue, privkey, relays) {
            const pool = new window.NostrTools.SimplePool();
            const pubkey = window.NostrTools.getPublicKey(privkey);
            
            let sent = 0;
            let failed = 0;
            
            for (const dm of queue) {
                try {
                    console.log('📨 Sending DM to ' + dm.username + ' (' + dm.recipient.substring(0, 16) + '...)');
                    
                    // Encrypt message
                    const encrypted = await window.NostrTools.nip04.encrypt(
                        privkey,
                        dm.recipient,
                        dm.message
                    );
                    
                    // Create DM event (kind 4)
                    const event = {
                        kind: 4,
                        created_at: Math.floor(Date.now() / 1000),
                        tags: [['p', dm.recipient]],
                        content: encrypted,
                        pubkey: pubkey
                    };
                    
                    // Sign event
                    const signedEvent = window.NostrTools.finishEvent(event, privkey);
                    
                    // Publish to relays
                    await pool.publish(relays, signedEvent);
                    
                    console.log('✅ DM sent! Event ID:', signedEvent.id);
                    
                    // Mark as sent (removes from queue, logs to outbox)
                    await fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'mark_dm_as_sent',
                            nonce: '<?php echo wp_create_nonce( 'process-dm-queue' ); ?>',
                            dm_id: dm.id,
                            event_id: signedEvent.id,
                            recipient: dm.recipient,
                            subject: dm.subject || 'Order Notification',
                            username: dm.username || 'unknown'
                        })
                    });
                    
                    sent++;
                    
                    // Small delay between messages
                    await new Promise(resolve => setTimeout(resolve, 1000));
                } catch (e) {
                    console.error('❌ Error sending DM:', e);
                    failed++;
                }
            }
            
            pool.close(relays);
            
            console.log(`✅ Complete! Sent: ${sent}, Failed: ${failed}`);
        }
        
        function clearQueue() {
            if (!confirm('Clear all queued messages? This cannot be undone.')) {
                return;
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'clear_dm_queue',
                    nonce: '<?php echo wp_create_nonce( 'clear-dm-queue' ); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                alert(data.success ? 'Queue cleared!' : 'Error clearing queue');
                location.reload();
            });
        }
        
        function deleteQueueItem(itemId) {
            if (!confirm('Delete this message from the queue?')) {
                return;
            }
            
            const row = document.getElementById('dm-queue-item-' + itemId);
            if (row) {
                row.style.opacity = '0.5';
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_dm_queue_item',
                    nonce: '<?php echo wp_create_nonce( 'delete-dm-queue-item' ); ?>',
                    item_id: itemId
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (row) {
                        row.remove();
                    }
                    // Show remaining count
                    if (data.data && data.data.remaining === 0) {
                        location.reload(); // Reload to show "Queue is empty" message
                    }
                } else {
                    alert('Error deleting message: ' + (data.data?.message || 'Unknown error'));
                    if (row) {
                        row.style.opacity = '1';
                    }
                }
            })
            .catch(e => {
                console.error('Error deleting queue item:', e);
                alert('Error deleting message');
                if (row) {
                    row.style.opacity = '1';
                }
            });
        }
        </script>
        <?php
    }

    /**
     * AJAX: Regenerate site nsec
     */
    public function ajax_regenerate_nsec() {
        check_ajax_referer( 'regenerate-site-nsec', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        // Generate new private key
        $new_privkey = bin2hex( random_bytes( 32 ) );
        update_option( 'nostr_login_pay_site_privkey', $new_privkey );

        wp_send_json_success( array(
            'message' => __( 'Keys regenerated successfully', 'nostr-outbox-wordpress' ),
        ) );
    }

    /**
     * AJAX: Send manual DM
     */
    public function ajax_send_manual_dm() {
        check_ajax_referer( 'send-manual-dm', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $recipient = isset( $_POST['recipient'] ) ? sanitize_text_field( $_POST['recipient'] ) : '';
        $subject = isset( $_POST['subject'] ) ? sanitize_text_field( $_POST['subject'] ) : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( $_POST['message'] ) : '';
        $selected_groups = isset( $_POST['selected_groups'] ) ? sanitize_text_field( $_POST['selected_groups'] ) : '';

        if ( empty( $message ) ) {
            wp_send_json_error( array( 'message' => __( 'Message is required', 'nostr-outbox-wordpress' ) ) );
        }

        // Check if sending to groups
        $group_ids = array_filter( explode( ',', $selected_groups ) );
        if ( ! empty( $group_ids ) ) {
            $groups = get_option( 'nostr_group_chats', array() );
            $dm_queue = get_option( 'nostr_dm_queue', array() );
            if ( ! is_array( $dm_queue ) ) {
                $dm_queue = array();
            }

            $added_count = 0;

            // Get notification instance and use reflection for npub_to_hex
            $notifications = Nostr_Login_Pay_Notifications::instance();
            $reflection = new ReflectionClass( $notifications );
            $npub_to_hex = $reflection->getMethod( 'npub_to_hex' );
            $npub_to_hex->setAccessible( true );

            foreach ( $group_ids as $group_id ) {
                if ( ! isset( $groups[ $group_id ] ) ) {
                    continue;
                }

                $group = $groups[ $group_id ];
                $members = array_filter( array_map( 'trim', explode( "\n", $group['members'] ) ) );

                foreach ( $members as $member ) {
                    $pubkey_hex = $member;
                    if ( strpos( $member, 'npub1' ) === 0 ) {
                        $pubkey_hex = $npub_to_hex->invoke( $notifications, $member );
                    }

                    if ( ! $pubkey_hex || ! preg_match( '/^[0-9a-f]{64}$/i', $pubkey_hex ) ) {
                        continue;
                    }

                    $dm_queue[] = array(
                        'id' => uniqid( 'dm_', true ),
                        'recipient' => $pubkey_hex,
                        'message' => $message, // Manual DMs to groups don't need the prefix by default, but we could add it
                        'subject' => $subject,
                        'username' => 'Group Member (' . $group['name'] . ')',
                        'timestamp' => time(),
                    );
                    $added_count++;
                }
            }

            // Still send to single recipient if specified
            if ( ! empty( $recipient ) ) {
                // ... same logic for single recipient ...
                $pubkey_hex = $recipient;
                if ( strpos( $recipient, 'npub1' ) === 0 ) {
                    $pubkey_hex = $npub_to_hex->invoke( $notifications, $recipient );
                }

                if ( $pubkey_hex && preg_match( '/^[0-9a-f]{64}$/i', $pubkey_hex ) ) {
                    $dm_queue[] = array(
                        'id' => uniqid( 'dm_', true ),
                        'recipient' => $pubkey_hex,
                        'message' => $message,
                        'subject' => $subject,
                        'username' => 'Manual Recipient',
                        'timestamp' => time(),
                    );
                    $added_count++;
                }
            }

            update_option( 'nostr_dm_queue', $dm_queue );
            wp_send_json_success( array( 'count' => $added_count ) );
            return;
        }

        // Standard single recipient logic if no groups
        if ( empty( $recipient ) ) {
            wp_send_json_error( array( 'message' => __( 'Recipient is required', 'nostr-outbox-wordpress' ) ) );
        }

        // Convert npub to hex if needed using reflection
        $pubkey_hex = $recipient;
        if ( strpos( $recipient, 'npub1' ) === 0 ) {
            $notifications = Nostr_Login_Pay_Notifications::instance();
            $reflection = new ReflectionClass( $notifications );
            $method = $reflection->getMethod( 'npub_to_hex' );
            $method->setAccessible( true );
            $pubkey_hex = $method->invoke( $notifications, $recipient );

            if ( ! $pubkey_hex ) {
                wp_send_json_error( array( 'message' => __( 'Invalid npub', 'nostr-outbox-wordpress' ) ) );
            }
        }

        // Validate hex pubkey
        if ( ! preg_match( '/^[0-9a-f]{64}$/i', $pubkey_hex ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid public key format', 'nostr-outbox-wordpress' ) ) );
        }

        // Add to queue
        $dm_queue = get_option( 'nostr_dm_queue', array() );
        if ( ! is_array( $dm_queue ) ) {
            $dm_queue = array();
        }

        $dm_queue[] = array(
            'id' => uniqid( 'dm_', true ),
            'recipient' => $pubkey_hex,
            'message' => $message,
            'subject' => $subject,
            'username' => 'Manual Recipient',
            'timestamp' => time(),
        );
        $save_result = update_option( 'nostr_dm_queue', $dm_queue );
        error_log( 'Manual DM: Save result: ' . var_export( $save_result, true ) );
        
        // Verify
        $verify = get_option( 'nostr_dm_queue', array() );
        error_log( 'Manual DM: Verification - Queue now has ' . ( is_array( $verify ) ? count( $verify ) : gettype( $verify ) ) . ' message(s)' );

        error_log( 'Manual DM: Queued to ' . substr( $recipient_hex, 0, 16 ) . '... - Subject: ' . $subject );

        wp_send_json_success( array(
            'message' => __( 'DM queued successfully', 'nostr-outbox-wordpress' ),
        ) );

        // Attempt to send immediately if queue is small to provide better UX
        // check if this is not a dry run
        if ( count( $dm_queue ) < 5 ) {
             $this->process_dm_queue_cron();
        }
    }

    /**
     * AJAX: Get outbox
     */
    public function ajax_get_outbox() {
        check_ajax_referer( 'get-dm-outbox', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        // Get sent messages log
        // Get sent messages log (newest is appended to end, so reverse for display)
        $sent_messages = array_reverse( get_option( 'nostr_dm_sent_log', array() ) );

        wp_send_json_success( array(
            'messages' => $sent_messages,
        ) );
    }

    /**
     * AJAX: Get inbox
     */
    public function ajax_get_inbox() {
        check_ajax_referer( 'get-dm-inbox', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        // TODO: Subscribe to relays and fetch incoming DMs
        wp_send_json_success( array(
            'messages' => array(),
        ) );
    }

    /**
     * AJAX: Clear queue
     */
    public function ajax_clear_queue() {
        check_ajax_referer( 'clear-dm-queue', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        delete_option( 'nostr_dm_queue' );

        wp_send_json_success( array(
            'message' => __( 'Queue cleared', 'nostr-outbox-wordpress' ),
        ) );
    }

    /**
     * AJAX: Delete individual queue item
     */
    public function ajax_delete_queue_item() {
        check_ajax_referer( 'delete-dm-queue-item', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $item_id = isset( $_POST['item_id'] ) ? sanitize_text_field( $_POST['item_id'] ) : '';

        if ( empty( $item_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No item ID provided', 'nostr-outbox-wordpress' ) ) );
        }

        $queue = get_option( 'nostr_dm_queue', array() );

        // Remove item with matching ID
        $queue = array_filter( $queue, function( $item ) use ( $item_id ) {
            return ! isset( $item['id'] ) || $item['id'] !== $item_id;
        } );

        // Re-index array
        $queue = array_values( $queue );

        update_option( 'nostr_dm_queue', $queue );

        wp_send_json_success( array(
            'message' => __( 'Queue item deleted', 'nostr-outbox-wordpress' ),
            'remaining' => count( $queue ),
        ) );
    }

    /**
     * AJAX: Get queue for processing
     */
    public function ajax_get_queue_for_processing() {
        check_ajax_referer( 'process-dm-queue', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $queue = get_option( 'nostr_dm_queue', array() );
        $privkey = get_option( 'nostr_login_pay_site_privkey' );
        $relays = get_option( 'nostr_login_pay_relays', array(
            'wss://relay.damus.io',
            'wss://nos.lol',
            'wss://relay.primal.net',
            'wss://blastr.f7z.io',
        ) );

        if ( empty( $privkey ) ) {
            // Generate if doesn't exist
            $privkey = bin2hex( random_bytes( 32 ) );
            update_option( 'nostr_login_pay_site_privkey', $privkey );
        }

        wp_send_json_success( array(
            'queue' => is_array( $queue ) ? $queue : array(),
            'privkey' => $privkey,
            'relays' => $relays,
        ) );
    }

    /**
     * AJAX: Mark DM as sent (called by JavaScript after successful send)
     */
    public function ajax_mark_dm_as_sent() {
        check_ajax_referer( 'process-dm-queue', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'nostr-outbox-wordpress' ) ) );
        }

        $dm_id = isset( $_POST['dm_id'] ) ? sanitize_text_field( $_POST['dm_id'] ) : '';
        $event_id = isset( $_POST['event_id'] ) ? sanitize_text_field( $_POST['event_id'] ) : '';
        $recipient = isset( $_POST['recipient'] ) ? sanitize_text_field( $_POST['recipient'] ) : '';
        $subject = isset( $_POST['subject'] ) ? sanitize_text_field( $_POST['subject'] ) : '';
        $username = isset( $_POST['username'] ) ? sanitize_text_field( $_POST['username'] ) : '';

        if ( empty( $dm_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No DM ID provided', 'nostr-outbox-wordpress' ) ) );
        }

        // Remove from queue
        $queue = get_option( 'nostr_dm_queue', array() );
        $updated_queue = array_filter( $queue, function( $item ) use ( $dm_id ) {
            return ! isset( $item['id'] ) || $item['id'] !== $dm_id;
        } );
        update_option( 'nostr_dm_queue', array_values( $updated_queue ), 'no' );

        // Add to sent log (outbox)
        $sent_log = get_option( 'nostr_dm_sent_log', array() );
        if ( ! is_array( $sent_log ) ) {
            $sent_log = array();
        }

        $sent_log[] = array(
            'recipient' => $recipient,
            'subject' => $subject,
            'username' => $username,
            'time' => current_time( 'mysql' ),
            'event_id' => $event_id,
        );

        // Keep last 50 sent messages
        $sent_log = array_slice( $sent_log, -50 );
        update_option( 'nostr_dm_sent_log', $sent_log, 'no' );

        error_log( "DM Admin: Marked DM as sent - {$username} ({$subject}) - Event: {$event_id}" );

        wp_send_json_success( array(
            'message' => __( 'DM marked as sent', 'nostr-outbox-wordpress' ),
            'remaining' => count( $updated_queue ),
        ) );
    }

    /**
     * Process DM queue via WP-Cron (automatic sending)
     */
    public function process_dm_queue_cron() {
        error_log( 'Nostr DM Cron: CRON TRIGGERED at ' . current_time( 'mysql' ) );
        
        $queue = get_option( 'nostr_dm_queue', array() );
        error_log( 'Nostr DM Cron: Queue retrieved, count: ' . ( is_array( $queue ) ? count( $queue ) : 'not array' ) );

        if ( ! is_array( $queue ) || empty( $queue ) ) {
            error_log( 'Nostr DM Cron: Queue is empty, exiting' );
            return;
        }

        // Check if PHP crypto is available
        if ( ! class_exists( 'Nostr_Login_Pay_Crypto_PHP' ) ) {
            error_log( 'Nostr DM Cron: Crypto class not loaded' );
            return;
        }

        if ( ! Nostr_Login_Pay_Crypto_PHP::is_available() ) {
            error_log( 'Nostr DM Cron: Crypto libraries not available. Install: composer require simplito/elliptic-php textalk/websocket' );
            return;
        }

        // Log that cron is running
        error_log( 'Nostr DM Cron: Processing ' . count( $queue ) . ' messages with PHP crypto' );

        // Get site private key
        $site_privkey = get_option( 'nostr_login_pay_site_privkey' );
        if ( empty( $site_privkey ) ) {
            // Generate if doesn't exist
            $site_privkey = bin2hex( random_bytes( 32 ) );
            update_option( 'nostr_login_pay_site_privkey', $site_privkey );
            error_log( 'Nostr DM Cron: Generated new site privkey' );
        }

        // Get site public key
        $site_pubkey = Nostr_Login_Pay_Crypto_PHP::get_public_key( $site_privkey );
        if ( ! $site_pubkey ) {
            error_log( 'Nostr DM Cron: Failed to derive public key' );
            return;
        }

        // Get relays
        $relays = get_option( 'nostr_login_pay_relays', array(
            'wss://relay.damus.io',
            'wss://nos.lol',
            'wss://relay.primal.net',
            'wss://blastr.f7z.io',
        ) );

        // Process each DM
        $sent_log = get_option( 'nostr_dm_sent_log', array() );
        $successfully_sent = array();

        foreach ( $queue as $key => $dm ) {
            try {
                error_log( "Nostr DM Cron: Processing DM to {$dm['recipient']}" );

                // 1. Encrypt content (NIP-04)
                $encrypted_content = Nostr_Login_Pay_Crypto_PHP::nip04_encrypt(
                    $dm['message'],
                    $dm['recipient'],
                    $site_privkey
                );

                if ( ! $encrypted_content ) {
                    error_log( 'Nostr DM Cron: Encryption failed' );
                    continue;
                }

                // 2. Build DM event (kind 4)
                $event = array(
                    'kind' => 4,
                    'created_at' => time(),
                    'tags' => array( array( 'p', $dm['recipient'] ) ),
                    'content' => $encrypted_content,
                    'pubkey' => $site_pubkey,
                );

                // 3. Sign event
                $signed_event = Nostr_Login_Pay_Crypto_PHP::sign_event( $event, $site_privkey );

                if ( ! $signed_event ) {
                    error_log( 'Nostr DM Cron: Signing failed' );
                    continue;
                }

                error_log( 'Nostr DM Cron: Event signed successfully, event ID: ' . $signed_event['id'] );

                // 4. Publish to relays
                $published = Nostr_Login_Pay_Crypto_PHP::publish_to_relays( $signed_event, $relays );

                if ( $published ) {
                    error_log( 'Nostr DM Cron: Published successfully to relays' );
                    
                    // Mark as successfully sent
                    $successfully_sent[] = $key;

                    // Add to sent log with proper subject and recipient info
                    $sent_log[] = array(
                        'recipient' => $dm['recipient'],
                        'recipient_display' => ! empty( $dm['username'] ) ? $dm['username'] : substr( $dm['recipient'], 0, 16 ) . '...',
                        'subject' => ! empty( $dm['subject'] ) ? $dm['subject'] : substr( $dm['message'], 0, 50 ) . '...',
                        'time' => current_time( 'mysql' ),
                        'event_id' => $signed_event['id'],
                    );
                } else {
                    error_log( 'Nostr DM Cron: Failed to publish to relays' );
                }

            } catch ( Exception $e ) {
                error_log( 'Nostr DM Cron: Exception processing DM: ' . $e->getMessage() );
            }
        }

        // Remove successfully sent messages from queue
        if ( ! empty( $successfully_sent ) ) {
            $queue = array_diff_key( $queue, array_flip( $successfully_sent ) );
            update_option( 'nostr_dm_queue', array_values( $queue ), 'no' );
            error_log( 'Nostr DM Cron: Removed ' . count( $successfully_sent ) . ' sent messages from queue' );
        }

        // Keep last 50 sent messages in log
        $sent_log = array_slice( $sent_log, -50 );
        update_option( 'nostr_dm_sent_log', $sent_log, 'no' );

        error_log( 'Nostr DM Cron: Completed processing. Sent: ' . count( $successfully_sent ) . ', Remaining: ' . count( $queue ) );
    }
}

