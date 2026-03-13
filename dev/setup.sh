#!/usr/bin/env bash
set -euo pipefail

echo "=== WooCommerce ECF DGII Dev Setup ==="

# Wait for WordPress to be ready
until wp core is-installed --allow-root 2>/dev/null; do
    echo "Waiting for WordPress..."
    sleep 2
done

# Install and activate WooCommerce
if ! wp plugin is-active woocommerce --allow-root 2>/dev/null; then
    echo "Installing WooCommerce..."
    wp plugin install woocommerce --activate --allow-root
fi

# Run composer install inside the plugin directory if needed
cd /var/www/html/wp-content/plugins/woo-ecf-dgii
if [ ! -d vendor ]; then
    echo "Running composer install..."
    composer install --no-dev --no-interaction
fi

# Always fix SDK symlink — host composer creates a relative symlink that
# doesn't resolve inside the container. The SDK is mounted at /opt/ecf-dgii-php.
if [ -d vendor/ecfx ]; then
    rm -f vendor/ecfx/ecf-dgii-php
    ln -s /opt/ecf-dgii-php vendor/ecfx/ecf-dgii-php
    echo "SDK symlink set to /opt/ecf-dgii-php"
fi

# Activate our plugin
if ! wp plugin is-active woo-ecf-dgii --allow-root 2>/dev/null; then
    echo "Activating WooCommerce ECF DGII..."
    wp plugin activate woo-ecf-dgii --allow-root
fi

# Set up basic WooCommerce settings for testing
wp option update woocommerce_currency DOP --allow-root
wp option update woocommerce_default_country DO --allow-root

echo "=== Setup complete ==="
echo "WordPress: http://localhost:8080"
echo "Admin: http://localhost:8080/wp-admin (admin/admin)"
