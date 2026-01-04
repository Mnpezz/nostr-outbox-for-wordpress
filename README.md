# ⚡ NOW - Nostr Outbox for WordPress

**Send WordPress and WooCommerce notifications via Nostr instead of email, plus reward users with Bitcoin!**

A comprehensive WordPress plugin that enables **encrypted Nostr Direct Messages**, **Nostr authentication**, **Lightning Network payments**, **NIP-05 verification**, and **Bitcoin Lightning rewards** for WooCommerce stores.

---

## 🎯 What Makes This Plugin Special?

✨ **First WordPress plugin** with full Nostr DM integration  
⚡ **Instant Lightning payments** with auto-verification  
💰 **Zap Rewards** - Reward users with Bitcoin for engagement  
🔐 **Passwordless login** via Nostr browser extensions  
💬 **Replace emails** with encrypted Nostr DMs  
👥 **Multiple Group Chats** - Route different notifications to different teams  
🆔 **NIP-05 verification** built-in (`username@yourdomain.com`)  
🔄 **Profile sync** from Nostr relays  
🤖 **Fully automatic** DM sending via WP-Cron  
🌐 **No external services** required (besides Nostr relays)

**Perfect for:**
- Privacy-focused WooCommerce stores
- Bitcoin/Lightning-only shops
- Nostr-native businesses
- Community-driven sites
- Censorship-resistant communications

---

## ✨ Features

### 🔐 **Nostr Authentication**
- **One-Click Login**: Users log in using their Nostr browser extension (Alby, nos2x)
- **Auto Account Creation**: Automatically creates WordPress accounts for new Nostr users
- **NIP-05 Verification**: Built-in identity verification (`username@yourdomain.com`)
- **Profile Sync**: Automatically sync user profiles (name, avatar, bio) from Nostr relays
- **Secure**: Server-side signature verification with time-limited auth events
- **No Passwords**: Eliminate password fatigue and security risks

### 💬 **Nostr Direct Messages**
- **Replace Email Notifications**: Send DMs instead of emails for orders, updates, etc.
- **Automatic Sending**: PHP-based crypto for fully automatic DM delivery via WP-Cron
- **NIP-04 Encryption**: All messages encrypted end-to-end
- **Multiple Group Chats**: Create separate groups for admins, workers, special teams
- **Smart Message Routing**: Different email types go to appropriate groups
- **Persistent Queue**: Bulletproof queue system with corruption detection & auto-recovery
- **DM Management Interface**: View queue, outbox, inbox, and compose messages
- **0xchat Integration**: Users receive messages in their Nostr DM apps
- **Site Nostr Identity**: Your site has its own nsec/npub for sending DMs
- **Auto Opt-In**: Nostr users automatically receive DMs; traditional users get emails

### 👥 **Multiple Group Chats**
- **Create Unlimited Groups**: Workers, Admins, VIPs, etc.
- **Granular Control**: Each group has its own message type settings
- **Smart Filtering**: Individual confirmations stay private, admin notifications go to groups
- **User-Friendly Management**: Select from existing users or add custom npubs
- **Per-Group Toggle**: Enable/disable different notification types per group
- **Message Type Routing**:
  - 🛒 WooCommerce Orders (New orders)
  - 👤 New User Registrations
  - 🔑 Password Resets
  - ⚙️ Admin Notifications
  - 💬 Comments & Reviews
  - 📋 Gig Notifications (if using gig plugins)

### 💬 **Customer Service Chat Widget**
- **Frontend Widget**: Floating chat button for customer support
- **Real-Time**: Instant message delivery via WebSockets (desktop & mobile)
- **Mobile Ready**:
  - **Android Support**: Seamless integration with **Amber** via `nostr-login`
  - **iOS Support**: Works with **Nostash** (Safari extension)
  - **QR Code Handoff**: "Continue on Mobile" tab lets users scan to chat in their favorite app
- **No Extension Required**: Smart fallback with helpful onboarding links
- **Integrated**: Chat history syncs with Admin DM interface

### 💰 **Zap Rewards**
- **Reward Engagement**: Automatically send Bitcoin Lightning payments to users
- **Comment Rewards**: Pay users for approved comments (configurable amount)
- **Review Rewards**: Incentivize product reviews on WooCommerce
- **Purchase Rewards**: Cashback percentage on purchases
- **Daily Limits**: Prevent abuse with per-user daily reward caps
- **User Dashboard**: Users see their rewards history at `/my-account/zap-rewards/`
- **Lightning Address Field**: Users can set their Lightning address for rewards
- **Admin Management**: View pending, completed, and failed rewards
- **Payment Methods**:
  - Coinos API (recommended - simple and reliable)
  - NWC Protocol (experimental - for future when BIP-340 Schnorr libraries mature)
