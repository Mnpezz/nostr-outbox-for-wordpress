/**
 * Nostr Customer Service Chat Widget
 */
(function ($) {
    'use strict';

    const Widget = {
        hasHistoryLoaded: false,
        supportPubkey: null,
        userPubkey: null,
        relays: [],
        lastEventTime: 0,
        pool: null,
        sub: null,

        init: function () {
            if (typeof nostrChatData === 'undefined' || !nostrChatData.enabled) {
                return;
            }

            this.relays = nostrChatData.relays || [
                'wss://relay.damus.io',
                'wss://relay.snort.social',
                'wss://nos.lol'
            ];

            this.injectHTML();
            this.setupMobileIntegration();
            this.bindEvents();
            this.checkExtension();

            // Initialize Pool
            if (window.NostrTools && window.NostrTools.SimplePool) {
                this.pool = new window.NostrTools.SimplePool();
            }
        },

        injectHTML: function () {
            let avatarHtml = '';
            if (nostrChatData.show_avatar) {
                const avatarUrl = nostrChatData.avatar_url || 'https://www.gravatar.com/avatar/00000000000000000000000000000000?d=mp&f=y';
                avatarHtml = `<img src="${avatarUrl}" class="nostr-chat-header-avatar" alt="Avatar">`;
            }

            const html = `
                <div id="nostr-chat-widget-button" class="nostr-chat-widget-button" title="Chat with Support">
                    <div id="nostr-chat-unread-badge" class="nostr-chat-unread-badge"></div>
                    <svg class="nostr-chat-widget-icon" viewBox="0 0 24 24">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                    </svg>
                </div>

                <div id="nostr-chat-window" class="nostr-chat-window">
                    <div class="nostr-chat-header">
                        <div class="nostr-chat-header-left">
                            ${avatarHtml}
                            <span class="nostr-chat-title">Customer Service</span>
                        </div>
                        <button class="nostr-chat-close">&times;</button>
                    </div>
                    
                    <div class="nostr-chat-tabs">
                        <div class="nostr-chat-tab active" data-tab="chat">Chat</div>
                        <div class="nostr-chat-tab" data-tab="mobile">Mobile</div>
                    </div>

                    <!-- Chat View -->
                    <div id="nostr-view-chat" class="nostr-view">
                        <div id="nostr-chat-messages" class="nostr-chat-messages">
                            <div class="nostr-chat-message received">
                                Hello! How can we help you today?
                            </div>
                        </div>
                        <div class="nostr-chat-footer-notice">
                            Note: Gift-wrapped messages are not yet supported.
                        </div>
                        <div class="nostr-chat-status" id="nostr-chat-status" style="display:none;"></div>
                        <div class="nostr-chat-input-area">
                            <input type="text" id="nostr-chat-input" class="nostr-chat-input" placeholder="Type a message...">
                            <button id="nostr-chat-send-btn" class="nostr-chat-send-btn">
                                <svg class="nostr-chat-send-icon" viewBox="0 0 24 24">
                                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Mobile/QR View -->
                    <div id="nostr-view-mobile" class="nostr-view nostr-view-hidden">
                        <div class="nostr-qr-container">
                             <div class="nostr-notice">
                                Scan to continue this conversation on your preferred mobile Nostr app:
                            </div>
                            <img id="nostr-qr-img" class="nostr-qr-code" src="" alt="Scan QR Code" />
                            <div class="nostr-notice" style="color:#7F00FF; font-weight:bold;">
                                <a id="nostr-qr-link" href="#" target="_blank" style="color:inherit; text-decoration:none;">Open in App</a>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('body').append(html);
        },

        setupMobileIntegration: function () {
            // Check for Storefront footer bar
            const $footerBar = $('.storefront-handheld-footer-bar ul');
            if ($footerBar.length) {
                // Add Chat Item
                const chatItem = `
                    <li class="chat">
                        <a href="#">Chat</a>
                    </li>
                `;
                $footerBar.append(chatItem);

                // Adjust columns from 3 to 4
                $footerBar.removeClass('columns-3').addClass('columns-4');

                // Bind click
                $footerBar.find('li.chat a').on('click', (e) => {
                    e.preventDefault();
                    this.toggleChat();
                });

                $('body').addClass('storefront-handheld-footer-bar-enabled');
            }
        },

        bindEvents: function () {
            $('#nostr-chat-widget-button').on('click', () => this.toggleChat());
            $('.nostr-chat-close').on('click', () => this.toggleChat());

            $('#nostr-chat-send-btn').on('click', () => this.sendMessage());
            $('#nostr-chat-input').on('keypress', (e) => {
                if (e.which === 13) this.sendMessage();
            });

            // Tabs
            $('.nostr-chat-tab').on('click', (e) => {
                const tab = $(e.target).data('tab');
                this.switchTab(tab);
            });
        },

        toggleChat: function () {
            const $chat = $('#nostr-chat-window');
            $chat.toggleClass('open');

            // Hide badge when opening
            if ($chat.hasClass('open')) {
                $('#nostr-chat-unread-badge').hide();
                this.scrollToBottom();
                $('#nostr-chat-input').focus();

                if (!this.hasHistoryLoaded) {
                    this.loadHistory();
                }
            }
        },

        scrollToBottom: function () {
            const $messages = $('#nostr-chat-messages');
            if ($messages.length) {
                $messages.scrollTop($messages[0].scrollHeight);
            }
        },

        switchTab: function (tab) {
            $('.nostr-chat-tab').removeClass('active');
            $(`.nostr-chat-tab[data-tab="${tab}"]`).addClass('active');

            if (tab === 'chat') {
                $('#nostr-view-chat').removeClass('nostr-view-hidden');
                $('#nostr-view-mobile').addClass('nostr-view-hidden');
            } else {
                $('#nostr-view-chat').addClass('nostr-view-hidden');
                $('#nostr-view-mobile').removeClass('nostr-view-hidden');
                this.renderQR();
            }
        },

        renderQR: function () {
            const pubkey = this.getSupportPubkey();
            if (!pubkey) return;

            let npub = pubkey;
            if (!pubkey.startsWith('npub') && window.NostrTools) {
                try {
                    npub = window.NostrTools.nip19.npubEncode(pubkey);
                } catch (e) { }
            }

            // Generate QR Code URL
            // Using nostr:<npub> schema
            const uri = `nostr:${npub}`;
            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(uri)}`;

            $('#nostr-qr-img').attr('src', qrUrl);
            $('#nostr-qr-link').attr('href', uri);
        },

        checkExtension: function () {
            if (window.nostr) {
                return;
            }
            setTimeout(() => {
                if (!window.nostr) {
                    $('#nostr-chat-status').html(`
                        <div style="text-align: left; padding: 5px;">
                            <strong>Nostr extension required to chat securely.</strong><br>
                            <p style="margin: 5px 0;">We recommend:</p>
                            <ul style="margin: 5px 0 10px 20px; list-style: disc;">
                                <li><strong>Desktop:</strong> <a href="https://getalby.com" target="_blank">Alby</a>, <a href="https://github.com/fiatjaf/nos2x" target="_blank">nos2x</a></li>
                                <li><strong>Android:</strong> <a href="https://play.google.com/store/apps/details?id=com.nostr.universe&hl=en_US&gl=US&pli=1" target="_blank">Spring</a>, <a href="https://github.com/greenart7c3/Amber" target="_blank">Amber</a></li>
                                <li><strong>iOS:</strong> <a href="https://apps.apple.com/us/app/nostash/id6744309333" target="_blank">Nostash</a> (Safari)</li>
                            </ul>
                            <p style="margin: 0; font-size: 11px;">
                                <a href="https://nostr.com" target="_blank">What is Nostr?</a>
                            </p>
                        </div>
                    `).show();
                    $('#nostr-chat-input, #nostr-chat-send-btn').prop('disabled', true);
                }
            }, 1000);
        },

        // Helper to resolve support pubkey
        getSupportPubkey: function () {
            if (this.supportPubkey) return this.supportPubkey;

            let key = nostrChatData.support_npub;
            if (key.startsWith('npub')) {
                if (window.NostrTools && window.NostrTools.nip19) {
                    try {
                        const decoded = window.NostrTools.nip19.decode(key);
                        this.supportPubkey = decoded.data;
                    } catch (e) {
                        console.error('Invalid support npub:', e);
                    }
                }
            } else {
                this.supportPubkey = key; // Assume hex
            }
            return this.supportPubkey;
        },

        loadHistory: async function () {
            if (!window.nostr || !window.NostrTools || !window.NostrTools.SimplePool) {
                console.log('Dependencies not ready for history loading');
                return;
            }

            try {
                // Determine keys
                this.userPubkey = await window.nostr.getPublicKey();
                const supportKey = this.getSupportPubkey();

                if (!this.userPubkey || !supportKey) return;

                if (!this.userPubkey || !supportKey) return;

                $('#nostr-chat-status').html('Loading history...').show();

                if (!this.pool) this.pool = new window.NostrTools.SimplePool();

                // Fetch DMs (Kind 4)
                // We need events where:
                // 1. authors = [user], #p = [support] (Outgoing)
                // 2. authors = [support], #p = [user] (Incoming)
                // We can do this with two filters

                const filters = [
                    {
                        kinds: [4],
                        authors: [this.userPubkey],
                        '#p': [supportKey],
                        limit: 50
                    },
                    {
                        kinds: [4],
                        authors: [supportKey],
                        '#p': [this.userPubkey],
                        limit: 50
                    }
                ];

                const events = await this.pool.list(this.relays, filters);

                // Sort by time
                events.sort((a, b) => a.created_at - b.created_at);

                // Clear initial "Hello" message if we have history
                if (events.length > 0) {
                    $('#nostr-chat-messages').empty();
                }

                // Decrypt and display
                for (const event of events) {
                    try {
                        let text;
                        // For outgoing, we need to decrypt our own message using 'supportKey' as the "other"
                        // wait, nip04.encrypt(pubkey, msg) uses recipient pubkey.
                        // nip04.decrypt(pubkey, ciphertext) uses sender pubkey? No.
                        // Window.nostr.nip04.decrypt(pubkey, ciphertext) -> decrypts message from 'pubkey'?
                        // Actually: 
                        // If I sent it (author=me), I encrypted it for 'supportKey'. 
                        // So to decrypt it, I need to use 'supportKey' as the counterparty?
                        // NIP-04: "ciphetext = encrypt(shared_secret, text)". Shared secret is ECDH(myPriv, theirPub).
                        // It is symmetric. ECDH(myPriv, theirPub) == ECDH(theirPriv, myPub).
                        // But wait, nos2x API: decrypt(pubkey, ciphertext). 
                        // It implies "decrypt message encrypted with shared secret of (myPriv, pubkey)".

                        // So for incoming (author=support), cipher was made with ECDH(supportPriv, myPub) == ECDH(myPriv, supportPub).
                        // So use supportKey.

                        // For outgoing (author=me), cipher was made with ECDH(myPriv, supportPub).
                        // So also use supportKey? YES.
                        // NIP-04 IV is inside the content.

                        const otherPubkey = (event.pubkey === this.userPubkey) ? supportKey : event.pubkey;
                        text = await window.nostr.nip04.decrypt(otherPubkey, event.content);

                        const type = (event.pubkey === this.userPubkey) ? 'sent' : 'received';
                        this.addMessage(text, type);

                    } catch (e) {
                        console.error('Failed to decrypt:', e);
                    }
                }

                $('#nostr-chat-status').hide();
                this.scrollToBottom();
                this.hasHistoryLoaded = true;

                if (events.length > 0) {
                    const lastEvent = events[events.length - 1]; // sorted oldest to newest
                    this.lastEventTime = lastEvent.created_at;
                }

                // Start Subscription
                this.subscribeToMessages();

            } catch (err) {
                console.error('History load failed:', err);
                $('#nostr-chat-status').html('Failed to load history').delay(2000).fadeOut();
            }
        },

        sendMessage: async function () {
            const $input = $('#nostr-chat-input');
            const message = $input.val().trim();
            const $btn = $('#nostr-chat-send-btn');

            if (!message) return;

            if (!window.nostr) {
                alert('Please install a Nostr extension (like Alby) to chat securely. See the links above!');
                return;
            }

            $input.prop('disabled', true);
            $btn.prop('disabled', true);

            try {
                // UI Update (Optimistic)
                this.addMessage(message, 'sent');
                $input.val('');
                this.scrollToBottom();

                // Get Keys
                if (!this.userPubkey) this.userPubkey = await window.nostr.getPublicKey();
                const supportKey = this.getSupportPubkey();

                // Encrypt
                // Note: We use supportKey to encrypt
                const encrypted = await window.nostr.nip04.encrypt(supportKey, message);

                // Create Event
                const event = {
                    kind: 4,
                    created_at: Math.floor(Date.now() / 1000),
                    tags: [['p', supportKey]],
                    content: encrypted,
                    pubkey: this.userPubkey
                };

                // Sign
                const signedEvent = await window.nostr.signEvent(event);

                // Publish
                if (this.pool) {
                    // We don't await all, just send it
                    this.pool.publish(this.relays, signedEvent);
                } else {
                    console.warn('NostrTools missing, cannot publish');
                }

            } catch (err) {
                console.error('Send failed:', err);
                this.addMessage('Error sending message: ' + err.message, 'received');
            } finally {
                $input.prop('disabled', false);
                $btn.prop('disabled', false);
                $input.focus();
            }
        },

        addMessage: function (text, type) {
            const html = `<div class="nostr-chat-message ${type}">${this.escapeHtml(text)}</div>`;
            $('#nostr-chat-messages').append(html);
        },

        escapeHtml: function (text) {
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        },

        subscribeToMessages: function () {
            if (this.sub) return; // Already subscribed
            if (!this.pool || !this.userPubkey) return;

            const supportKey = this.getSupportPubkey();

            // Subscribe to new incoming messages
            const filter = {
                kinds: [4],
                authors: [supportKey],
                '#p': [this.userPubkey],
                since: this.lastEventTime + 1
            };

            this.sub = this.pool.sub(this.relays, [filter]);

            this.sub.on('event', async (event) => {
                // Deduplicate based on time/id again just in case
                if (event.created_at <= this.lastEventTime) return;

                try {
                    const text = await window.nostr.nip04.decrypt(supportKey, event.content);
                    if (!text) return;

                    // Update UI
                    this.addMessage(text, 'received');

                    if (event.created_at > this.lastEventTime) {
                        this.lastEventTime = event.created_at;
                    }

                    // Badge / Scroll
                    const $chat = $('#nostr-chat-window');
                    if ($chat.hasClass('open')) {
                        this.scrollToBottom();
                    } else {
                        $('#nostr-chat-unread-badge').show();
                    }

                } catch (e) {
                    console.error('Sub decrypt failed:', e);
                }
            });
        },


    };

    $(document).ready(function () {
        Widget.init();
    });

})(jQuery);
