/**
 * Frontend JavaScript for Nostr Login & Pay
 */

(function($) {
    'use strict';

    // Wait for DOM and nostr-tools to be ready
    $(document).ready(function() {
        initNostrLogin();
        initNWCConnect();
        initNWCDisconnect();
    });

    /**
     * Initialize Nostr login functionality
     */
    function initNostrLogin() {
        $('#nostr-login-btn').on('click', async function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const $status = $('.nostr-login-status');
            
            $button.prop('disabled', true);
            $status.html('<p class="nostr-status-info">Checking for Nostr extension...</p>');

            try {
                // Check if window.nostr exists at all
                if (typeof window.nostr === 'undefined') {
                    // Wait a bit for it to load
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // Still not there? Extension might not be installed
                    if (typeof window.nostr === 'undefined') {
                        throw new Error('Nostr extension not found. Please install nos2x or Alby, then refresh the page.');
                    }
                }

                $status.html('<p class="nostr-status-info">Requesting public key from your Nostr extension...</p>');

                // Try to get public key - this will trigger the permission prompt!
                const pubkey = await window.nostr.getPublicKey();

                // Create auth event (NIP-42 style)
                const event = {
                    kind: 22242,
                    created_at: Math.floor(Date.now() / 1000),
                    tags: [
                        ['challenge', generateChallenge()],
                        ['relay', nostrLoginPay.siteUrl]
                    ],
                    content: `Login to ${nostrLoginPay.siteName}`,
                    pubkey: pubkey
                };

                $status.html('<p class="nostr-status-info">Requesting signature from your Nostr extension...</p>');

                // Sign event - this may also trigger a permission prompt
                const signedEvent = await window.nostr.signEvent(event);

                // Send to server for verification
                $.ajax({
                    url: nostrLoginPay.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'nostr_verify_login',
                        nonce: nostrLoginPay.nonce,
                        pubkey: pubkey,
                        signature: signedEvent.sig,
                        event: JSON.stringify(signedEvent)
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<p class="nostr-status-success">✓ ' + response.data.message + '</p>');
                            
                            // Redirect after a short delay
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000);
                        } else {
                            throw new Error(response.data.message || 'Login failed');
                        }
                    },
                    error: function(xhr, status, error) {
                        throw new Error('Server error: ' + error);
                    }
                });

            } catch (error) {
                console.error('Nostr login error:', error);
                $status.html('<p class="nostr-status-error">✗ ' + error.message + '</p>');
                $button.prop('disabled', false);
            }
        });
    }

    /**
     * Initialize NWC wallet connection
     */
    function initNWCConnect() {
        // Handle connect button on dashboard
        $('#connect-nwc-btn').on('click', function() {
            const nwcUrl = prompt('Paste your NWC connection string:\n(nostr+walletconnect://...)');
            
            if (!nwcUrl) {
                return;
            }

            connectNWCWallet(nwcUrl);
        });

        // Handle connect button on NWC wallet page
        $('#connect-nwc-wallet-btn').on('click', function() {
            const nwcUrl = $('#nwc-connection-string').val();
            
            if (!nwcUrl) {
                alert('Please enter your NWC connection string');
                return;
            }

            connectNWCWallet(nwcUrl);
        });
    }

    /**
     * Connect NWC wallet
     */
    function connectNWCWallet(nwcUrl) {
        const $status = $('#nwc-connection-status');
        
        if ($status.length) {
            $status.html('<p class="nostr-status-info">Connecting wallet...</p>');
        }

        $.ajax({
            url: nostrLoginPay.ajaxUrl,
            method: 'POST',
            data: {
                action: 'nostr_verify_nwc',
                nonce: nostrLoginPay.nonce,
                nwc_url: nwcUrl
            },
            success: function(response) {
                if (response.success) {
                    if ($status.length) {
                        $status.html('<p class="nostr-status-success">✓ ' + response.data.message + '</p>');
                    } else {
                        alert(response.data.message);
                    }
                    
                    // Reload page after short delay
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    const message = response.data.message || 'Connection failed';
                    if ($status.length) {
                        $status.html('<p class="nostr-status-error">✗ ' + message + '</p>');
                    } else {
                        alert('Error: ' + message);
                    }
                }
            },
            error: function(xhr, status, error) {
                const message = 'Server error: ' + error;
                if ($status.length) {
                    $status.html('<p class="nostr-status-error">✗ ' + message + '</p>');
                } else {
                    alert('Error: ' + message);
                }
            }
        });
    }

    /**
     * Initialize NWC wallet disconnect
     */
    function initNWCDisconnect() {
        $('#disconnect-nwc-btn').on('click', function() {
            if (!confirm('Are you sure you want to disconnect your Lightning wallet?')) {
                return;
            }

            $.ajax({
                url: nostrLoginPay.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'disconnect_nwc_wallet',
                    nonce: nostrLoginPay.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.location.reload();
                    } else {
                        alert('Error: ' + (response.data.message || 'Disconnection failed'));
                    }
                },
                error: function(xhr, status, error) {
                    alert('Server error: ' + error);
                }
            });
        });
    }

    /**
     * Wait for Nostr extension to be available
     */
    function waitForNostr(timeout = 5000) {
        return new Promise((resolve) => {
            // Check immediately
            if (window.nostr) {
                console.log('Nostr extension found immediately');
                resolve(window.nostr);
                return;
            }

            console.log('Waiting for Nostr extension...');
            let attempts = 0;
            
            // Set up interval to check every 100ms
            const interval = setInterval(() => {
                attempts++;
                if (window.nostr) {
                    console.log('Nostr extension found after', attempts * 100, 'ms');
                    clearInterval(interval);
                    resolve(window.nostr);
                } else {
                    console.log('Attempt', attempts, '- window.nostr is', window.nostr);
                }
            }, 100);

            // Timeout after specified time
            setTimeout(() => {
                clearInterval(interval);
                console.log('Nostr extension not found after', timeout, 'ms');
                console.log('window.nostr final value:', window.nostr);
                console.log('Available window properties:', Object.keys(window).filter(k => k.toLowerCase().includes('nostr')));
                resolve(null);
            }, timeout);
        });
    }

    /**
     * Generate a random challenge string
     */
    function generateChallenge() {
        const array = new Uint8Array(32);
        crypto.getRandomValues(array);
        return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    // Show button state on page load and add visual indicator
    $(document).ready(function() {
        // Check if extension is available after short delay
        setTimeout(function() {
            if (window.nostr) {
                console.log('✓ nos2x/Alby detected!');
                $('.nostr-login-button').attr('title', 'Nostr extension detected ✓');
                $('.nostr-login-button').css('border', '2px solid #22c55e');
            } else {
                console.log('✗ No Nostr extension detected');
                console.log('Checked for window.nostr:', typeof window.nostr);
                console.log('Current domain:', window.location.hostname);
                console.log('Full origin:', window.location.origin);
                $('.nostr-login-button').attr('title', 'Please install a Nostr extension (Alby or nos2x)');
                $('.nostr-login-button').css('border', '2px solid #f59e0b');
                
                // Add a help message
                if ($('.nostr-login-status').length) {
                    $('.nostr-login-status').html(
                        '<p style="font-size: 12px; color: #f59e0b; margin-top: 10px;">' +
                        '⚠️ nos2x not detected. Make sure:<br>' +
                        '1. nos2x is installed and enabled<br>' +
                        '2. You\'ve granted permission to <strong>' + window.location.hostname + '</strong><br>' +
                        '3. Try refreshing the page after enabling nos2x' +
                        '</p>'
                    );
                }
            }
        }, 2000); // Give more time for extension to load
        
        // Also check on window load event
        $(window).on('load', function() {
            setTimeout(function() {
                console.log('Window loaded. Checking again for window.nostr:', !!window.nostr);
            }, 500);
        });
    });

    // Listen for the extension being loaded dynamically
    document.addEventListener('nos2x-loaded', function() {
        console.log('nos2x loaded event detected');
        $('.nostr-login-button').css('border', '2px solid #22c55e');
        $('.nostr-login-status').empty();
    });

})(jQuery);

