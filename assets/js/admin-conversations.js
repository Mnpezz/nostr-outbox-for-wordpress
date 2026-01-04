/**
 * Admin Conversations JS
 * Handles threading, profile fetching, and chat UI for the admin panel.
 */
(function ($) {
    'use strict';

    const App = {
        conversations: {}, // Key: pubkey, Value: { profile: {}, messages: [] }
        currentPubkey: null,
        sentMessages: [], // From DB
        relays: [],
        sitePrivkey: null,
        sitePubkey: null,

        init: function () {
            // Check dependencies
            if (typeof window.NostrTools === 'undefined') {
                $('#nostr-conversations-root').html('<p style="color:red; padding: 20px;">Error: nostr-tools not loaded.</p>');
                return;
            }

            // Load config from localized script
            if (typeof nostrChatAdminData === 'undefined') {
                console.error('nostrChatAdminData missing');
                return;
            }

            this.sentMessages = nostrChatAdminData.sentMessages || [];
            this.relays = nostrChatAdminData.relays || [];
            this.sitePrivkey = nostrChatAdminData.sitePrivkey;

            // Get site pubkey
            try {
                this.sitePubkey = window.NostrTools.getPublicKey(this.sitePrivkey);
            } catch (e) {
                console.error('Invalid site privkey');
                return;
            }

            this.renderLayout();
            this.loadConversations();
            this.bindEvents();

            // Poll for new messages every 30s
            setInterval(() => this.fetchNewMessages(), 30000);
        },

        fetchNewMessages: async function () {
            if (!this.sitePubkey) return;

            // 1. Fetch recent Kind 4 events (last 5 mins)
            const since = Math.floor(Date.now() / 1000) - 300;
            const pool = new window.NostrTools.SimplePool();
            const filters = [
                { kinds: [4], authors: [this.sitePubkey], since: since }, // Sent
                { kinds: [4], '#p': [this.sitePubkey], since: since }   // Received
            ];

            try {
                const events = await pool.list(this.relays, filters);
                let hasUpdates = false;

                for (const event of events) {
                    const isSent = event.pubkey === this.sitePubkey;
                    let otherParty = isSent ? (event.tags.find(t => t[0] === 'p')?.[1]) : event.pubkey;

                    if (!otherParty) continue;

                    // Initialize if new conversation
                    if (!this.conversations[otherParty]) {
                        this.conversations[otherParty] = {
                            pubkey: otherParty,
                            profile: { name: otherParty.substring(0, 8) + '...' },
                            messages: [],
                            lastActivity: 0
                        };
                        // Fetch profile for new person?
                        // For simplicity, just let them be "unknown" until full refresh or lazy load
                        // Or we can trigger a profile fetch here.
                        this.fetchProfiles([otherParty], pool);
                    }

                    const conv = this.conversations[otherParty];

                    // Check if message exists
                    if (!conv.messages.find(m => m.id === event.id)) {
                        conv.messages.push(event);
                        conv.lastActivity = Math.max(conv.lastActivity, event.created_at);
                        hasUpdates = true;

                        // If this conversation is open, append the message directly!
                        if (this.currentPubkey === otherParty) {
                            try {
                                const otherKey = (event.pubkey === this.sitePubkey) ? otherParty : event.pubkey;
                                const text = await window.NostrTools.nip04.decrypt(this.sitePrivkey, otherKey, event.content);
                                this.renderMessage(text, event.pubkey === this.sitePubkey, event.created_at);
                                this.scrollToBottom();
                            } catch (e) {
                                console.error('Auto-decrypt failed', e);
                            }
                        }
                    }
                }

                if (hasUpdates) {
                    this.renderConversationList();
                }

            } catch (e) {
                console.error('Polling error:', e);
            }
        },

        renderLayout: function () {
            const html = `
                <div class="nostr-conversations-wrapper">
                    <div class="nostr-conv-sidebar">
                        <div class="nostr-conv-search">
                            <input type="text" id="nostr-conv-search" placeholder="Search conversations...">
                        </div>
                        <div class="nostr-conv-list" id="nostr-conv-list">
                            <div style="padding: 20px; text-align: center; color: #7F00FF;">
                                Loading threads... <span class="spinner is-active" style="float:none;"></span>
                            </div>
                        </div>
                    </div>
                    <div class="nostr-conv-main" id="nostr-conv-main">
                        <div class="nostr-conv-empty">
                            Select a conversation to start chatting
                        </div>
                    </div>
                </div>
            `;
            $('#nostr-conversations-root').html(html);
        },

        loadConversations: async function () {
            // 1. Process Sent Messages (DB)
            // Group by recipient (who is the other party)
            this.sentMessages.forEach(msg => {
                const otherParty = msg.recipient; // The user we sent TO
                if (!this.conversations[otherParty]) {
                    this.conversations[otherParty] = {
                        pubkey: otherParty,
                        profile: { name: 'Unknown', about: '', picture: '' },
                        messages: [],
                        lastActivity: 0
                    };
                }

                this.conversations[otherParty].messages.push({
                    id: msg.event_id,
                    content: msg.message, // This might be unencrypted in recent DB logs?
                    // Note: DB doesn't store unencrypted content usually for sent messages in this plugin?
                    // Actually, 'nostr_dm_sent_log' usually stores metadata.
                    // Wait, let's check what `ajax_get_outbox` returns.
                    // It returns { recipient, subject, time, ... } but NOT content usually unless we added it.
                    // The previous `ajax_get_outbox` code didn't show content.
                    // If we don't have content, we can't show it.
                    // Implementation Plan Check: "Fetch Sent DMs from DB". 
                    // If DB doesn't have content, we might need to fetch our own sent events from relays too!
                    // Let's assume we fetch EVERYTHING from relays for full content.
                    // DB is good for "knowing who we messaged", but relays have the content.
                    type: 'sent',
                    created_at: new Date(msg.time).getTime() / 1000 // approx
                    // If we rely on DB content, we might be missing it.
                    // BETTER STRATEGY: Fetch Kind 4 from Relays where 'authors' = [sitePubkey].
                });
            });

            // CLEAR DB messages if we are going to fetch real ones.
            // Actually, let's just use the DB to initialize the "contact list" so we know who to look for?
            // No, fetching Kind 4 for our pubkey is better.
            this.conversations = {};

            try {
                const pool = new window.NostrTools.SimplePool();

                // Fetch Sent (Author = Me) AND Received (Tag = Me)
                // Note: 'authors' filter is OR. But we need:
                // 1. authors=[Me] (Sent)
                // 2. #p=[Me] (Received)

                const filters = [
                    { kinds: [4], authors: [this.sitePubkey], limit: 200 },
                    { kinds: [4], '#p': [this.sitePubkey], limit: 200 }
                ];

                const events = await pool.list(this.relays, filters);

                for (const event of events) {
                    const isSent = event.pubkey === this.sitePubkey;
                    // If sent, other party is in 'p' tag.
                    // If received, other party is 'event.pubkey'.

                    let otherParty = null;
                    if (isSent) {
                        const pTag = event.tags.find(t => t[0] === 'p');
                        if (pTag) otherParty = pTag[1];
                    } else {
                        otherParty = event.pubkey;
                    }

                    if (!otherParty) continue;

                    if (!this.conversations[otherParty]) {
                        this.conversations[otherParty] = {
                            pubkey: otherParty,
                            profile: { name: otherParty.substring(0, 8) + '...' }, // temp name
                            messages: [],
                            lastActivity: 0
                        };
                    }

                    // Store raw event for later decryption (lazy decrypt)
                    this.conversations[otherParty].messages.push(event);
                }

                // Fetch profiles (Kind 0) for all keys
                const pubkeys = Object.keys(this.conversations);
                if (pubkeys.length > 0) {
                    await this.fetchProfiles(pubkeys, pool);
                }

                this.renderConversationList();

            } catch (e) {
                console.error('Error loading conversations:', e);
                $('#nostr-conv-list').html('<p style="padding:15px; color:red;">Error loading from relays.</p>');
            }
        },

        updatedProfileCache: {},

        fetchProfiles: async function (pubkeys, pool) {
            const filter = { kinds: [0], authors: pubkeys };
            try {
                const events = await pool.list(this.relays, [filter]);
                events.forEach(ev => {
                    try {
                        const content = JSON.parse(ev.content);
                        this.updatedProfileCache[ev.pubkey] = content;

                        if (this.conversations[ev.pubkey]) {
                            this.conversations[ev.pubkey].profile = content;
                            // Fallback name if display_name is missing
                            if (!this.conversations[ev.pubkey].profile.name) {
                                this.conversations[ev.pubkey].profile.name =
                                    content.display_name || content.username || content.name;
                            }
                        }
                    } catch (e) { }
                });
            } catch (e) {
                console.error('Profile fetch error:', e);
            }
        },

        renderConversationList: function () {
            const list = $('#nostr-conv-list');
            list.empty();

            // Convert to array and sort
            const sorted = Object.values(this.conversations).sort((a, b) => {
                // Find latest message time in each
                const timeA = Math.max(...a.messages.map(m => m.created_at));
                const timeB = Math.max(...b.messages.map(m => m.created_at));

                // Store for later
                a.lastActivity = timeA;
                b.lastActivity = timeB;

                return timeB - timeA; // Descending
            });

            if (sorted.length === 0) {
                list.html('<p style="padding:20px; text-align:center; color:#666;">No conversations found.</p>');
                return;
            }

            sorted.forEach(conv => {
                const name = conv.profile.name || conv.profile.display_name || (conv.pubkey.substring(0, 8) + '...');
                const pic = conv.profile.picture || ''; // Default avatar via CSS if empty

                const item = $(`
                    <div class="nostr-conv-item" data-pubkey="${conv.pubkey}">
                        <div class="nostr-conv-avatar">
                            ${pic ? `<img src="${pic}" onerror="this.style.display='none'">` : ''}
                        </div>
                        <div class="nostr-conv-info">
                            <span class="nostr-conv-name">${this.escapeHtml(name)}</span>
                            <span class="nostr-conv-meta">
                                <span class="nostr-conv-preview">Click to load...</span>
                                <span class="nostr-conv-time">${this.formatTime(conv.lastActivity)}</span>
                            </span>
                        </div>
                    </div>
                `);

                item.on('click', () => this.openConversation(conv.pubkey));
                list.append(item);
            });
        },

        openConversation: async function (pubkey) {
            this.currentPubkey = pubkey;

            // Highlight sidebar
            $('.nostr-conv-item').removeClass('active');
            $(`.nostr-conv-item[data-pubkey="${pubkey}"]`).addClass('active');

            const conv = this.conversations[pubkey];
            const name = conv.profile.name || conv.profile.display_name || (pubkey.substring(0, 8) + '...');

            // Render basic chat structure
            const html = `
                <div class="nostr-conv-header">
                    <span>${this.escapeHtml(name)}</span>
                    <button id="nostr-conv-refresh" class="button button-small" title="Refresh">↻</button>
                </div>
                <div class="nostr-conv-messages" id="nostr-conv-messages">
                    <div class="nostr-conv-loading">Decrypting messages...</div>
                </div>
                <div class="nostr-conv-input">
                    <textarea id="nostr-conv-reply-text" placeholder="Type a message..."></textarea>
                    <button id="nostr-conv-send-btn" class="nostr-conv-send-btn">
                         <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>
            `;
            $('#nostr-conv-main').html(html);

            // Bind input events
            $('#nostr-conv-send-btn').on('click', () => this.sendMessage());
            $('#nostr-conv-reply-text').on('keypress', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            $('#nostr-conv-refresh').on('click', () => this.loadConversations());

            // Decrypt messages
            // Sort by time ascending
            const events = conv.messages.sort((a, b) => a.created_at - b.created_at);

            const msgContainer = $('#nostr-conv-messages');
            msgContainer.empty(); // Clear loading text

            for (const event of events) {
                try {
                    let text = '';
                    // Decrypt
                    // If Sent: encrypt(myPriv, theirPub) -> decrypt(myPriv, theirPub, content) NO.
                    // If Sent: It is encrypted FOR them. Shared secret (myPriv, theirPub).
                    // If Received: Encrypted For Me. Shared secret (myPriv, senderPub).

                    // In nip04.decrypt(privkey, pubkey, content):
                    // pubkey is the OTHER party's pubkey.

                    // If I sent it, event.pubkey is ME. I need to use 'pubkey' (the recipient).
                    // If I received it, event.pubkey is THEM. I need to use 'event.pubkey'.

                    const otherKey = (event.pubkey === this.sitePubkey) ? pubkey : event.pubkey;
                    text = await window.NostrTools.nip04.decrypt(this.sitePrivkey, otherKey, event.content);

                    this.renderMessage(text, event.pubkey === this.sitePubkey, event.created_at);

                } catch (e) {
                    console.error('Decrypt failed for event', event.id, e);
                    // this.renderMessage('<i>(Decryption failed)</i>', event.pubkey === this.sitePubkey, event.created_at);
                }
            }

            this.scrollToBottom();
        },

        renderMessage: function (text, isSent, timestamp) {
            const timeStr = new Date(timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const type = isSent ? 'nostr-msg-sent' : 'nostr-msg-received';

            const html = `
                <div class="nostr-msg-bubble ${type}">
                    ${this.escapeHtml(text)}
                    <span class="nostr-msg-time">${timeStr}</span>
                </div>
            `;
            $('#nostr-conv-messages').append(html);
        },

        sendMessage: async function () {
            const input = $('#nostr-conv-reply-text');
            const btn = $('#nostr-conv-send-btn');
            const text = input.val().trim();
            const recipient = this.currentPubkey;

            if (!text || !recipient) return;

            input.prop('disabled', true);
            btn.prop('disabled', true);

            try {
                // Encrypt
                // Use NIP-04
                const encrypted = await window.NostrTools.nip04.encrypt(this.sitePrivkey, recipient, text);

                const event = {
                    kind: 4,
                    created_at: Math.floor(Date.now() / 1000),
                    tags: [['p', recipient]],
                    content: encrypted,
                    pubkey: this.sitePubkey
                };

                // Sign
                const signed = window.NostrTools.finishEvent(event, this.sitePrivkey);

                // Publish
                const pool = new window.NostrTools.SimplePool();
                await pool.publish(this.relays, signed);

                // Optimistic Render
                this.renderMessage(text, true, event.created_at);
                this.scrollToBottom();
                input.val('');

                // Update conversation list tracking
                if (this.conversations[recipient]) {
                    this.conversations[recipient].messages.push(signed);
                    this.conversations[recipient].lastActivity = event.created_at;
                }

                // Log to DB via AJAX (Optional, but good for "Outbox")
                // We'll skip the AJAX call to `ajax_send_manual_dm` since that does everything server side.
                // But we should probably log it. 
                // Let's call a lightweight logging endpoint? 
                // For now, rely on standard "outbox" fetching from relays which we just implemented.

            } catch (e) {
                alert('Send failed: ' + e.message);
            } finally {
                input.prop('disabled', false);
                btn.prop('disabled', false);
                input.focus();
            }
        },

        scrollToBottom: function () {
            const el = document.getElementById('nostr-conv-messages');
            if (el) el.scrollTop = el.scrollHeight;
        },

        // Helpers
        escapeHtml: function (text) {
            if (!text) return '';
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        },

        formatTime: function (timestamp) {
            const date = new Date(timestamp * 1000);
            const now = new Date();
            const diff = (now - date) / 1000;

            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h';
            return date.toLocaleDateString();
        },

        bindEvents: function () {
            $('#nostr-conv-search').on('keyup', (e) => {
                const term = e.target.value.toLowerCase();
                $('.nostr-conv-item').each(function () {
                    const name = $(this).find('.nostr-conv-name').text().toLowerCase();
                    $(this).toggle(name.indexOf(term) > -1);
                });
            });
        }
    };

    $(document).ready(function () {
        if ($('#nostr-conversations-root').length) {
            App.init();
        }
    });

})(jQuery);
