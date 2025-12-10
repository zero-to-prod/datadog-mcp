#!/bin/sh
set -e

# Ensure required directories exist with proper permissions
mkdir -p /var/www/html/storage/mcp-sessions
mkdir -p /var/www/html/storage/cache
mkdir -p /var/log/nginx
mkdir -p /var/lib/nginx/tmp
mkdir -p /var/log/supervisor
mkdir -p /run/php

# Set ownership for application directories
chown -R appuser:appgroup /var/www/html/storage
chown -R appuser:appgroup /var/log/nginx
chown -R appuser:appgroup /var/lib/nginx

# Set proper permissions
chmod -R 755 /var/www/html/storage
chmod 775 /var/www/html/storage/mcp-sessions

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf