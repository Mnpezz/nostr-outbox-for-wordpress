/**
 * NWC Lightning Payment Handler
 */

(function($) {
    'use strict';

    const NWCPayment = {
        orderId: null,
        amountSats: null,
        invoice: null,
        paymentHash: null,
        checkInterval: null,
        merchantNWC: null,
        nwcClient: null,              // Alby SDK client for balance checking
        initialBalance: null,         // Balance when invoice was created
        balanceCheckEnabled: false,   // Whether balance checking is active

        init: function() {
            console.log('NWC Payment: Initializing...');
            
            // Get order details from page
            const container = $('.nwc-payment-container');
            if (container.length === 0) {
                console.log('NWC Payment: No payment container found');
                return;
            }

            this.orderId = container.attr('id').replace('nwc-payment-', '');
            
            // Get merchant NWC connection from backend
            this.getMerchantNWC();
        },

        getMerchantNWC: function() {
            console.log('NWC Payment: Getting merchant wallet and creating invoice...');
            
            $.ajax({
                url: nwcPaymentData.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_merchant_nwc',
                    nonce: nwcPaymentData.nonce,
                    order_id: this.orderId
                },
                success: (response) => {
                    if (response.success) {
                        console.log('NWC Payment: Got invoice data', response.data);
                        
                        if (response.data.method === 'lnurl' && response.data.invoice) {
                            // Real invoice from Lightning Address
                            this.invoice = response.data.invoice;
                            this.paymentHash = response.data.payment_hash;
                            this.amountSats = response.data.amount_sats;
                            this.merchantNWC = response.data.merchant_nwc || ''; // Store for NWC lookup
                            
                            // Initialize NWC verification if merchant wallet provided
                            if (response.data.merchant_nwc) {
            console.log('🔍 Merchant NWC received, checking for SDK...');
            // Check for Alby SDK in multiple locations (matching Alby PoS)
            console.log('Checking for Alby SDK...');
            console.log('window.nwc:', typeof window.nwc);
            console.log('window.nwc?.NWCClient:', typeof window.nwc?.NWCClient);
            console.log('window.webln:', typeof window.webln);
            if (window.webln) {
                console.log('window.webln properties:', Object.keys(window.webln));
                console.log('window.webln.NWCClient:', typeof window.webln.NWCClient);
            }
            console.log('Available Nostr objects:', Object.keys(window).filter(k => k.toLowerCase().includes('nostr') || k === 'nwc'));
            
            // Check if SDK script tag exists
            const sdkScript = document.querySelector('script[src*="getalby"]');
            console.log('Alby SDK script tag found:', !!sdkScript);
            if (sdkScript) {
                console.log('SDK script src:', sdkScript.src);
            }
            
            // DEBUG: Check ALL window properties that might be from Alby SDK
            console.log('Checking for ALL possible Alby SDK exports...');
            const albyRelated = Object.keys(window).filter(k => 
                k.toLowerCase().includes('alby') || 
                k.toLowerCase().includes('nwc') ||
                k === 'sdk' ||
                k === 'SDK' ||
                k === 'getalby'
            );
            console.log('Alby-related globals:', albyRelated);
            if (albyRelated.length > 0) {
                albyRelated.forEach(key => {
                    console.log(`window.${key}:`, typeof window[key], window[key]);
                });
            }
                                
                                // Check for Alby SDK NWCClient (CORRECT LOCATION!)
                                if (window.sdk && window.sdk.nwc && window.sdk.nwc.NWCClient) {
                                    console.log('✅ Alby SDK NWCClient found at window.sdk.nwc.NWCClient!');
                                    console.log('NWC lookup_invoice will be used for payment verification');
                                    // Don't need to initialize anything - checkInvoiceViaAlbySDK will handle it
                                }
                                // Legacy: Try old NostrWebLNProvider location
                                else if (typeof window.NostrWebLNProvider !== 'undefined') {
                                    console.log('✅ Legacy: NostrWebLNProvider detected');
                                    console.log('Initializing balance checking...');
                                    this.initAlbyBalanceCheck(response.data.merchant_nwc);
                                }
                                // Fallback to old NWC verification
                                else if (window.NWCVerification) {
                                    console.log('Using NWCVerification fallback...');
                                    const nwcEnabled = window.NWCVerification.init(response.data.merchant_nwc);
                                    if (nwcEnabled) {
                                        console.log('✓ NWC instant verification enabled - will check every 3 seconds');
                                    } else {
                                        console.log('⚠ NWC verification not available - using Coinos API fallback');
                                    }
                                } else {
                                    console.log('⚠️ No NWC SDK available - using Coinos API fallback');
                                    console.log('Note: For QR code auto-verification, ensure Alby SDK loads correctly');
                                }
                            } else {
                                console.log('⚠️ No merchant NWC configured in settings');
                                console.log('To enable auto-verification, configure NWC in Settings → Nostr Login & Pay');
                            }
                            
                            console.log('NWC Payment: Real LNURL invoice received');
                            
                            // Display the invoice immediately
                            this.displayInvoice();
                            
                            // Start checking for payment
                            this.startPaymentCheck();
                        } else {
                            // Fallback or NWC method
                            this.amountSats = response.data.amount_sats;
                            this.showError('Invoice generation not yet implemented for this payment method. Please configure a Lightning Address in Settings → Nostr Login & Pay → NWC Settings.');
                        }
                    } else {
                        this.showError(response.data || 'Failed to create invoice');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX Error:', xhr.responseText);
                    this.showError('Network error: ' + error);
                }
            });
        },

        generateFakeInvoice: function(amountSats) {
            // Generate a realistic-looking fake BOLT11 invoice
            return 'lnbc' + amountSats + 'n1' + this.generateRandomString(300);
        },

        generateRandomHash: function() {
            let hash = '';
            const chars = '0123456789abcdef';
            for (let i = 0; i < 64; i++) {
                hash += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return hash;
        },

        generateRandomString: function(length) {
            let result = '';
            const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            for (let i = 0; i < length; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return result;
        },

        saveInvoiceToOrder: function() {
            $.ajax({
                url: nwcPaymentData.ajax_url,
                type: 'POST',
                data: {
                    action: 'save_nwc_invoice',
                    nonce: nwcPaymentData.nonce,
                    order_id: this.orderId,
                    invoice: this.invoice,
                    payment_hash: this.paymentHash
                }
            });
        },

        displayInvoice: function() {
            console.log('NWC Payment: Displaying invoice...');
            console.log('Merchant NWC configured:', this.merchantNWC ? 'YES ✓' : 'NO ✗');
            
            $('#nwc-payment-status').hide();
            
            let displayHtml = '';
            
            // Smart display logic based on NWC configuration
            if (!this.merchantNWC) {
                // NO NWC configured - Only show browser wallet option (no QR auto-verification)
                console.log('📱 Browser wallet only mode (no NWC for QR verification)');
                displayHtml = `
                    <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin-bottom: 20px;">
                        <strong style="color: #92400e;">⚠️ Payment Verification Limited</strong>
                        <p style="margin: 5px 0 0; font-size: 13px; color: #92400e;">
                            Only browser wallet payments are available. QR code payments require NWC configuration for auto-verification.
                        </p>
                    </div>
                    <h3 style="margin-bottom: 20px; text-align: center;">Pay with Browser Wallet</h3>
                    <div style="text-align: center; margin: 30px 0;">
                        <button class="nwc-browser-pay-button" onclick="NWCPayment.payWithBrowserWallet()" style="
                            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); 
                            color: white; 
                            border: none; 
                            padding: 20px 40px; 
                            font-size: 18px; 
                            font-weight: bold; 
                            border-radius: 8px; 
                            cursor: pointer;
                            display: none;
                            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                        ">
                            ⚡ Pay with Browser Wallet
                        </button>
                    </div>
                    <div style="margin-top: 20px; padding: 15px; background: #eff6ff; border-radius: 6px; text-align: center;" id="browser-wallet-status">
                        <p style="margin: 0; color: #64748b; font-size: 14px;">
                            Checking for browser wallet extension...
                        </p>
                    </div>
                    <details style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 6px; cursor: pointer;">
                        <summary style="font-weight: bold; color: #374151;">Alternative: Copy Invoice for Manual Payment</summary>
                        <div style="margin-top: 15px;">
                            <div class="nwc-invoice-string" style="font-size: 11px;">${this.invoice}</div>
                            <button class="nwc-copy-button" onclick="NWCPayment.copyInvoice()" style="margin-top: 10px;">📋 Copy Invoice</button>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #6b7280;">
                                ⚠️ Manual verification required - you'll need to contact the store owner after payment.
                            </p>
                        </div>
                    </details>
                `;
            } else {
                // NWC configured - Show both QR code (with auto-verification) and browser wallet
                console.log('✅ Full payment mode (QR + Browser Wallet with NWC auto-verification)');
                displayHtml = `
                    <h3 style="margin-bottom: 20px;">⚡ Lightning Payment</h3>
                    <div class="nwc-invoice-qr" id="nwc-qr-code"></div>
                    <div class="nwc-invoice-string">${this.invoice}</div>
                    <div style="display: flex; gap: 10px; margin-top: 15px; justify-content: center; flex-wrap: wrap;">
                        <button class="nwc-copy-button" onclick="NWCPayment.copyInvoice()">📋 Copy Invoice</button>
                        <button class="nwc-browser-pay-button" onclick="NWCPayment.payWithBrowserWallet()" style="
                            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); 
                            color: white; 
                            border: none; 
                            padding: 12px 24px; 
                            font-size: 14px; 
                            font-weight: bold; 
                            border-radius: 6px; 
                            cursor: pointer;
                            display: none;
                        ">
                            ⚡ Pay with Browser Wallet
                        </button>
                    </div>
                    <div class="nwc-payment-instructions">
                        <h4 style="margin-top: 0;">How to Pay:</h4>
                        <ol style="margin: 10px 0 0 20px; padding: 0;">
                            <li><strong>Option 1:</strong> Click "⚡ Pay with Browser Wallet" if you have Alby/nos2x installed</li>
                            <li><strong>Option 2:</strong> Scan the QR code with any Lightning wallet app</li>
                            <li>Confirm the payment in your wallet</li>
                            <li>Wait for automatic confirmation (3-6 seconds)</li>
                        </ol>
                    </div>
                    <div style="margin-top: 20px; padding: 15px; background: #eff6ff; border-radius: 6px;">
                        <p style="margin: 0; color: #1e40af;">
                            ⏳ Waiting for payment... <span class="spinner" style="display: inline-block; width: 16px; height: 16px; border: 2px solid #3b82f6; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></span>
                        </p>
                    </div>
                `;
            }
            
            $('#nwc-invoice-display').html(displayHtml).fadeIn();
            
            // Generate QR code ONLY if NWC is configured
            if (this.merchantNWC && typeof QRCode !== 'undefined') {
                new QRCode(document.getElementById('nwc-qr-code'), {
                    text: this.invoice.toUpperCase(),
                    width: 256,
                    height: 256,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }

            // Show browser wallet button if nos2x/Alby is detected
            this.checkBrowserWallet();
        },

        checkBrowserWallet: function() {
            // Check if window.nostr exists (nos2x, Alby, etc.)
            if (window.nostr) {
                $('.nwc-browser-pay-button').show();
                console.log('✓ Browser wallet detected - showing pay button');
                
                // Update status message if no NWC configured
                if (!this.merchantNWC) {
                    $('#browser-wallet-status').html(`
                        <p style="margin: 0; color: #22c55e; font-size: 14px;">
                            ✅ Browser wallet detected! Click the button above to pay.
                        </p>
                    `);
                }
            } else {
                // Check again after a delay in case extension loads late
                setTimeout(() => {
                    if (window.nostr) {
                        $('.nwc-browser-pay-button').show();
                        console.log('✓ Browser wallet detected (delayed) - showing pay button');
                        
                        if (!this.merchantNWC) {
                            $('#browser-wallet-status').html(`
                                <p style="margin: 0; color: #22c55e; font-size: 14px;">
                                    ✅ Browser wallet detected! Click the button above to pay.
                                </p>
                            `);
                        }
                    } else if (!this.merchantNWC) {
                        // No wallet detected and no NWC - show installation instructions
                        $('#browser-wallet-status').html(`
                            <div style="background: #fee; border-left: 4px solid #ef4444; padding: 15px;">
                                <strong style="color: #dc2626;">❌ No Browser Wallet Detected</strong>
                                <p style="margin: 5px 0 0; font-size: 13px; color: #991b1b;">
                                    Please install <a href="https://getalby.com" target="_blank" style="color: #dc2626; text-decoration: underline;">Alby</a> or 
                                    <a href="https://github.com/fiatjaf/nos2x" target="_blank" style="color: #dc2626; text-decoration: underline;">nos2x</a> browser extension to pay.
                                </p>
                            </div>
                        `);
                    }
                }, 1000);
            }
        },

        payWithBrowserWallet: async function() {
            console.log('🚀 Browser wallet payment initiated...');
            console.log('Invoice to pay:', this.invoice);
            
            if (!window.nostr) {
                alert('No browser wallet detected. Please install Alby or nos2x extension.');
                return;
            }

            try {
                // Disable button and show loading
                const button = $('.nwc-browser-pay-button');
                button.prop('disabled', true).html('⏳ Requesting payment...');

                console.log('Checking for window.webln:', typeof window.webln);
                
                // Try WebLN first (Alby supports this)
                if (window.webln) {
                    try {
                        console.log('Attempting WebLN payment...');
                        await window.webln.enable();
                        console.log('WebLN enabled, sending payment...');
                        const result = await window.webln.sendPayment(this.invoice);
                        console.log('WebLN payment result:', result);
                        
                        if (result && result.preimage) {
                            console.log('✅ Payment sent via WebLN! Preimage:', result.preimage);
                            button.html('✓ Payment Sent!').css('background', '#22c55e');
                            
                            // Immediately mark as paid since we have cryptographic proof (preimage)
                            console.log('🎉 Auto-completing order with preimage proof...');
                            await this.notifyServerPaymentComplete(result.preimage);
                            return;
                        } else {
                            console.warn('⚠️ WebLN payment succeeded but no preimage returned:', result);
                            // Still notify server even without preimage
                            console.log('Notifying server anyway...');
                            await this.notifyServerPaymentComplete('');
                            return;
                        }
                    } catch (weblnError) {
                        console.error('❌ WebLN error:', weblnError);
                        console.log('Trying NWC fallback...');
                    }
                }

                // Try NWC via window.nostr
                console.log('Checking for window.nostr.nwc:', typeof window.nostr.nwc);
                if (window.nostr.nwc) {
                    console.log('Attempting NWC payment...');
                    const result = await window.nostr.nwc({
                        method: 'pay_invoice',
                        params: {
                            invoice: this.invoice
                        }
                    });
                    console.log('NWC payment result:', result);

                    if (result && result.preimage) {
                        console.log('✅ Payment sent via NWC! Preimage:', result.preimage);
                        button.html('✓ Payment Sent!').css('background', '#22c55e');
                        
                        // Immediately mark as paid since we have cryptographic proof (preimage)
                        console.log('🎉 Auto-completing order with preimage proof...');
                        await this.notifyServerPaymentComplete(result.preimage);
                        return;
                    }
                }

                // If neither worked, just copy to clipboard and prompt user
                console.warn('⚠️ Neither WebLN nor NWC payment methods worked, copying invoice');
                this.copyInvoice();
                button.prop('disabled', false).html('⚡ Pay with Browser Wallet');
                alert('Please open your Lightning wallet extension and approve the payment.\n\nThe invoice has been copied to your clipboard.');

            } catch (error) {
                console.error('❌ Browser wallet payment error:', error);
                
                $('.nwc-browser-pay-button')
                    .prop('disabled', false)
                    .html('⚡ Pay with Browser Wallet');
                
                // Copy invoice as fallback
                this.copyInvoice();
                alert('Could not connect to wallet extension.\n\nThe invoice has been copied to your clipboard. Please paste it in your wallet app.');
            }
        },

        oneClickPay: async function() {
            if (!nwcPaymentData.userWallet) {
                alert('No wallet connected. Please connect your NWC wallet first in My Account → NWC Wallet.');
                return;
            }

            console.log('🔥 One-click payment initiated with saved wallet connection...');
            console.log('Using wallet:', nwcPaymentData.userWallet.substring(0, 50) + '...');
            
            // Disable button and show loading
            const button = $('.nwc-one-click-pay');
            button.prop('disabled', true).html('⏳ Connecting to wallet...');

            try {
                // Check if NWCClient is available
                if (typeof window.NWCClient === 'undefined') {
                    throw new Error('NWC Client not loaded. Please refresh the page.');
                }

                // Initialize user's NWC
                console.log('Initializing NWC client with user wallet...');
                const userNWC = new window.NWCClient();
                await userNWC.init(nwcPaymentData.userWallet);

                button.html('⏳ Sending payment...');

                // Pay the invoice
                console.log('Sending payment request...');
                const result = await userNWC.payInvoice(this.invoice);
                
                if (result && result.preimage) {
                    console.log('✓ Payment successful! Preimage:', result.preimage);
                    button.html('✓ Payment Sent!').css('background', '#22c55e');
                    
                    // Immediately mark as paid since we have cryptographic proof (preimage)
                    console.log('🎉 Auto-completing order with preimage proof...');
                    await this.notifyServerPaymentComplete(result.preimage);
                } else {
                    throw new Error('No preimage returned - payment may have failed');
                }
            } catch (error) {
                console.error('❌ One-click payment error:', error);
                button.prop('disabled', false).html('⚡ Pay with Connected Wallet');
                
                // Show user-friendly error with clear alternatives
                if (error.message.includes('not loaded')) {
                    alert('Payment system not ready. Please refresh the page and try again.');
                } else if (error.message.includes('relayInit') || error.message.includes('NostrTools')) {
                    // Offer to copy invoice and redirect to Coinos
                    const openCoinos = confirm(
                        '⚠️ Direct wallet payment is not available in this browser.\n\n' +
                        '✓ WORKING ALTERNATIVES:\n\n' +
                        '1. Click "Pay with Browser Wallet" (purple button below)\n' +
                        '2. Scan the QR code with your mobile wallet\n' +
                        '3. Copy invoice and pay on Coinos.io\n\n' +
                        'Click OK to copy the invoice and open Coinos in a new tab.'
                    );
                    
                    if (openCoinos) {
                        this.copyInvoice();
                        window.open('https://coinos.io/', '_blank');
                        alert('✓ Invoice copied!\n\nPaste it into Coinos to complete payment.\n\nThis page will detect payment automatically.');
                    }
                    
                    button.prop('disabled', false).html('⚡ Pay with Connected Wallet');
                } else {
                    alert('Payment failed: ' + error.message + '\n\nPlease try the QR code payment method or "Pay with Browser Wallet" button instead.');
                }
            }
        },

        copyInvoice: function() {
            const tempInput = document.createElement('input');
            tempInput.value = this.invoice;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            
            // Show feedback
            $('.nwc-copy-button').text('✓ Copied!').css('background', '#22c55e');
            setTimeout(() => {
                $('.nwc-copy-button').text('📋 Copy Invoice').css('background', '#3b82f6');
            }, 2000);
        },

        startPaymentCheck: function() {
            console.log('NWC Payment: Starting payment check...');
            
            // Check immediately
            this.checkPaymentStatus();
            
            // Then check every 3 seconds
            this.checkInterval = setInterval(() => {
                this.checkPaymentStatus();
            }, 3000);
        },

        checkPaymentStatus: async function() {
            // Debug: Show what we're checking
            console.log('[Payment Check] merchantNWC:', this.merchantNWC ? 'SET ✓' : 'NOT SET ✗');
            console.log('[Payment Check] window.sdk.nwc.NWCClient:', (window.sdk && window.sdk.nwc && window.sdk.nwc.NWCClient) ? 'AVAILABLE ✓' : 'NOT AVAILABLE ✗');
            
            // Method 1: Try NWC lookup_invoice via Alby SDK (BEST - works for all payments!)
            // Check for SDK at the CORRECT location: window.sdk.nwc.NWCClient
            if (this.merchantNWC && window.sdk && window.sdk.nwc && window.sdk.nwc.NWCClient) {
                try {
                    console.log('🔍 Checking invoice via NWC lookup_invoice (Alby SDK)...');
                    const isPaid = await this.checkInvoiceViaAlbySDK();
                    if (isPaid) {
                        console.log('✅ Payment confirmed via NWC lookup_invoice!');
                        return;
                    }
                } catch (error) {
                    console.log('❌ NWC lookup error:', error.message);
                }
            } else {
                // Debug: Show why we're not using NWC
                if (!this.merchantNWC) {
                    console.log('⚠️ Skipping NWC: merchantNWC not configured');
                } else if (!window.sdk || !window.sdk.nwc || !window.sdk.nwc.NWCClient) {
                    console.log('⚠️ Skipping NWC: SDK not available');
                }
            }
            
            // Method 2: Try NWC verification (fallback)
            if (window.NWCVerification && window.NWCVerification.nwcConnection) {
                try {
                    const isPaid = await window.NWCVerification.checkInvoicePaid(
                        this.invoice, 
                        this.paymentHash
                    );
                    
                    if (isPaid) {
                        console.log('✓ Payment confirmed via NWC lookup_invoice!');
                        this.notifyServerPaymentComplete();
                        return;
                    }
                } catch (error) {
                    // Silently fall back to server check
                }
            }
            
            // Method 3: Server-side check (Coinos API) - last resort fallback
            $.ajax({
                url: nwcPaymentData.ajax_url,
                type: 'POST',
                data: {
                    action: 'check_nwc_payment',
                    nonce: nwcPaymentData.nonce,
                    order_id: this.orderId,
                    payment_hash: this.paymentHash
                },
                success: (response) => {
                    if (response.success && response.data.paid) {
                        console.log('✓ Payment confirmed via Coinos API!');
                        this.handlePaymentSuccess();
                    }
                },
                error: (xhr, status, error) => {
                    console.warn('Payment check failed:', error);
                }
            });
        },

        /**
         * Wait for Alby SDK to load (with retries)
         */
        waitForAlbySDK: async function(maxRetries = 10, delayMs = 300) {
            for (let i = 0; i < maxRetries; i++) {
                if (typeof window.NostrWebLNProvider !== 'undefined') {
                    console.log(`✅ Alby SDK loaded after ${i} retries (${i * delayMs}ms)`);
                    return true;
                }
                console.log(`⏳ Waiting for Alby SDK... (attempt ${i + 1}/${maxRetries})`);
                await new Promise(resolve => setTimeout(resolve, delayMs));
            }
            console.error('❌ Alby SDK did not load after', maxRetries * delayMs, 'ms');
            return false;
        },

        /**
         * Initialize Alby SDK for balance-based payment detection
         */
        initAlbyBalanceCheck: async function(nwcConnectionString) {
            try {
                console.log('🔧 Initializing Alby NWC SDK for balance checking...');
                console.log('NWC connection string length:', nwcConnectionString ? nwcConnectionString.length : 0);
                
                // Wait for Alby SDK to load
                const sdkLoaded = await this.waitForAlbySDK();
                if (!sdkLoaded) {
                    console.error('❌ NostrWebLNProvider is undefined - SDK not loaded');
                    console.log('Available on window:', Object.keys(window).filter(k => k.includes('Nostr') || k.includes('nostr')));
                    return false;
                }
                
                console.log('✓ NostrWebLNProvider found, creating client...');
                
                // Create NWC client using Alby SDK
                this.nwcClient = new window.NostrWebLNProvider({ nostrWalletConnectUrl: nwcConnectionString });
                
                console.log('✓ NWC client created, getting initial balance...');
                
                // Get initial balance
                const balanceResponse = await this.nwcClient.getBalance();
                this.initialBalance = balanceResponse.balance / 1000; // Convert msats to sats
                
                console.log('✅ Alby NWC SDK initialized successfully!');
                console.log('💰 Initial balance:', this.initialBalance, 'sats');
                console.log('📊 Will check for balance increase of', this.amountSats, 'sats');
                
                this.balanceCheckEnabled = true;
                return true;
                
            } catch (error) {
                console.error('❌ Failed to initialize Alby NWC SDK:');
                console.error('Error type:', error.constructor.name);
                console.error('Error message:', error.message);
                console.error('Full error:', error);
                console.log('⚠️ Will use Coinos API fallback instead');
                return false;
            }
        },

        /**
         * Check payment via balance comparison
         */
        checkPaymentViaBalance: async function() {
            if (!this.nwcClient || !this.balanceCheckEnabled) {
                return false;
            }

            try {
                // Get current balance
                const balanceResponse = await this.nwcClient.getBalance();
                const currentBalance = balanceResponse.balance / 1000; // Convert msats to sats
                
                // Calculate difference
                const difference = currentBalance - this.initialBalance;
                
                console.log('📊 Balance check:', {
                    initial: this.initialBalance,
                    current: currentBalance,
                    difference: difference,
                    expected: this.amountSats
                });

                // Check if payment received (with small tolerance for rounding)
                if (difference >= this.amountSats - 1) {
                    console.log('🎉 Payment confirmed via balance increase!');
                    return true;
                }

                return false;

            } catch (error) {
                console.error('Balance check error:', error);
                // Don't disable balance checking on error, might be temporary
                return false;
            }
        },

        /**
         * Check invoice status via NWC lookup_invoice using Alby SDK
         */
        /**
         * Check invoice status via NWC lookup_invoice using Alby SDK
         * This matches the Alby PoS implementation exactly
         */
        checkInvoiceViaAlbySDK: async function() {
            if (!this.merchantNWC) {
                return false;
            }

            try {
                // Check ALL possible locations for NWCClient
                let NWCClient = null;
                
                // Option 1: window.sdk.nwc.NWCClient (CORRECT LOCATION! Found it!)
                if (window.sdk && window.sdk.nwc && window.sdk.nwc.NWCClient) {
                    NWCClient = window.sdk.nwc.NWCClient;
                    console.log('✓ Found NWCClient at window.sdk.nwc.NWCClient');
                }
                // Option 2: window.nwc.NWCClient (alternative)
                else if (window.nwc && window.nwc.NWCClient) {
                    NWCClient = window.nwc.NWCClient;
                    console.log('✓ Found NWCClient at window.nwc.NWCClient');
                }
                // Option 3: window.webln.NWCClient (alternative location)
                else if (window.webln && window.webln.NWCClient) {
                    NWCClient = window.webln.NWCClient;
                    console.log('✓ Found NWCClient at window.webln.NWCClient');
                }
                // Option 4: Check if SDK exports as { nwc: { NWCClient } }
                else if (window.AlbySDK && window.AlbySDK.nwc && window.AlbySDK.nwc.NWCClient) {
                    NWCClient = window.AlbySDK.nwc.NWCClient;
                    console.log('✓ Found NWCClient at window.AlbySDK.nwc.NWCClient');
                }
                // Option 5: Global NWCClient
                else if (typeof window.NWCClient !== 'undefined') {
                    NWCClient = window.NWCClient;
                    console.log('✓ Found NWCClient at window.NWCClient');
                }
                
                if (!NWCClient) {
                    console.warn('⚠️ Alby SDK NWCClient not found in any location');
                    console.log('window.nwc:', typeof window.nwc);
                    console.log('window.webln:', typeof window.webln);
                    console.log('window.AlbySDK:', typeof window.AlbySDK);
                    console.log('window.NWCClient:', typeof window.NWCClient);
                    if (window.webln) {
                        console.log('window.webln properties:', Object.keys(window.webln));
                    }
                    if (window.nwc) {
                        console.log('window.nwc properties:', Object.keys(window.nwc));
                    }
                    return false;
                }

                console.log('🔌 Initializing NWCClient (Alby SDK v3.6.1)...');
                console.log('NWC URL:', this.merchantNWC.substring(0, 50) + '...');
                
                const nwcClient = new NWCClient({
                    nostrWalletConnectUrl: this.merchantNWC
                });

                // NOTE: NWCClient v3.6.1 doesn't have enable() - just use it directly!
                console.log('✅ NWC client created');

                // Use lookupInvoice exactly like Alby PoS
                console.log('🔍 Looking up invoice via NWC...');
                const result = await nwcClient.lookupInvoice({
                    invoice: this.invoice
                });

                console.log('📥 NWC lookup result:', result);

                // Check if settled (Alby PoS checks result.settled)
                if (result && (result.settled || result.settled_at || result.preimage)) {
                    console.log('✅ Invoice is PAID!');
                    console.log('Payment details:', {
                        settled: result.settled,
                        settled_at: result.settled_at,
                        preimage: result.preimage ? '(present)' : '(none)'
                    });
                    
                    // Notify server with preimage as proof
                    if (result.preimage) {
                        this.notifyServerPaymentComplete(result.preimage);
                    } else {
                        this.notifyServerPaymentComplete();
                    }
                    
                    return true;
                }

                console.log('⏳ Invoice not settled yet');
                return false;

            } catch (error) {
                // Don't spam logs during polling
                if (!error.message || (!error.message.includes('not found') && !error.message.includes('not paid'))) {
                    console.error('❌ NWC lookup error:', error);
                }
                return false;
            }
        },

        notifyServerPaymentComplete: function(preimage = '') {
            const requestData = {
                action: 'nwc_mark_paid',
                nonce: nwcPaymentData.nonce,
                order_id: this.orderId,
            };
            
            // Include preimage if provided (cryptographic proof of payment)
            if (preimage) {
                requestData.preimage = preimage;
                console.log('Including preimage as proof of payment');
            }
            
            $.ajax({
                url: nwcPaymentData.ajax_url,
                type: 'POST',
                data: requestData,
                success: (response) => {
                    if (response.success) {
                        console.log('✅ Server confirmed order completion!');
                        this.handlePaymentSuccess();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Failed to notify server:', error);
                    // Still show payment confirmed to user
                    this.handlePaymentSuccess();
                }
            });
        },

        handlePaymentSuccess: function() {
            // Stop checking
            if (this.checkInterval) {
                clearInterval(this.checkInterval);
            }
            
            // Hide invoice
            $('#nwc-invoice-display').fadeOut();
            
            // Show success message
            $('#nwc-payment-complete').fadeIn();
            
            // Redirect to order thank you page after 2 seconds
            setTimeout(() => {
                if (nwcPaymentData.thank_you_url) {
                    console.log('✅ Redirecting to thank you page:', nwcPaymentData.thank_you_url);
                    window.location.href = nwcPaymentData.thank_you_url;
                } else {
                    console.log('Reloading page (no thank you URL)');
                    window.location.reload();
                }
            }, 2000);
        },

        showError: function(message) {
            console.error('NWC Payment Error:', message);
            
            $('#nwc-payment-status').hide();
            $('#nwc-payment-error').html(`
                <h3 style="color: #dc2626; margin: 0 0 10px 0;">⚠️ Payment Error</h3>
                <p style="margin: 0; color: #991b1b;">${message}</p>
                <p style="margin: 15px 0 0 0; font-size: 14px; color: #7f1d1d;">
                    Please contact support or try a different payment method.
                </p>
            `).fadeIn();
        }
    };

    // Make it globally accessible
    window.NWCPayment = NWCPayment;

    // Initialize when document is ready
    $(document).ready(function() {
        NWCPayment.init();
    });

})(jQuery);

