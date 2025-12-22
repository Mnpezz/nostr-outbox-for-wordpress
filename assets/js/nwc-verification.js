/**
 * NWC Payment Verification using nostr-tools
 * 
 * This handles real-time payment verification via NWC lookup_invoice
 */

(function($) {
    'use strict';

    window.NWCVerification = {
        nwcConnection: null,

    /**
     * Initialize with merchant NWC connection
     */
    init: function(nwcConnectionString) {
        if (!nwcConnectionString) {
            console.warn('NWC Verification: No connection string provided');
            return false;
        }

        // Check if nostr-tools has the required functions
        if (!window.NostrTools || typeof window.NostrTools.relayInit !== 'function') {
            console.warn('NWC Verification: nostr-tools relay functions not available. Falling back to Coinos API check.');
            return false;
        }

        try {
            this.nwcConnection = this.parseNWC(nwcConnectionString);
            console.log('✓ NWC Verification: Initialized with relay support', {
                relay: this.nwcConnection.relay,
                pubkey: this.nwcConnection.pubkey.substring(0, 8) + '...'
            });
            return true;
        } catch (error) {
            console.error('NWC Verification: Parse error', error);
            return false;
        }
    },

        /**
         * Parse NWC connection string
         */
        parseNWC: function(nwcString) {
            // Format: nostr+walletconnect://pubkey?relay=wss://...&secret=...
            const url = nwcString.replace('nostr+walletconnect://', 'http://');
            const parsed = new URL(url);
            
            return {
                pubkey: parsed.hostname,
                relay: parsed.searchParams.get('relay'),
                secret: parsed.searchParams.get('secret')
            };
        },

        /**
         * Check if invoice is paid via NWC lookup_invoice
         */
        checkInvoicePaid: async function(invoice, paymentHash) {
            if (!this.nwcConnection) {
                console.error('NWC not initialized');
                return false;
            }

            console.log('Checking invoice via NWC lookup_invoice...');

            try {
                // Create NWC request event
                const request = {
                    method: 'lookup_invoice',
                    params: {
                        invoice: invoice,
                        payment_hash: paymentHash
                    }
                };

                // Send NWC request and get response
                const response = await this.sendNWCRequest(request);
                
                console.log('NWC lookup_invoice response:', response);

                // Check if invoice is settled/paid
                if (response && response.result) {
                    const settled = response.result.settled || 
                                  response.result.paid || 
                                  (response.result.status === 'settled') ||
                                  (response.result.status === 'paid');
                    
                    console.log('Invoice paid status:', settled);
                    return settled;
                }

                return false;
            } catch (error) {
                console.error('NWC lookup error:', error);
                return false;
            }
        },

        /**
         * Send NWC request via Nostr
         */
        sendNWCRequest: async function(request) {
            return new Promise(async (resolve, reject) => {
                try {
                    const relay = window.NostrTools.relayInit(this.nwcConnection.relay);
                    await relay.connect();

                    console.log('Connected to relay:', this.nwcConnection.relay);

                    // Create encrypted content
                    const content = JSON.stringify(request);
                    
                    // Create and sign event (kind 23194 for NWC)
                    const event = {
                        kind: 23194,
                        created_at: Math.floor(Date.now() / 1000),
                        tags: [['p', this.nwcConnection.pubkey]],
                        content: content, // Should be encrypted but simplified for now
                    };

                    // Sign event with merchant's secret key
                    const signedEvent = await this.signEvent(event, this.nwcConnection.secret);
                    
                    console.log('Sending NWC request event...');

                    // Publish event
                    await relay.publish(signedEvent);

                    // Listen for response
                    const sub = relay.sub([{
                        kinds: [23195], // NWC response kind
                        '#p': [this.getPublicKey(this.nwcConnection.secret)],
                        since: Math.floor(Date.now() / 1000) - 5
                    }]);

                    const timeout = setTimeout(() => {
                        relay.close();
                        reject(new Error('Timeout waiting for NWC response'));
                    }, 10000);

                    sub.on('event', (responseEvent) => {
                        clearTimeout(timeout);
                        console.log('Received NWC response event');
                        
                        try {
                            const response = JSON.parse(responseEvent.content);
                            relay.close();
                            resolve(response);
                        } catch (error) {
                            relay.close();
                            reject(error);
                        }
                    });

                } catch (error) {
                    console.error('NWC request error:', error);
                    reject(error);
                }
            });
        },

        /**
         * Sign Nostr event
         */
        signEvent: async function(event, secretKey) {
            if (!window.NostrTools || !window.NostrTools.getEventHash || !window.NostrTools.signEvent) {
                throw new Error('nostr-tools signing functions not available');
            }

            const pubkey = this.getPublicKey(secretKey);
            event.pubkey = pubkey;
            event.id = window.NostrTools.getEventHash(event);
            event.sig = await window.NostrTools.signEvent(event, secretKey);
            
            return event;
        },

        /**
         * Get public key from secret key
         */
        getPublicKey: function(secretKey) {
            if (!window.NostrTools || !window.NostrTools.getPublicKey) {
                throw new Error('nostr-tools getPublicKey not available');
            }
            return window.NostrTools.getPublicKey(secretKey);
        }
    };

})(jQuery);

