<?php
/**
 * User Profile Fields
 *
 * @package Nostr_Login_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles user profile fields for Nostr and NWC
 */
class Nostr_Login_Pay_User_Profile {

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
        // Add fields to user profile
        add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );

        // Add fields to WooCommerce my account page
        add_action( 'woocommerce_account_dashboard', array( $this, 'render_woocommerce_nostr_section' ) );
        
        // Update user display name to correct npub if needed
        add_action( 'woocommerce_account_dashboard', array( $this, 'update_user_display_name_to_npub' ), 5 );

        // Add custom endpoint for NWC management
        add_action( 'init', array( $this, 'add_nwc_endpoint' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_nwc_menu_item' ) );
        add_action( 'woocommerce_account_nwc-wallet_endpoint', array( $this, 'render_nwc_wallet_page' ) );

        // AJAX handlers
        add_action( 'wp_ajax_disconnect_nwc_wallet', array( $this, 'ajax_disconnect_wallet' ) );
        add_action( 'wp_ajax_update_nostr_pubkey', array( $this, 'ajax_update_nostr_pubkey' ) );
        add_action( 'wp_ajax_update_nostr_display_name', array( $this, 'ajax_update_nostr_display_name' ) );
    }

    /**
     * Convert hex public key to npub format (bech32)
     *
     * @param string $hex_pubkey Hex public key (64 characters)
     * @return string|false npub string or false on error
     */
    private function hex_to_npub( $hex_pubkey ) {
        if ( empty( $hex_pubkey ) || strlen( $hex_pubkey ) !== 64 ) {
            return false;
        }

        // If already in npub format, return as is
        if ( strpos( $hex_pubkey, 'npub' ) === 0 ) {
            return $hex_pubkey;
        }

        // Normalize to lowercase for hex2bin
        $hex_pubkey = strtolower( $hex_pubkey );

        // Convert hex to bytes
        $bytes = hex2bin( $hex_pubkey );
        if ( $bytes === false ) {
            return false;
        }

        // Bech32 encoding
        return $this->bech32_encode( 'npub', $bytes );
    }

    /**
     * Bech32 encoding implementation (BIP-173)
     * Based on reference implementation from Bitcoin Core
     *
     * @param string $hrp Human-readable part (e.g., 'npub')
     * @param string $data Binary data to encode
     * @return string Bech32 encoded string
     */
    private function bech32_encode( $hrp, $data ) {
        $charset = 'qpzry9x8gf2tvdw0s3jn54kce10mru6yghe45a7';
        $hrp = strtolower( $hrp );
        
        // Convert data bytes to 5-bit groups
        $data_uint5 = $this->convert_bits( $data, 8, 5, true );
        
        // Expand HRP
        $hrp_expanded = array();
        for ( $i = 0; $i < strlen( $hrp ); $i++ ) {
            $c = ord( $hrp[ $i ] );
            $hrp_expanded[] = $c >> 5;
        }
        $hrp_expanded[] = 0;
        for ( $i = 0; $i < strlen( $hrp ); $i++ ) {
            $hrp_expanded[] = ord( $hrp[ $i ] ) & 31;
        }
        
        // Calculate checksum
        $combined = array_merge( $hrp_expanded, $data_uint5 );
        $polymod = $this->bech32_polymod( $combined ) ^ 1;
        $checksum = array();
        for ( $i = 0; $i < 6; $i++ ) {
            $checksum[] = ( $polymod >> ( 5 * ( 5 - $i ) ) ) & 31;
        }
        
        // Build result
        $result = $hrp . '1';
        foreach ( array_merge( $data_uint5, $checksum ) as $v ) {
            $result .= $charset[ $v ];
        }
        
        return $result;
    }

    /**
     * Convert between bit sizes
     *
     * @param string $data Input data
     * @param int $frombits Source bit size
     * @param int $tobits Target bit size
     * @param bool $pad Pad output
     * @return array Array of integers
     */
    private function convert_bits( $data, $frombits, $tobits, $pad = true ) {
        $acc = 0;
        $bits = 0;
        $ret = array();
        $maxv = ( 1 << $tobits ) - 1;
        $max_acc = ( 1 << ( $frombits + $tobits - 1 ) ) - 1;
        
        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $value = ord( $data[ $i ] );
            if ( $value < 0 || ( $value >> $frombits ) ) {
                return array(); // Invalid
            }
            $acc = ( ( $acc << $frombits ) | $value ) & $max_acc;
            $bits += $frombits;
            while ( $bits >= $tobits ) {
                $bits -= $tobits;
                $ret[] = ( ( $acc >> $bits ) & $maxv );
            }
        }
        
        if ( $pad ) {
            if ( $bits ) {
                $ret[] = ( ( $acc << ( $tobits - $bits ) ) & $maxv );
            }
        } elseif ( $bits >= $frombits || ( ( $acc << ( $tobits - $bits ) ) & $maxv ) ) {
            return array(); // Invalid
        }
        
        return $ret;
    }

    /**
     * Bech32 polymod function (BIP-173)
     *
     * @param array $values Array of 5-bit values
     * @return int Polymod result (32-bit)
     */
    private function bech32_polymod( $values ) {
        $generator = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );
        $chk = 1;
        foreach ( $values as $value ) {
            $top = $chk >> 25;
            $chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;
            for ( $i = 0; $i < 5; $i++ ) {
                if ( ( $top >> $i ) & 1 ) {
                    $chk ^= $generator[ $i ];
                }
            }
        }
        // Ensure 32-bit result (handle PHP's integer overflow)
        if ( PHP_INT_SIZE === 8 ) {
            // 64-bit system - mask to 32 bits
            return $chk & 0xffffffff;
        }
        return $chk;
    }

    /**
     * Render Nostr profile fields
     */
    public function render_profile_fields( $user ) {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }

        $nostr_pubkey = get_user_meta( $user->ID, 'nostr_pubkey', true );
        $nwc_connected = get_user_meta( $user->ID, 'nwc_wallet_pubkey', true );
        $nwc_connected_at = get_user_meta( $user->ID, 'nwc_wallet_connected_at', true );
        
        // Convert to npub format if hex
        $npub = $this->hex_to_npub( $nostr_pubkey );
        ?>
        <h2><?php _e( 'Nostr & Lightning', 'nostr-outbox-wordpress' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label><?php _e( 'Nostr Public Key', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <?php if ( $nostr_pubkey ) : ?>
                        <code><?php echo esc_html( $npub ? $npub : $nostr_pubkey ); ?></code>
                        <p class="description"><?php _e( 'Your Nostr identity public key.', 'nostr-outbox-wordpress' ); ?></p>
                    <?php else : ?>
                        <p class="description"><?php _e( 'No Nostr public key connected.', 'nostr-outbox-wordpress' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label><?php _e( 'NWC Wallet Status', 'nostr-outbox-wordpress' ); ?></label></th>
                <td>
                    <?php if ( $nwc_connected ) : ?>
                        <span style="color: green;">✓ <?php _e( 'Connected', 'nostr-outbox-wordpress' ); ?></span>
                        <?php if ( $nwc_connected_at ) : ?>
                            <p class="description">
                                <?php
                                printf(
                                    __( 'Connected on %s', 'nostr-outbox-wordpress' ),
                                    date_i18n( get_option( 'date_format' ), $nwc_connected_at )
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    <?php else : ?>
                        <span style="color: gray;">✗ <?php _e( 'Not connected', 'nostr-outbox-wordpress' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Update user display name to correct npub if needed
     */
    public function update_user_display_name_to_npub() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        // Check if user has synced their profile from Nostr
        // If so, respect that and don't auto-update to npub
        $nostr_display_name = get_user_meta( $user_id, 'nostr_display_name', true );
        $has_synced_profile = ! empty( $nostr_display_name ) && strpos( $nostr_display_name, 'nostr_' ) !== 0;
        
        // Check if display name needs updating (only if it looks like an npub)
        $user = wp_get_current_user();
        $current_display = $user->display_name;
        
        // If display name starts with npub but is wrong, we'll update it via JS
        // This method just sets up the JavaScript to do the update
        ?>
        <script>
        (function() {
            // Check if user has synced profile - if so, don't auto-update to npub
            const hasSyncedProfile = <?php echo $has_synced_profile ? 'true' : 'false'; ?>;
            
            if (hasSyncedProfile) {
                // User has synced their profile, respect their Nostr display name
                console.log('Nostr: Using synced profile name, not auto-updating to npub');
                return;
            }
            
            // Update display name to correct npub
            if (typeof window.NostrTools !== 'undefined' && window.NostrTools.nip19 && window.NostrTools.nip19.npubEncode) {
                const hexKey = '<?php echo esc_js( $nostr_pubkey ); ?>';
                if (hexKey && hexKey.length === 64) {
                    try {
                        const correctNpub = window.NostrTools.nip19.npubEncode(hexKey);
                        const currentDisplay = '<?php echo esc_js( $current_display ); ?>';
                        
                        // Only update if current display is hex-based username or wrong npub
                        // Don't update if it's a nice human-readable name
                        const isHexUsername = currentDisplay.startsWith('nostr_');
                        const isWrongNpub = currentDisplay.startsWith('npub1') && currentDisplay !== correctNpub;
                        
                        if (isHexUsername || isWrongNpub) {
                            // Update via AJAX
                            fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'update_nostr_display_name',
                                    nonce: '<?php echo wp_create_nonce( 'update-nostr-display-name' ); ?>',
                                    npub: correctNpub
                                })
                            })
                            .then(r => r.json())
                            .then(data => {
                                if (data.success) {
                                    // Reload page to show updated display name
                                    location.reload();
                                }
                            })
                            .catch(e => console.error('Error updating display name:', e));
                        }
                    } catch(e) {
                        console.error('Error generating npub for display name:', e);
                    }
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * Render Nostr section on WooCommerce dashboard
     */
    public function render_woocommerce_nostr_section() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nostr_pubkey = get_user_meta( $user_id, 'nostr_pubkey', true );
        
        // Only show section if user is logged in with Nostr
        if ( ! $nostr_pubkey ) {
            return;
        }
        
        // Convert to npub format if hex
        $npub = $this->hex_to_npub( $nostr_pubkey );
        ?>
        <div class="nostr-wallet-dashboard">
            <h3><?php _e( 'Nostr Identity', 'nostr-outbox-wordpress' ); ?></h3>
            
            <p>
                <strong><?php _e( 'Nostr Public Key:', 'nostr-outbox-wordpress' ); ?></strong><br>
                <code style="font-size: 11px;" id="nostr-npub-display"><?php echo esc_html( $npub ? $npub : $nostr_pubkey ); ?></code>
                <input type="hidden" id="nostr-hex-key" value="<?php echo esc_attr( $nostr_pubkey ); ?>" />
            </p>
            
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <p style="font-size: 11px; color: #999; margin-top: 10px;">
                <strong>Debug (Admin only):</strong><br>
                Stored hex: <code><?php echo esc_html( $nostr_pubkey ); ?></code><br>
                <span id="js-conversion-status" style="color: #666;">Waiting for JavaScript conversion...</span><br>
                <button type="button" id="sync-nostr-key-btn" style="margin-top: 5px; padding: 5px 10px; font-size: 11px; display: none;">
                    Update stored key to match nos2x
                </button>
            </p>
            <?php endif; ?>
            
            <p style="font-size: 13px; color: #666;">
                <?php _e( 'You can use your Nostr identity to log in to this site.', 'nostr-outbox-wordpress' ); ?>
            </p>
        </div>
        <script>
        // Minimal bech32 encoder (BIP-173) for accurate npub conversion
        (function() {
            function initNpubConversion() {
                const hexKey = document.getElementById('nostr-hex-key');
                const npubDisplay = document.getElementById('nostr-npub-display');
                if (!hexKey || !npubDisplay) {
                    console.warn('Nostr npub conversion: Elements not found');
                    return;
                }
                
                const hexValue = hexKey.value;
                if (!hexValue || hexValue.length !== 64) {
                    console.warn('Nostr npub conversion: Invalid hex value', hexValue);
                    return;
                }
                
                // Start conversion silently
            
            // Bech32 charset
            const CHARSET = 'qpzry9x8gf2tvdw0s3jn54kce10mru6yghe45a7';
            const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
            
            function polymod(values) {
                let chk = 1;
                for (let value of values) {
                    const top = chk >>> 25;
                    chk = ((chk & 0x1ffffff) << 5) ^ value;
                    for (let i = 0; i < 5; i++) {
                        if ((top >>> i) & 1) chk ^= GENERATOR[i];
                    }
                }
                return chk;
            }
            
            function bech32Encode(hrp, data) {
                const hrpLower = hrp.toLowerCase();
                const hrpExpanded = [];
                for (let i = 0; i < hrpLower.length; i++) {
                    hrpExpanded.push(hrpLower.charCodeAt(i) >>> 5);
                }
                hrpExpanded.push(0);
                for (let i = 0; i < hrpLower.length; i++) {
                    hrpExpanded.push(hrpLower.charCodeAt(i) & 31);
                }
                
                const combined = hrpExpanded.concat(data);
                const polymodValue = polymod(combined) ^ 1;
                const checksum = [];
                for (let i = 0; i < 6; i++) {
                    checksum.push((polymodValue >>> (5 * (5 - i))) & 31);
                }
                
                return hrpLower + '1' + (data.concat(checksum).map(v => CHARSET[v]).join(''));
            }
            
            function convertBits(data, fromBits, toBits, pad) {
                let acc = 0, bits = 0, ret = [];
                const maxv = (1 << toBits) - 1;
                const maxAcc = (1 << (fromBits + toBits - 1)) - 1;
                
                for (let value of data) {
                    if (value < 0 || (value >>> fromBits)) return [];
                    acc = ((acc << fromBits) | value) & maxAcc;
                    bits += fromBits;
                    while (bits >= toBits) {
                        bits -= toBits;
                        ret.push((acc >>> bits) & maxv);
                    }
                }
                if (pad && bits) {
                    ret.push((acc << (toBits - bits)) & maxv);
                } else if (bits >= fromBits || ((acc << (toBits - bits)) & maxv)) {
                    return [];
                }
                return ret;
            }
            
            function convertToNpub() {
                try {
                    let npub = null;
                    
                    // Try to use nostr-tools first (most reliable, already loaded)
                    if (typeof window.NostrTools !== 'undefined') {
                        try {
                            // Check for nip19.npubEncode (v2.x API)
                            if (window.NostrTools.nip19 && window.NostrTools.nip19.npubEncode) {
                                npub = window.NostrTools.nip19.npubEncode(hexValue);
                            }
                            // Check for bech32 functions directly
                            else if (window.NostrTools.bech32 && window.NostrTools.bech32.encode) {
                                // Check if utils.hexToBytes exists
                                if (window.NostrTools.utils && window.NostrTools.utils.hexToBytes) {
                                    const bytes = window.NostrTools.utils.hexToBytes(hexValue);
                                    npub = window.NostrTools.bech32.encode('npub', bytes, window.NostrTools.bech32.encodings.BECH32);
                                } else {
                                    // Manual hex to bytes conversion
                                    const bytes = new Uint8Array(hexValue.match(/.{1,2}/g).map(b => parseInt(b, 16)));
                                    npub = window.NostrTools.bech32.encode('npub', bytes, window.NostrTools.bech32.encodings.BECH32);
                                }
                            }
                            // Check for nip19.encode (alternative API)
                            else if (window.NostrTools.nip19 && window.NostrTools.nip19.encode) {
                                const bytes = new Uint8Array(hexValue.match(/.{1,2}/g).map(b => parseInt(b, 16)));
                                npub = window.NostrTools.nip19.encode({ type: 'npub', data: bytes });
                            }
                        } catch(e) {
                            console.warn('Nostr npub conversion: nostr-tools conversion failed:', e);
                            console.error(e);
                        }
                    }
                    
                    // Fallback to our implementation if nostr-tools didn't work (shouldn't happen, but just in case)
                    if (!npub) {
                        console.warn('Nostr npub conversion: nostr-tools not available, using fallback...');
                        // Convert hex to bytes
                        const bytes = new Uint8Array(hexValue.match(/.{1,2}/g).map(b => parseInt(b, 16)));
                        // Convert bytes to 5-bit groups
                        const data5bit = convertBits(Array.from(bytes), 8, 5, true);
                        // Encode to bech32
                        npub = bech32Encode('npub', data5bit);
                        console.log('Nostr npub conversion: Fallback result npub:', npub);
                    }
                    
                    if (!npub) {
                        throw new Error('Failed to convert hex to npub - nostr-tools not available and fallback failed');
                    }
                    
                    // Update display
                    npubDisplay.textContent = npub;
                    
                    // Update debug status if available
                    const statusEl = document.getElementById('js-conversion-status');
                    if (statusEl) {
                        statusEl.textContent = 'Converted: ' + npub;
                        statusEl.style.color = '#0a0';
                    }
                    
                    // Also try to get from nos2x to compare (only log if mismatch)
                    if (typeof window.nostr !== 'undefined') {
                        window.nostr.getPublicKey().then(function(nos2xHex) {
                            if (nos2xHex) {
                                // Normalize both to lowercase for comparison
                                const storedLower = hexValue.toLowerCase();
                                const nos2xLower = nos2xHex.toLowerCase();
                                
                                if (nos2xLower !== storedLower) {
                                    console.warn('⚠️ Nostr key mismatch detected!');
                                    console.log('  Stored hex:', hexValue);
                                    console.log('  nos2x hex:', nos2xHex);
                                    const statusEl = document.getElementById('js-conversion-status');
                                    if (statusEl) {
                                        statusEl.innerHTML = '⚠️ Key mismatch! Stored: ' + hexValue.substring(0, 16) + '... nos2x: ' + nos2xHex.substring(0, 16) + '...';
                                        statusEl.style.color = '#d00';
                                    }
                                    
                                    // Show update button for admins
                                    const updateBtn = document.getElementById('sync-nostr-key-btn');
                                    if (updateBtn) {
                                        updateBtn.style.display = 'inline-block';
                                        updateBtn.onclick = function() {
                                            if (confirm('Update stored key to match nos2x? This will sync your account with your current nos2x key.')) {
                                                updateNostrKey(nos2xHex);
                                            }
                                        };
                                    }
                                } else {
                                    const statusEl = document.getElementById('js-conversion-status');
                                    if (statusEl) {
                                        statusEl.textContent = '✓ Keys match nos2x';
                                        statusEl.style.color = '#0a0';
                                    }
                                }
                            }
                        }).catch(function(e) {
                            console.log('Could not get nos2x key for comparison:', e);
                        });
                    }
                    
                    function updateNostrKey(newHex) {
                        const btn = document.getElementById('sync-nostr-key-btn');
                        if (btn) {
                            btn.disabled = true;
                            btn.textContent = 'Updating...';
                        }
                        
                        fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'update_nostr_pubkey',
                                nonce: '<?php echo wp_create_nonce( 'update-nostr-pubkey' ); ?>',
                                pubkey: newHex
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                alert('Key updated! Refreshing page...');
                                location.reload();
                            } else {
                                alert('Error: ' + (data.data?.message || 'Unknown error'));
                                if (btn) {
                                    btn.disabled = false;
                                    btn.textContent = 'Update stored key to match nos2x';
                                }
                            }
                        })
                        .catch(e => {
                            alert('Error updating key: ' + e.message);
                            if (btn) {
                                btn.disabled = false;
                                btn.textContent = 'Update stored key to match nos2x';
                            }
                        });
                    }
                } catch(e) {
                    console.error('Error converting to npub:', e);
                    const statusEl = document.getElementById('js-conversion-status');
                    if (statusEl) {
                        statusEl.textContent = 'Error: ' + e.message;
                        statusEl.style.color = '#d00';
                    }
                }
            }
            
                // Convert immediately
                convertToNpub();
            }
            
            // Run when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initNpubConversion);
            } else {
                initNpubConversion();
            }
        })();
        </script>
        <?php
        
        /* DISABLED: NWC wallet connection UI removed
        $nwc_connected = get_user_meta( $user_id, 'nwc_wallet_pubkey', true );
        
        <?php if ( $nwc_connected ) : ?>
            <div class="nwc-wallet-status" style="padding: 15px; background: #f0f9ff; border-left: 4px solid #3b82f6; margin: 15px 0;">
                <p style="margin: 0;">
                    <strong style="color: #1e40af;">⚡ <?php _e( 'Lightning Wallet Connected', 'nostr-outbox-wordpress' ); ?></strong>
                </p>
                <p style="margin: 10px 0 0 0; font-size: 14px;">
                    <?php _e( 'You can now make instant Lightning payments at checkout.', 'nostr-outbox-wordpress' ); ?>
                    <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'nwc-wallet' ) ); ?>" style="margin-left: 10px;">
                        <?php _e( 'Manage Wallet', 'nostr-outbox-wordpress' ); ?>
                    </a>
                </p>
            </div>
        <?php else : ?>
            <div class="nwc-connect-section" style="padding: 15px; background: #fef3c7; border-left: 4px solid #f59e0b; margin: 15px 0;">
                <p style="margin: 0 0 10px 0;">
                    <strong><?php _e( 'Connect Your Lightning Wallet', 'nostr-outbox-wordpress' ); ?></strong>
                </p>
                <p style="margin: 0 0 10px 0; font-size: 14px;">
                    <?php _e( 'Connect your NWC-enabled Lightning wallet to make instant payments.', 'nostr-outbox-wordpress' ); ?>
                </p>
                <button type="button" class="button button-primary" id="connect-nwc-btn">
                    <?php _e( 'Connect Wallet', 'nostr-outbox-wordpress' ); ?>
                </button>
            </div>
        <?php endif; ?>
        */
    }

    /**
     * Add NWC endpoint
     */
    public function add_nwc_endpoint() {
        // DISABLED: Customer NWC wallet connection not currently functional
        // Keeping code for future implementation when browser NWC support improves
        // add_rewrite_endpoint( 'nwc-wallet', EP_ROOT | EP_PAGES );
    }

    /**
     * Add NWC menu item to My Account
     */
    public function add_nwc_menu_item( $items ) {
        // DISABLED: Customer NWC wallet connection not currently functional
        // Just return items unchanged (no NWC wallet tab)
        return $items;
        
        /* Original code kept for future use:
        $new_items = array();
        
        foreach ( $items as $key => $item ) {
            $new_items[ $key ] = $item;
            
            // Add after dashboard
            if ( $key === 'dashboard' ) {
                $new_items['nwc-wallet'] = __( 'Lightning Wallet', 'nostr-outbox-wordpress' );
            }
        }
        
        return $new_items;
        */
    }

    /**
     * Render NWC wallet management page
     */
    public function render_nwc_wallet_page() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
        $connection = $nwc_wallet->get_user_connection( $user_id );
        ?>
        <div class="nwc-wallet-page">
            <h2><?php _e( 'Lightning Wallet (NWC)', 'nostr-outbox-wordpress' ); ?></h2>

            <?php if ( $connection ) : ?>
                <div class="nwc-connected-info">
                    <div style="padding: 20px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; margin-bottom: 20px;">
                        <h3 style="margin: 0 0 15px 0; color: #15803d;">
                            ✓ <?php _e( 'Wallet Connected', 'nostr-outbox-wordpress' ); ?>
                        </h3>
                        
                        <div style="margin-bottom: 15px;">
                            <strong><?php _e( 'Wallet Public Key:', 'nostr-outbox-wordpress' ); ?></strong><br>
                            <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( $connection['pubkey'] ); ?></code>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <strong><?php _e( 'Relay:', 'nostr-outbox-wordpress' ); ?></strong><br>
                            <code><?php echo esc_html( $connection['relay'] ); ?></code>
                        </div>

                        <button type="button" class="button button-secondary" id="disconnect-nwc-btn" style="background: #dc2626; border-color: #dc2626; color: white;">
                            <?php _e( 'Disconnect Wallet', 'nostr-outbox-wordpress' ); ?>
                        </button>
                    </div>

                    <div class="nwc-features">
                        <h3><?php _e( 'What You Can Do', 'nostr-outbox-wordpress' ); ?></h3>
                        <ul>
                            <li>⚡ <?php _e( 'Make instant Lightning payments at checkout', 'nostr-outbox-wordpress' ); ?></li>
                            <li>🔒 <?php _e( 'Your keys remain secure in your wallet', 'nostr-outbox-wordpress' ); ?></li>
                            <li>🌐 <?php _e( 'Works with any NWC-compatible Lightning wallet', 'nostr-outbox-wordpress' ); ?></li>
                        </ul>
                    </div>
                </div>
            <?php else : ?>
                <div class="nwc-connect-form">
                    <p><?php _e( 'Connect your NWC-enabled Lightning wallet to enable instant payments.', 'nostr-outbox-wordpress' ); ?></p>
                    
                    <div style="padding: 20px; background: #f0f9ff; border: 1px solid #3b82f6; border-radius: 8px; margin: 20px 0;">
                        <h3 style="margin-top: 0; color: #1e40af;">💡 Recommended: Coinos</h3>
                        <p style="margin-bottom: 15px;">
                            <strong>Coinos</strong> is a free, easy-to-use Lightning wallet with excellent NWC support.
                        </p>
                        <h4 style="margin: 15px 0 10px 0;"><?php _e( 'How to Connect Your Wallet:', 'nostr-outbox-wordpress' ); ?></h4>
                        <ol style="margin: 10px 0 10px 20px; line-height: 1.8;">
                            <li>Sign up at <a href="https://coinos.io/" target="_blank" style="color: #2563eb; text-decoration: none;">coinos.io</a></li>
                            <li>Go to <strong>Settings → NWC</strong></li>
                            <li>Click <strong>"Create Connection"</strong></li>
                            <li>Enable <strong>"pay_invoice"</strong> permission (for making payments)</li>
                            <li>Copy the <strong>full connection string</strong></li>
                            <li>Paste it below and click Connect</li>
                        </ol>
                        <p style="margin: 10px 0 0 0; font-size: 13px; color: #64748b;">
                            <em>Works with Alby, Mutiny, and other NWC wallets too!</em>
                        </p>
                    </div>

                    <div style="margin: 20px 0;">
                        <label for="nwc-connection-string" style="display: block; margin-bottom: 10px; font-weight: bold;">
                            <?php _e( 'NWC Connection String', 'nostr-outbox-wordpress' ); ?>
                        </label>
                        <input 
                            type="text" 
                            id="nwc-connection-string" 
                            placeholder="nostr+walletconnect://..." 
                            style="width: 100%; padding: 10px; font-size: 14px; font-family: monospace;"
                        >
                        <p class="description">
                            <?php _e( 'Your connection string is encrypted and stored securely.', 'nostr-outbox-wordpress' ); ?>
                        </p>
                    </div>

                    <button type="button" class="button button-primary" id="connect-nwc-wallet-btn">
                        <?php _e( 'Connect Wallet', 'nostr-outbox-wordpress' ); ?>
                    </button>

                    <div id="nwc-connection-status" style="margin-top: 15px;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler to disconnect wallet
     */
    public function ajax_disconnect_wallet() {
        check_ajax_referer( 'nostr-outbox-wordpress-nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $nwc_wallet = new Nostr_Login_Pay_NWC_Wallet();
        $nwc_wallet->disconnect_user_wallet( get_current_user_id() );

        wp_send_json_success( array(
            'message' => __( 'Wallet disconnected successfully', 'nostr-outbox-wordpress' ),
        ) );
    }

    /**
     * AJAX handler to update Nostr pubkey
     */
    public function ajax_update_nostr_pubkey() {
        check_ajax_referer( 'update-nostr-pubkey', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $pubkey = isset( $_POST['pubkey'] ) ? sanitize_text_field( $_POST['pubkey'] ) : '';
        
        if ( empty( $pubkey ) ) {
            wp_send_json_error( array( 'message' => __( 'No pubkey provided', 'nostr-outbox-wordpress' ) ) );
        }

        // Validate hex format (64 characters, hex)
        if ( strlen( $pubkey ) !== 64 || ! ctype_xdigit( $pubkey ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid pubkey format', 'nostr-outbox-wordpress' ) ) );
        }

        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'nostr_pubkey', strtolower( $pubkey ) );

        wp_send_json_success( array(
            'message' => __( 'Nostr public key updated successfully', 'nostr-outbox-wordpress' ),
        ) );
    }

    /**
     * AJAX handler to update Nostr display name
     */
    public function ajax_update_nostr_display_name() {
        check_ajax_referer( 'update-nostr-display-name', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in', 'nostr-outbox-wordpress' ) ) );
        }

        $npub = isset( $_POST['npub'] ) ? sanitize_text_field( $_POST['npub'] ) : '';
        
        if ( empty( $npub ) ) {
            wp_send_json_error( array( 'message' => __( 'No npub provided', 'nostr-outbox-wordpress' ) ) );
        }

        // Validate npub format
        if ( ! preg_match( '/^npub1[a-z0-9]{58}$/', $npub ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid npub format', 'nostr-outbox-wordpress' ) ) );
        }

        $user_id = get_current_user_id();
        wp_update_user( array(
            'ID' => $user_id,
            'display_name' => $npub,
        ) );

        wp_send_json_success( array(
            'message' => __( 'Display name updated successfully', 'nostr-outbox-wordpress' ),
        ) );
    }
}

