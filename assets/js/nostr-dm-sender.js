/**
 * Nostr DM Sender
 * 
 * Handles sending queued Nostr encrypted DMs (NIP-04)
 */

(function() {
    'use strict';

    // Check if we have NostrTools available
    if (typeof window.NostrTools === 'undefined') {
        console.warn('NostrTools not available for DM sending');
        return;
    }

    /**
     * Send a Nostr encrypted DM (NIP-04)
     */
    async function sendEncryptedDM(recipientPubkey, message, sitePrivkey, relays) {
        try {
            // Get site's public key
            const sitePubkey = window.NostrTools.getPublicKey(sitePrivkey);
            
            // Encrypt the message
            let encryptedContent;
            if (window.NostrTools.nip04 && window.NostrTools.nip04.encrypt) {
                encryptedContent = await window.NostrTools.nip04.encrypt(sitePrivkey, recipientPubkey, message);
            } else if (window.NostrTools.nip44 && window.NostrTools.nip44.encrypt) {
                // Try NIP-44 if NIP-04 not available
                encryptedContent = await window.NostrTools.nip44.encrypt(sitePrivkey, recipientPubkey, message);
            } else {
                throw new Error('No encryption method available');
            }
            
            // Create DM event (kind 4 for NIP-04)
            const event = {
                kind: 4,
                created_at: Math.floor(Date.now() / 1000),
                tags: [['p', recipientPubkey]],
                content: encryptedContent,
                pubkey: sitePubkey
            };
            
            // Sign the event
            const signedEvent = window.NostrTools.finishEvent(event, sitePrivkey);
            
            // Publish to relays
            const pool = new window.NostrTools.SimplePool();
            await pool.publish(relays, signedEvent);
            
            console.log('✅ Nostr DM sent successfully! Event ID:', signedEvent.id);
            
            // Return the signed event so we can log it
            return signedEvent;
        } catch (error) {
            console.error('❌ Error sending Nostr DM:', error);
            return null;
        }
    }

    /**
     * Process queued DMs
     */
    async function processQueue() {
        try {
            // Fetch queue from server
            const response = await fetch(nostrDMData.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'get_dm_queue_for_processing',
                    nonce: nostrDMData.nonce
                })
            });
            
            const data = await response.json();
            
            if (!data.success || !data.data.queue || data.data.queue.length === 0) {
                console.log('📭 No messages in queue');
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
            
            console.log('📤 Processing', queue.length, 'queued Nostr DMs...');
            
            let sent = 0;
            let failed = 0;
            
            // Send each DM
            for (const dm of queue) {
                console.log(`📨 Sending to ${dm.username} (${dm.recipient.substring(0, 16)}...)`);
                
                const signedEvent = await sendEncryptedDM(dm.recipient, dm.message, privkey, relays);
                
                if (signedEvent) {
                    // Mark as sent in database (removes from queue, adds to outbox)
                    try {
                        await fetch(nostrDMData.ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'mark_dm_as_sent',
                                nonce: nostrDMData.nonce,
                                dm_id: dm.id,
                                event_id: signedEvent.id,
                                recipient: dm.recipient,
                                subject: dm.subject || 'Order Notification',
                                username: dm.username || 'unknown'
                            })
                        });
                        sent++;
                        console.log(`✅ DM sent and logged to outbox`);
                    } catch (err) {
                        console.error('⚠️ DM sent but failed to log:', err);
                        failed++;
                    }
                } else {
                    console.error(`❌ Failed to send DM to ${dm.username}`);
                    failed++;
                }
                
                // Small delay between messages
                await new Promise(resolve => setTimeout(resolve, 1000));
            }
            
            console.log(`✅ Queue processing complete! Sent: ${sent}, Failed: ${failed}`);
            
            // Reload page to show updated queue/outbox
            if (sent > 0) {
                setTimeout(() => location.reload(), 1000);
            }
        } catch (error) {
            console.error('❌ Error processing DM queue:', error);
        }
    }

    // Make processQueue available globally so the button can call it
    window.processNostrDMQueue = processQueue;

    // Auto-process every 5 minutes if admin is viewing the page
    if (nostrDMData && nostrDMData.isAdmin === '1') {
        // Check for new messages every 5 minutes
        setInterval(processQueue, 300000);
    }
})();

