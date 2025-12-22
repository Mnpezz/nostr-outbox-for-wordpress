/**
 * NWC Client for Customer One-Click Payments
 * Handles pay_invoice requests via customer's connected wallet
 */

class NWCClient {
    constructor() {
        this.connection = null;
        this.relay = null;
    }

    /**
     * Initialize with customer's NWC connection
     */
    async init(nwcConnectionString) {
        if (!nwcConnectionString || !window.NostrTools) {
            throw new Error('Missing nostr-tools or NWC connection');
        }

        try {
            this.connection = this.parseNWC(nwcConnectionString);
            console.log('NWC Client: Initialized', {
                relay: this.connection.relay,
                pubkey: this.connection.pubkey.substring(0, 8) + '...'
            });
            return true;
        } catch (error) {
            console.error('NWC Client: Parse error', error);
            throw error;
        }
    }

    /**
     * Parse NWC connection string
     */
    parseNWC(nwcString) {
        // Format: nostr+walletconnect://pubkey?relay=wss://...&secret=...
        const url = nwcString.replace('nostr+walletconnect://', 'http://');
        const parsed = new URL(url);
        
        return {
            pubkey: parsed.hostname,
            relay: parsed.searchParams.get('relay'),
            secret: parsed.searchParams.get('secret')
        };
    }

    /**
     * Pay invoice using NWC pay_invoice
     */
    async payInvoice(invoice) {
        if (!this.connection) {
            throw new Error('NWC not initialized');
        }

        console.log('Paying invoice via NWC...');

        try {
            const request = {
                method: 'pay_invoice',
                params: {
                    invoice: invoice
                }
            };

            const response = await this.sendNWCRequest(request);
            
            console.log('NWC pay_invoice response:', response);

            if (response && response.result && response.result.preimage) {
                console.log('✓ Payment successful!');
                return response.result;
            } else if (response && response.error) {
                throw new Error(response.error.message || 'Payment failed');
            } else {
                throw new Error('Invalid response from wallet');
            }
        } catch (error) {
            console.error('NWC payment error:', error);
            throw error;
        }
    }

    /**
     * Send NWC request via Nostr
     */
    async sendNWCRequest(request) {
        return new Promise(async (resolve, reject) => {
            try {
                this.relay = window.NostrTools.relayInit(this.connection.relay);
                await this.relay.connect();

                console.log('Connected to relay:', this.connection.relay);

                // Create content (should be encrypted in production)
                const content = JSON.stringify(request);
                
                // Get our pubkey
                const ourPubkey = this.getPublicKey(this.connection.secret);
                
                // Create and sign event (kind 23194 for NWC request)
                const event = {
                    kind: 23194,
                    created_at: Math.floor(Date.now() / 1000),
                    tags: [['p', this.connection.pubkey]], // Wallet's pubkey
                    content: content,
                    pubkey: ourPubkey
                };

                // Sign event
                const signedEvent = await this.signEvent(event, this.connection.secret);
                
                console.log('Sending NWC request event...');

                // Publish event
                await this.relay.publish(signedEvent);

                // Listen for response
                const sub = this.relay.sub([{
                    kinds: [23195], // NWC response kind
                    '#p': [ourPubkey],
                    since: Math.floor(Date.now() / 1000) - 5
                }]);

                const timeout = setTimeout(() => {
                    this.relay.close();
                    reject(new Error('Timeout waiting for wallet response. Please try again.'));
                }, 30000); // 30 seconds for payment

                sub.on('event', (responseEvent) => {
                    clearTimeout(timeout);
                    console.log('Received NWC response event');
                    
                    try {
                        const response = JSON.parse(responseEvent.content);
                        this.relay.close();
                        resolve(response);
                    } catch (error) {
                        this.relay.close();
                        reject(error);
                    }
                });

            } catch (error) {
                console.error('NWC request error:', error);
                if (this.relay) {
                    this.relay.close();
                }
                reject(error);
            }
        });
    }

    /**
     * Sign Nostr event
     */
    async signEvent(event, secretKey) {
        if (!window.NostrTools || !window.NostrTools.getEventHash || !window.NostrTools.signEvent) {
            throw new Error('nostr-tools signing functions not available');
        }

        event.id = window.NostrTools.getEventHash(event);
        event.sig = await window.NostrTools.signEvent(event, secretKey);
        
        return event;
    }

    /**
     * Get public key from secret key
     */
    getPublicKey(secretKey) {
        if (!window.NostrTools || !window.NostrTools.getPublicKey) {
            throw new Error('nostr-tools getPublicKey not available');
        }
        return window.NostrTools.getPublicKey(secretKey);
    }
}

// Make it globally accessible
window.NWCClient = NWCClient;

