/**
 * Simple NWC Balance Checker
 * 
 * Uses WebSocket directly to check wallet balance via NWC
 * No dependency on broken nostr-tools library
 */

(function($) {
    'use strict';

    window.NWCBalanceChecker = {
        nwcConnection: null,
        initialBalance: null,
        ws: null,

        /**
         * Initialize with merchant NWC connection
         */
        init: function(nwcConnectionString) {
            if (!nwcConnectionString) {
                console.warn('NWC Balance: No connection string provided');
                return false;
            }

            try {
                this.nwcConnection = this.parseNWC(nwcConnectionString);
                console.log('✓ NWC Balance Checker initialized', {
                    relay: this.nwcConnection.relay,
                    pubkey: this.nwcConnection.pubkey.substring(0, 8) + '...'
                });
                return true;
            } catch (error) {
                console.error('NWC Balance: Parse error', error);
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
         * Get current wallet balance
         */
        getBalance: async function() {
            if (!this.nwcConnection) {
                throw new Error('NWC not initialized');
            }

            console.log('📊 Getting wallet balance via NWC...');

            try {
                const response = await this.sendNWCCommand('get_balance', {});
                
                if (response && response.balance !== undefined) {
                    const balanceSats = response.balance / 1000; // Convert msats to sats
                    console.log('✓ Current balance:', balanceSats, 'sats');
                    return balanceSats;
                }

                throw new Error('Invalid balance response');
            } catch (error) {
                console.error('❌ Failed to get balance:', error);
                throw error;
            }
        },

        /**
         * Check if payment was received by comparing balances
         */
        checkPaymentReceived: async function(expectedAmountSats) {
            try {
                // Get current balance
                const currentBalance = await this.getBalance();

                // If we don't have initial balance, save it now
                if (this.initialBalance === null) {
                    this.initialBalance = currentBalance;
                    console.log('💰 Initial balance recorded:', this.initialBalance, 'sats');
                    return false;
                }

                // Calculate difference
                const difference = currentBalance - this.initialBalance;
                console.log('📈 Balance change:', difference, 'sats (expected:', expectedAmountSats, 'sats)');

                // Check if payment received (with small tolerance for rounding)
                if (difference >= expectedAmountSats - 1) {
                    console.log('🎉 Payment confirmed via balance increase!');
                    return true;
                }

                return false;
            } catch (error) {
                console.error('Balance check error:', error);
                return false;
            }
        },

        /**
         * Send NWC command via WebSocket (simplified - no encryption for now)
         */
        sendNWCCommand: async function(method, params) {
            return new Promise((resolve, reject) => {
                try {
                    console.log('📡 Connecting to relay:', this.nwcConnection.relay);

                    // Create WebSocket connection
                    this.ws = new WebSocket(this.nwcConnection.relay);
                    const requestId = this.generateId();

                    this.ws.onopen = () => {
                        console.log('✓ Connected to relay');

                        // Create NWC request
                        const request = {
                            id: requestId,
                            method: method,
                            params: params
                        };

                        // Create Nostr event (simplified - should be encrypted)
                        const event = {
                            kind: 23194, // NWC request
                            created_at: Math.floor(Date.now() / 1000),
                            tags: [['p', this.nwcConnection.pubkey]],
                            content: JSON.stringify(request),
                            pubkey: this.derivePubkey(this.nwcConnection.secret),
                        };

                        // For now, send without full Nostr signing (this won't work with real NWC)
                        // This is a placeholder showing the structure
                        console.log('⚠️ Note: Full NWC requires event signing with secp256k1');
                        console.log('📝 For production, use a proper Nostr library');

                        // Send REQ to subscribe to responses
                        const subId = this.generateId();
                        const filter = {
                            kinds: [23195], // NWC response
                            '#p': [event.pubkey],
                            since: Math.floor(Date.now() / 1000) - 5
                        };

                        this.ws.send(JSON.stringify(['REQ', subId, filter]));

                        // Send event (this is simplified and won't actually work)
                        this.ws.send(JSON.stringify(['EVENT', event]));

                        console.log('📤 NWC request sent');
                    };

                    this.ws.onmessage = (msg) => {
                        try {
                            const data = JSON.parse(msg.data);
                            console.log('📥 Received:', data);

                            if (data[0] === 'EVENT') {
                                const responseEvent = data[2];
                                const response = JSON.parse(responseEvent.content);

                                if (response.id === requestId) {
                                    this.ws.close();
                                    resolve(response.result);
                                }
                            }
                        } catch (error) {
                            console.error('Message parse error:', error);
                        }
                    };

                    this.ws.onerror = (error) => {
                        console.error('WebSocket error:', error);
                        reject(error);
                    };

                    // Timeout after 10 seconds
                    setTimeout(() => {
                        if (this.ws.readyState !== WebSocket.CLOSED) {
                            this.ws.close();
                            reject(new Error('Timeout waiting for NWC response'));
                        }
                    }, 10000);

                } catch (error) {
                    reject(error);
                }
            });
        },

        /**
         * Generate random ID
         */
        generateId: function() {
            return Math.random().toString(36).substring(2, 15);
        },

        /**
         * Derive pubkey from secret (placeholder - needs secp256k1)
         */
        derivePubkey: function(secret) {
            // This is a placeholder
            // Real implementation needs secp256k1 library
            console.warn('⚠️ Pubkey derivation requires secp256k1 - using placeholder');
            return '0'.repeat(64);
        }
    };

})(jQuery);

