#!/bin/bash
# Installation script for Nostr Login & Pay PHP crypto dependencies
# Run this script from the plugin directory

echo "========================================="
echo "Nostr Login & Pay - Crypto Dependencies"
echo "========================================="
echo ""

# Check if we're in the right directory
if [ ! -f "nostr-login-and-pay.php" ]; then
    echo "❌ Error: Please run this script from the plugin directory"
    echo "   cd /path/to/wp-content/plugins/nostr-login-and-pay"
    echo "   bash INSTALL-CRYPTO.sh"
    exit 1
fi

echo "✓ Found plugin directory"
echo ""

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "📌 PHP Version: $PHP_VERSION"

# Check for required extensions
echo ""
echo "Checking PHP extensions..."

check_extension() {
    if php -m | grep -q "$1"; then
        echo "✓ $1 is installed"
        return 0
    else
        echo "❌ $1 is NOT installed"
        return 1
    fi
}

MISSING_EXTENSIONS=0

check_extension "openssl" || MISSING_EXTENSIONS=$((MISSING_EXTENSIONS + 1))
check_extension "gmp" || MISSING_EXTENSIONS=$((MISSING_EXTENSIONS + 1))
check_extension "json" || MISSING_EXTENSIONS=$((MISSING_EXTENSIONS + 1))
check_extension "curl" || MISSING_EXTENSIONS=$((MISSING_EXTENSIONS + 1))

if [ $MISSING_EXTENSIONS -gt 0 ]; then
    echo ""
    echo "❌ Missing $MISSING_EXTENSIONS required PHP extension(s)"
    echo ""
    echo "To install missing extensions:"
    echo "  sudo apt-get install php-gmp php-curl php-json"
    echo "  # Then restart PHP:"
    echo "  sudo systemctl restart php*-fpm"
    echo "  # Or restart Local by Flywheel"
    echo ""
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Check for Composer
echo ""
echo "Checking for Composer..."
if ! command -v composer &> /dev/null; then
    echo "❌ Composer is not installed"
    echo ""
    echo "Install Composer:"
    echo "  curl -sS https://getcomposer.org/installer | php"
    echo "  sudo mv composer.phar /usr/local/bin/composer"
    echo ""
    echo "Or download from: https://getcomposer.org/download/"
    exit 1
fi

COMPOSER_VERSION=$(composer --version)
echo "✓ Composer is installed: $COMPOSER_VERSION"

# Initialize composer.json if it doesn't exist
if [ ! -f "composer.json" ]; then
    echo ""
    echo "📝 Initializing composer.json..."
    composer init \
        --name="nostr/nostr-login-and-pay" \
        --description="WordPress plugin for Nostr login and Lightning payments" \
        --type="wordpress-plugin" \
        --license="GPL-2.0-or-later" \
        --no-interaction
fi

# Install dependencies
echo ""
echo "📦 Installing PHP crypto libraries..."
echo "   This may take a minute..."
echo ""

composer require simplito/elliptic-php --no-interaction
if [ $? -ne 0 ]; then
    echo "❌ Failed to install simplito/elliptic-php"
    exit 1
fi

composer require textalk/websocket --no-interaction
if [ $? -ne 0 ]; then
    echo "❌ Failed to install textalk/websocket"
    exit 1
fi

# Optimize autoloader
echo ""
echo "🔧 Optimizing autoloader..."
composer dump-autoload --optimize

echo ""
echo "========================================="
echo "✅ Installation Complete!"
echo "========================================="
echo ""
echo "Installed packages:"
composer show 2>/dev/null | grep -E "(simplito|textalk)"
echo ""
echo "Next steps:"
echo "1. Test automatic DM sending:"
echo "   wp cron event run nostr_process_dm_queue"
echo ""
echo "2. Check WordPress admin for any warnings"
echo ""
echo "3. Try sending a test DM from:"
echo "   Settings → Nostr Login & Pay → DM Management → Compose"
echo ""
echo "📚 For more info, see:"
echo "   - AUTOMATIC-DM-IMPLEMENTATION.md"
echo "   - PURE-PHP-NOSTR-GUIDE.md"
echo ""