- **Flexible Addresses**: Supports Lightning addresses, Coinos usernames, and invoices

### ⚡ **Lightning Network Payments**
- **Instant Payments**: Accept Bitcoin via Lightning Network (settles in seconds)
- **Multiple Payment Methods**:
  - Browser wallet (WebLN/nos2x) - One-click instant payment
  - QR code - Scan with any Lightning wallet
- **Auto-Verification**: Payments auto-complete in 3-6 seconds via NWC
- **Accurate Pricing**: Real-time BTC exchange rates from multiple reliable APIs

### 🎯 **Smart Payment Flow**
- **Browser Wallet Priority**: If customer has Alby/nos2x, show instant pay button
- **QR Code Fallback**: Universal payment method for any Lightning wallet
- **Automatic Detection**: Plugin adapts based on merchant's NWC configuration

---

## 🚀 Quick Start (5 Minutes!)

### **Prerequisites**

**Server Requirements:**
1. WordPress 5.8+ with WooCommerce installed
2. PHP 7.4+ with extensions: `openssl`, `gmp`, `json`, `curl`
3. Composer (for automatic DM sending)
4. A [Coinos](https://coinos.io) account (free, instant setup)

**For Testing (Your Users Will Need):**
- **Nostr Extension**: [Alby](https://getalby.com/) or [nos2x](https://github.com/fiatjaf/nos2x) for login
- **Lightning Wallet**: Any Lightning wallet for payments (Zeus, Phoenix, Alby, etc.)
- **Nostr DM App**: [0xchat](https://0xchat.com/), Amethyst, or Damus for receiving DMs

### **Step 1: Install Plugin**
1. Upload plugin folder to `/wp-content/plugins/nostr-outbox-wordpress/`
2. Activate in WordPress admin → Plugins

### **Step 2: Install Crypto Dependencies (For Automatic DMs)**

For automatic Nostr DM sending via WP-Cron:

```bash
# Install PHP GMP extension (if not already installed)
sudo apt-get install php-gmp

# Navigate to plugin directory
cd /path/to/wp-content/plugins/nostr-outbox-wordpress

# Run installation script
bash INSTALL-CRYPTO.sh

# Or install manually via Composer
composer require simplito/elliptic-php textalk/websocket
```

✅ **Manual DMs work without this, but automatic sending requires crypto libraries.**

### **Step 3: Configure Lightning Address**
1. Sign up at [coinos.io](https://coinos.io) (takes 30 seconds)
2. Your Lightning address is: `username@coinos.io`
3. Go to **Settings → NOW - Nostr Outbox → NWC Settings**
4. Paste your Lightning address → **Save Settings**

✅ **You're now accepting Lightning payments!**

### **Step 4: Enable Auto-Verification (Recommended)**

For QR code payments to auto-complete:

1. Go to [coinos.io](https://coinos.io) → **Settings → Plugins → NWC**
2. Create new connection with permission: `lookup_invoice`
3. Copy the connection string (starts with `nostr+walletconnect://`)
4. Paste in **NWC Connection (For Auto-Verification)** field → **Save**

🎉 **Done! QR payments now auto-complete in 3-6 seconds!**

### **Step 5: Configure Zap Rewards (Optional)**

Reward your users with Bitcoin for engagement!

1. Go to **Settings → NOW - Nostr Outbox → 💰 Zap Rewards → Settings**
2. Add your **Coinos API Token** (get it from [coinos.io/docs](https://coinos.io/docs))
3. Enable reward types (comments, reviews, purchases)
4. Set reward amounts and daily limits
5. **Save Settings**

Users will receive Lightning payments automatically when they engage!

### **Step 6: Configure Group Chats (Optional)**

Route notifications to different teams:

1. Go to **Settings → NOW - Nostr Outbox → 💬 DM Management → Group Chats**
2. Create groups (e.g., "Admins", "Workers", "VIPs")
3. Add members (select existing users or paste custom npubs)
4. Enable message types for each group
5. **Save**

Now different notifications automatically go to the right teams!

### **Step 7: Configure Nostr Relays & DMs**

1. Go to **Settings → NOW - Nostr Outbox → Relays**
2. Add your preferred Nostr relays (or use defaults)
3. Choose redirect page after login
4. Go to **💬 DM Management** tab to view your site's Nostr identity

**User DM Preferences:**
- Users who log in with Nostr **automatically** get DMs enabled
- Traditional email users continue receiving emails
- Users can toggle DM preference in their account settings
- Admin can manually compose DMs to any user from DM Management

🎉 **All done! Your site now has full Nostr integration + Bitcoin rewards!**

---

## 💡 How It Works

### **Nostr Direct Messages**

**Email → Nostr DM Replacement:**

```
WooCommerce Order
    ↓
Instead of Email → Nostr DM (encrypted)
    ↓
User receives in 0xchat/Damus/Amethyst
    ↓
Private, encrypted, censorship-resistant! 💬
```

**Automatic Sending:**
1. User places order or triggers notification
2. Plugin intercepts email
3. Checks if user has Nostr pubkey → converts to encrypted DM
4. Checks if message should go to group chats → queues for groups
5. Adds to queue
6. WP-Cron processes queue every 5 minutes
7. PHP crypto signs and encrypts
8. Published to relays via WebSocket
9. Users receive in Nostr DM app ✅

**Group Chat Routing:**
- Individual confirmations ("Your order...", "Reminder: You have...") → Stay private
- Admin notifications ("New order #123", "Gig claimed") → Sent to configured groups
- Smart filtering prevents private messages from going to groups

### **Zap Rewards Flow**

```
User Action (comment/review/purchase)
    ↓
Plugin checks: Is reward enabled?
    ↓
✅ Yes → Check daily limit
    ↓
Not exceeded → Create reward record
    ↓
Resolve Lightning address to invoice
    ↓
Send payment via Coinos API
    ↓
Payment successful → Mark as completed
    ↓
User receives sats! ⚡
```

### **For Customers**

#### **Browser Wallet Payment** (Recommended)
1. Click "⚡ Pay with Browser Wallet" at checkout
2. Alby/nos2x prompts for approval
3. Confirm payment
4. Order completes automatically ✅

#### **QR Code Payment** (Universal)
1. Scan QR code with any Lightning wallet (Zeus, Phoenix, Wallet of Satoshi, etc.)
2. Confirm payment in your wallet
3. Plugin detects payment via NWC
4. Order completes automatically ✅ (if NWC configured)

#### **Earning Zap Rewards**
1. Set Lightning address in **My Account → Zap Rewards**
2. Leave comments, reviews, or make purchases
3. Receive automatic Bitcoin payments! ⚡
4. View reward history on rewards page

### **For Store Owners**

```
Order Created → Invoice Generated (via Lightning Address)
                        ↓
        ┌───────────────┴───────────────┐
        │                               │
 Browser Wallet                    QR Code
 (WebLN instant)               (Universal payment)
        │                               │
        ├─ Preimage returned            ├─ NWC checks wallet
        ├─ Auto-complete ✅             │   every 3 seconds
        │                               ├─ Payment detected
        │                               ├─ Auto-complete ✅
        └───────────────┬───────────────┘
                        │
                Order Complete! 🎉
                        │
        Check if rewards enabled for user
                        │
        ✅ Yes → Send Zap Reward ⚡
```

---

## ⚙️ Configuration

### **General Settings** (Settings → NOW - Nostr Outbox → General)

| Setting | Description | Default |
|---------|-------------|---------|
| **Enable Nostr Login** | Allow users to log in with Nostr | ✅ Enabled |
| **Auto-create Accounts** | Create WordPress accounts for new Nostr users | ✅ Enabled |
| **Default User Role** | Role for new Nostr accounts | Customer |

### **NWC Payment Settings** (Settings → NOW - Nostr Outbox → NWC Settings)

| Setting | Description | Required |
|---------|-------------|----------|
| **Enable Payment Gateway** | Enable Lightning as payment method | ✅ Yes |
| **Lightning Address** | Your receiving address (`username@coinos.io`) | ✅ Yes |
| **NWC Connection** | For QR auto-verification (Coinos NWC string) | ⚠️ Optional* |

\* Without NWC: QR payments require manual "Mark as Paid" button.  
With NWC: QR payments auto-complete in seconds! 🚀

### **Zap Rewards Settings** (Settings → NOW - Nostr Outbox → 💰 Zap Rewards)

| Setting | Description | Default |
|---------|-------------|---------|
| **Coinos API Token** | API token from coinos.io/settings | None |
| **Enable Comments** | Reward approved comments | ❌ Disabled |
| **Comment Amount** | Sats per comment | 100 |
| **Enable Reviews** | Reward product reviews | ❌ Disabled |
| **Review Amount** | Sats per review | 500 |
| **Enable Purchases** | Cashback on purchases | ❌ Disabled |
| **Purchase Percentage** | Cashback % of order total | 1% |
| **Daily Limit** | Max rewards per user per day | 5 |

💡 **Tip:** 100 sats ≈ $0.10 USD (varies with Bitcoin price)

### **Group Chat Settings** (Settings → NOW - Nostr Outbox → 💬 DM Management → Group Chats)

**Per-Group Configuration:**
- **Group Name**: Descriptive name (e.g., "Admins", "Workers")
- **Enabled**: Toggle group on/off
- **Members**: Select existing users or add custom npubs
- **Message Types**: Choose which notifications this group receives
  - 🛒 WooCommerce Orders
  - 👤 New User Registrations
  - 🔑 Password Resets
  - ⚙️ Admin Notifications
  - 💬 Comments & Reviews
  - 📋 Gig Notifications

**Smart Filtering:**
- Individual messages (confirmations, reminders) automatically stay private
- Only admin-level notifications go to groups
- Prevents sensitive customer info from going to wrong people

### **Relay Settings** (Settings → NOW - Nostr Outbox → Relays)

| Setting | Description | Default |
|---------|-------------|---------|
| **Nostr Relays** | WebSocket URLs for Nostr relays | 4 popular relays |
| **Redirect After Login** | Where to send users after Nostr login | My Account |

**Default Relays:**
- `wss://relay.damus.io` - Damus relay (popular)
- `wss://relay.snort.social` - Snort relay
- `wss://nos.lol` - Fast, reliable
- `wss://relay.nostr.band` - Nostr.band

### **DM Management** (Settings → NOW - Nostr Outbox → 💬 DM Management)

**Sub-tabs:**
- **🔑 Site Keys**: View your site's nsec/npub for sending DMs
- **📋 Queue**: View pending DMs waiting to be sent
- **📤 Outbox**: View sent DM history (newest first)
- **📥 Inbox**: View incoming DMs (coming soon)
- **✍️ Compose**: Manually compose and send DMs to users or groups
- **👥 Group Chats**: Manage multiple group chats

**Compose Features:**
- Send to individual users (by username or npub)
- Send to specific groups
- Dropdown shows all active groups with member counts
- Messages queued and sent automatically via WP-Cron

### **Advanced Tools**

**Clear BTC Price Cache** (NWC Settings page):
- Plugin caches Bitcoin prices for 5 minutes (performance)
- If pricing seems wrong, clear cache to fetch fresh rates
- Automatically tries 3 reliable APIs:
  1. CoinGecko (primary)
  2. Coinbase (backup)
  3. Blockchain.info (backup)

**NIP-05 Verification**:
- Automatically enabled at `/.well-known/nostr.json`
- Users get identity: `username@yourdomain.com`
- Works with any Nostr client for verification

---

## 🔧 Requirements

### **Server Requirements:**
- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher (8.0+ recommended)
- **PHP Extensions**: `openssl`, `gmp`, `json`, `curl`
- **WooCommerce**: 6.0 or higher
- **Composer**: For automatic DM sending (optional)

### **External Services:**
- **Lightning Wallet**: Coinos (recommended) or any NWC-compatible wallet
- **Nostr Relays**: Default relays provided, or configure your own

### **For Customers:**
- **Browser Extension** (for login & browser wallet payments): Alby or nos2x
- **Nostr DM App** (for receiving messages): 0xchat, Amethyst, Damus, etc.
- **Lightning Wallet** (for receiving rewards): Any wallet with Lightning address

---

## 🛠️ Troubleshooting

### **Zap Rewards Issues**

#### **"Rewards page not found" (404 error)**
1. Go to **Plugins** → Deactivate "Nostr Outbox WordPress"
2. Reactivate it
3. Or go to **Settings → Permalinks** and click "Save Changes"
4. This flushes rewrite rules and registers the `/my-account/zap-rewards/` endpoint

#### **Reward stuck in "Processing" or "Failed"**
1. Go to **Zap Rewards → Pending Rewards**
2. Click **"Retry"** button
3. Check that Coinos API token is configured correctly
4. Verify user's Lightning address is valid
5. Check debug.log for specific error messages

#### **Payment failed: "No preimage in response"**
- This means the payment method returned success but we're looking for wrong fields
- Check that you're using **Coinos API** (not NWC)
- NWC has BIP-340 Schnorr signature issues in PHP (experimental)

### **Group Chat Issues**

#### **Individual messages going to groups**
- The plugin filters messages starting with "Your...", "confirmation", "reminder"
- If a message still goes to groups incorrectly, check the subject line
- You can adjust filtering in `class-nostr-notifications.php`

#### **Group not receiving messages**
1. Check group is **Enabled**
2. Verify **Message Types** are enabled for that group
3. Ensure group has valid **Members** (npubs or hex pubkeys)
4. Check **Queue** tab to see if messages are queued
5. Use **"Process Queue Now"** button to test immediately

#### **Custom npubs not working**
- Ensure npubs start with `npub1`
- Plugin auto-converts npubs to hex format
- Check debug.log for conversion errors

### **"Lightning (NWC)" not showing in checkout**
1. Check: Settings → NOW - Nostr Outbox → NWC Settings
2. Ensure "Enable Payment Gateway" is checked
3. Verify Lightning Address is filled in
4. Go to: WooCommerce → Settings → Payments
5. Enable "Lightning (NWC)"

### **QR payments not auto-completing**
1. Check: Is NWC Connection configured?
2. Go to: Settings → NOW - Nostr Outbox → NWC Settings
3. Look for: "✓ NWC Auto-Verification Enabled!" message
4. If not: Follow Step 4 in Quick Start above

### **Wrong satoshi amounts**
1. Go to: Settings → NOW - Nostr Outbox → NWC Settings
2. Scroll to: "🔧 Advanced Tools"
3. Click: "🔄 Clear BTC Price Cache"
4. Next order will fetch fresh exchange rates

### **Browser wallet not detected**
1. Install [Alby](https://getalby.com/) or [nos2x](https://github.com/fiatjaf/nos2x)
2. Ensure extension is enabled in browser
3. Refresh the payment page
4. Check browser console for errors

### **Login button not appearing**
1. Ensure "Enable Nostr Login" is checked (Settings → General)
2. Clear WordPress cache
3. Check that WooCommerce is active

### **DMs not sending automatically**

**⚠️ Important: Localhost vs. Production**

On **localhost**, WP-Cron only fires when you visit your site. This means:
- DMs queue correctly ✅
- But they won't send automatically until you visit a page ⏰
- Use the **"⚡ Process Queue Now"** button for instant testing

On **live production sites**, WP-Cron fires automatically with regular customer traffic. DMs will send within 5 minutes without any manual intervention! 🎉

**Troubleshooting Steps:**
1. Check if crypto libraries installed: `composer show | grep -E "(simplito|textalk)"`
2. Verify PHP extensions: `php -m | grep -E "(openssl|gmp)"`
3. Test manual sending first (DM Management → Compose → Process Queue Now)
4. Check WP-Cron is running: `wp cron event list`
5. Trigger manually: `wp cron event run nostr_process_dm_queue`
6. Check debug.log for errors: `tail -f wp-content/debug.log | grep "Nostr DM Cron"`
7. For production sites with low traffic, optionally set up system cron (see below)

### **"Composer dependencies not found" warning**
1. Navigate to plugin directory
2. Run: `bash INSTALL-CRYPTO.sh`
3. Or manually: `composer require simplito/elliptic-php textalk/websocket`
4. Verify `vendor/` directory exists

### **Relay connection issues**
1. Check relay URLs start with `wss://` or `ws://`
2. Test relay in browser: Try connecting via nostr.watch
3. Firewall may block WebSocket connections
4. Try default relays first (built-in)

### **NIP-05 verification not working**
1. Check `/.well-known/nostr.json` is accessible
2. Verify file returns valid JSON
3. Test with nostr.directory or other NIP-05 validators
4. Ensure pretty permalinks are enabled in WordPress

### **Setting up System Cron (Optional - For Guaranteed Delivery)**

For **maximum reliability** on production sites (especially low-traffic sites), set up a real system cron:

```bash
# Edit crontab
crontab -e

# Add this line (runs every 5 minutes)
*/5 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1

# Or using curl
*/5 * * * * curl -s https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1

# Or using WP-CLI (most reliable)
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

This ensures DMs send every 5 minutes **even during 3 AM with zero traffic**. For most WooCommerce sites with regular traffic, the built-in WP-Cron is sufficient! ✅

---

## 📁 File Structure

```
nostr-outbox-wordpress/
├── nostr-outbox-wordpress.php           # Main plugin file
├── composer.json                        # Composer dependencies
├── composer.lock                        # Locked dependency versions
├── INSTALL-CRYPTO.sh                    # Auto-install script for crypto libs
├── README.md                            # This file
├── includes/
│   ├── class-nostr-auth.php             # Nostr authentication
│   ├── class-admin-settings.php         # Admin settings page (with Zap Rewards tab)
│   ├── class-user-profile.php           # User profile management
│   ├── class-nip05-verification.php     # NIP-05 identity verification
│   ├── class-nostr-notifications.php    # Email → DM replacement (+ group filtering)
│   ├── class-nostr-profile-sync.php     # Profile sync from relays
│   ├── class-nostr-connect.php          # Connect existing users to Nostr
│   ├── class-dm-admin.php               # DM management interface (+ group chats)
│   ├── class-nostr-crypto-php.php       # PHP crypto for automatic DMs
│   ├── class-nwc-wallet.php             # NWC connection parsing
│   ├── class-nwc-client.php             # NWC protocol client (experimental)
│   ├── class-lnurl-service.php          # LNURL invoice generation
│   ├── class-zap-rewards.php            # Zap Rewards main class
│   ├── class-zap-rewards-admin.php      # Zap Rewards admin interface
│   ├── class-zap-rewards-processor.php  # Zap Rewards payment processor
│   └── woocommerce/
│       ├── class-wc-gateway-nwc.php     # Payment gateway
│       └── class-wc-gateway-nwc-blocks-support.php # Blocks support
├── templates/
│   └── rewards-page.php                 # User-facing rewards page template
├── assets/
│   ├── js/
│   │   ├── frontend.js                  # Nostr login
│   │   ├── nwc-payment.js               # Payment UI & NWC verification
│   │   ├── nostr-profile-sync.js        # Profile sync client
│   │   ├── nostr-dm-sender.js           # DM sending (browser)
│   │   ├── zap-rewards.js               # Zap Rewards frontend
│   │   └── checkout.js                  # WooCommerce checkout
│   └── css/
│       ├── frontend.css                 # Frontend styles
│       ├── admin.css                    # Admin styles
│       └── zap-rewards.css              # Zap Rewards styles
├── vendor/                              # Composer dependencies
│   ├── simplito/elliptic-php/           # Elliptic curve crypto
│   ├── textalk/websocket/               # WebSocket client
│   └── autoload.php                     # Composer autoloader
└── Documentation/
    ├── FEATURES.md                      # Complete feature list
    ├── DM-TESTING-GUIDE.md              # DM testing procedures
    ├── AUTOMATIC-DM-IMPLEMENTATION.md   # Technical DM implementation
    ├── PURE-PHP-NOSTR-GUIDE.md          # PHP crypto guide
    ├── NOSTR-FAQ.md                     # FAQ and troubleshooting
    ├── STATUS-REPORT.md                 # Current status
    └── READY-TO-INSTALL.md              # Installation guide
```

---

## 🔄 Changelog

### **Version 1.3.0** (Current) - December 2025
**Major Features Added:**
- ✅ **Zap Rewards System**:
  - Automatic Bitcoin Lightning payments for user engagement
  - Comment rewards (configurable sats per approved comment)
  - Review rewards (incentivize WooCommerce product reviews)
  - Purchase rewards (cashback percentage on orders)
  - Daily per-user reward limits (prevent abuse)
  - User dashboard at `/my-account/zap-rewards/`
  - Lightning address management for users
  - Admin pending/completed/failed rewards interface
  - Retry failed payments with one click
  - Coinos API integration (primary payment method)
  - Lightning address resolution (supports addresses, usernames, invoices)
  - Real-time payment status tracking
  - Automatic invoice generation via LNURL
  
- ✅ **Multiple Group Chats**:
  - Create unlimited groups (Admins, Workers, VIPs, etc.)
  - Per-group message type configuration
  - Smart message filtering (keeps individual messages private)
  - User-friendly member selection (existing users + custom npubs)
  - Individual group compose options
  - Granular control over notification routing
  
- ✅ **Enhanced Compose Tab**:
  - Send to individual users or specific groups
  - Dropdown shows all active groups with member counts
  - Direct npub recipient support
  - Automatic npub-to-hex conversion
  - Better user experience

- ✅ **Improved Message Filtering**:
  - Smart detection of individual vs. admin messages
  - Filters confirmations, reminders, "Your..." messages
  - Prevents sensitive customer data from going to groups
  - Ensures proper notification routing

**Bug Fixes & Improvements:**
- ✅ Fixed outbox message order (newest first)
- ✅ Improved group chat member display (shows usernames/npubs)
- ✅ Better npub conversion error handling
- ✅ Enhanced payment error messages
- ✅ Fixed reward status updates
- ✅ Improved Coinos API response parsing
- ✅ Better Lightning address validation

### **Version 1.2.0** - December 2025
**Major Features Added:**
- ✅ **Nostr Direct Messaging System**:
  - NIP-04 encrypted DMs
  - Automatic sending via WP-Cron (PHP crypto)
  - Replace email notifications with Nostr DMs
  - DM management interface (Queue, Outbox, Inbox, Compose)
  - Site Nostr identity (nsec/npub)
  - 0xchat integration
  - **Bulletproof queue** with corruption detection & auto-recovery
  - Persistent queue storage (WordPress options, not transients)
  - Individual queue item deletion
  - Auto opt-in for Nostr users
  
- ✅ **NIP-05 Identity Verification**:
  - `/.well-known/nostr.json` endpoint
  - `username@yourdomain.com` identities
  - Automatic verification for all users
  
- ✅ **Profile Sync from Relays**:
  - Fetch user profile data (name, avatar, bio)
  - Kind 0 metadata events
  - Manual sync button
  
- ✅ **Relay Configuration**:
  - Configure custom Nostr relays
  - Post-login redirect options
  - Relay management UI
  
- ✅ **PHP Crypto Implementation**:
  - `simplito/elliptic-php` for signing
  - `textalk/websocket` for relay publishing
  - Schnorr-compatible ECDSA signatures
  - ECDH shared secret generation
  - NIP-04 encryption/decryption
  
- ✅ **Enhanced Admin Interface**:
  - Integrated DM management
  - Dashboard DM queue widget
  - Admin bar queue indicator
  - Comprehensive documentation
  
**Bug Fixes & Improvements:**
- ✅ Fixed `npub` display to match browser extension keys
- ✅ Client-side `npub` encoding using nostr-tools for accuracy
- ✅ NIP-05 email format for new users (`username@yourdomain.com`)
- ✅ Automatic email updates when profile synced
- ✅ Queue corruption detection and auto-recovery
- ✅ Persistent queue storage (survives cache clears & logouts)
- ✅ WooCommerce billing email sync with profile updates

### **Version 1.0.0** - Initial Release
- ✅ Nostr authentication (NIP-42 style)
- ✅ Lightning Address invoice generation (LNURL protocol)
- ✅ NWC auto-verification via Alby SDK `lookup_invoice`
- ✅ Browser wallet payments (WebLN)
- ✅ QR code payments with auto-complete
- ✅ Multi-API BTC price fetching (CoinGecko, Coinbase, Blockchain.info)
- ✅ Smart payment display (adapts to NWC configuration)
- ✅ WooCommerce Blocks support
- ✅ Admin tools (BTC cache clearing)
- ✅ nostr-tools v1.17.0 + Alby SDK v3.6.1 compatibility

---

## 💻 Technical Details

### **Payment Verification Architecture**

**Frontend (JavaScript)**:
- Uses **Alby SDK v3.6.1** with **nostr-tools v1.17.0**
- NWC `lookup_invoice` method checks payment status
- Polls merchant's Coinos wallet every 3 seconds
- Auto-completes order on successful payment detection

**Backend (PHP)**:
- LNURL protocol for invoice generation from Lightning Address
- Simple order status checks (verification happens on frontend)
- WooCommerce HPOS compatible

### **Zap Rewards Architecture**

**Payment Processing:**
- **Primary**: Coinos REST API (simple, reliable, fully functional)
- **Experimental**: NWC Protocol (requires BIP-340 Schnorr - not yet available in PHP)
- Lightning address resolution via LNURL
- Automatic invoice generation
- Payment status tracking in custom database table
- Retry mechanism for failed payments

**Database Schema:**
```sql
wp_zap_rewards
├── id (bigint)
├── user_id (bigint)
├── zap_address (varchar)
├── reward_type (varchar) - 'comment', 'review', 'purchase'
├── amount (bigint) - satoshis
├── status (varchar) - 'pending', 'completed', 'failed'
├── block_hash (varchar) - transaction ID or preimage
├── error_message (text)
├── comment_id (bigint)
├── order_id (bigint)
└── created_at (datetime)
```

**User Meta:**
- `zap_address` - User's Lightning address for receiving rewards

### **Nostr DM Architecture**

**Frontend (JavaScript)**:
- Uses **nostr-tools v1.17.0** for manual DM sending
- NIP-04 encryption in browser
- WebSocket connections to relays
- SimplePool for relay management

**Backend (PHP)**:
- Uses **simplito/elliptic-php v1.0.12** for cryptographic operations
- Uses **textalk/websocket v1.6.3** for relay communication
- WP-Cron scheduled every 5 minutes for automatic sending
- NIP-04 encryption/decryption in pure PHP
- Schnorr-compatible ECDSA signatures
- ECDH shared secret generation

**DM Flow:**
```
1. WooCommerce Event (order, notification, etc.)
   ↓
2. Email intercepted by plugin
   ↓
3. Check if user has Nostr pubkey → Convert to Nostr DM (NIP-04)
   ↓
4. Check if message should go to group chats → Queue for groups
   ↓
5. Added to DM queue
   ↓
6. WP-Cron runs every 5 minutes
   ↓
7. PHP crypto signs & encrypts event
   ↓
8. Published to configured relays via WebSocket
   ↓
9. Users receive in 0xchat/Damus/etc. ✅
```

### **Group Chat Routing Logic:**

```
Email Triggered
   ↓
Check subject line
   ↓
Is it individual? (starts with "Your", "confirmation", "reminder", etc.)
   ↓
✅ Yes → Send ONLY to individual recipient
   │
   ↓
❌ No → Check each group's settings
   │
   ├─ Group A: WooCommerce Orders enabled? → ✅ Queue for Group A
   ├─ Group B: Admin Notifications enabled? → ✅ Queue for Group B
   └─ Group C: Comments enabled? → ❌ Skip Group C
```

### **Supported Nostr NIPs**
- **NIP-01**: Basic protocol (events, tags, signatures)
- **NIP-04**: Encrypted Direct Messages
- **NIP-05**: Identity verification (username@domain)
- **NIP-19**: bech32 encoding (npub, nsec)
- **NIP-42**: Authentication events (for login)

### **Supported NWC Methods**
- `lookup_invoice` - Check invoice payment status (used for auto-verification)
- `pay_invoice` - Send Lightning payment (experimental, requires BIP-340 Schnorr)

### **Browser Compatibility**
- Tested with: Chrome, Firefox, Brave
- Requires: JavaScript enabled, Nostr extension (Alby/nos2x)

---

## 📚 Documentation

Comprehensive guides included with the plugin:

- **`READY-TO-INSTALL.md`** - Installation guide for automatic DMs
- **`FEATURES.md`** - Complete feature list with examples
- **`DM-TESTING-GUIDE.md`** - Step-by-step DM testing procedures
- **`AUTOMATIC-DM-IMPLEMENTATION.md`** - Technical implementation details
- **`PURE-PHP-NOSTR-GUIDE.md`** - PHP crypto implementation guide
- **`NOSTR-FAQ.md`** - Frequently asked questions
- **`STATUS-REPORT.md`** - Current development status

---

## 🤝 Support & Contributing

For issues or questions:
1. Check this README and other documentation files
2. Review WordPress error logs (`wp-content/debug.log`)
3. Check browser console for JavaScript errors
4. Test with default relays first
5. Verify Coinos account is active and funded
6. Check WP-Cron is running: `wp cron event list`

**Useful Links**:
- [Nostr Protocol](https://github.com/nostr-protocol/nostr)
- [Nostr NIPs](https://github.com/nostr-protocol/nips) - Protocol specifications
- [NWC Documentation](https://docs.nwc.dev/)
- [Coinos](https://coinos.io/) - Lightning wallet
- [Alby SDK](https://github.com/getAlby/js-sdk)
- [0xchat](https://github.com/0xchat-app) - Nostr DM app
- [nostr-tools](https://github.com/nbd-wtf/nostr-tools) - JavaScript library
- [elliptic-php](https://github.com/simplito/elliptic-php) - PHP crypto library

---

## 📜 License

GPL v2 or later

---

## 🙏 Credits

Built with:
- [nostr-tools v1.17.0](https://github.com/nbd-wtf/nostr-tools) - Nostr protocol implementation (JavaScript)
- [Alby SDK v3.6.1](https://github.com/getAlby/js-sdk) - NWC client for payment verification
- [simplito/elliptic-php v1.0.12](https://github.com/simplito/elliptic-php) - Elliptic curve cryptography (PHP)
- [textalk/websocket v1.6.3](https://github.com/Textalk/websocket-php) - WebSocket client (PHP)
- [Nostr Wallet Connect](https://nwc.dev/) - Payment protocol specification
- [LNURL](https://github.com/lnurl/luds) - Lightning Address invoice generation
- [0xchat](https://github.com/0xchat-app) - Inspiration for DM interface
- [Coinos](https://coinos.io/) - Lightning wallet and API

**Influenced by:**
- [YEGHRO_NostrLogin](https://github.com/Yeghro/YEGHRO_NostrLogin) - Nostr WordPress auth
- [nostr-verify](https://github.com/easydns/wp-nostr-nip05) - NIP-05 implementation
- [nostrtium](https://github.com/pjv/nostrtium) - Nostr publishing from WordPress

Special thanks to the Coinos, Nostr, and Lightning Network communities! ⚡🤙

---

**Made with ⚡ and 🧡 for the Bitcoin Lightning Network**
