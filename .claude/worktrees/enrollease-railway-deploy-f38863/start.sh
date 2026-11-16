#!/bin/bash
export PORT=${PORT:-80}
echo "Starting on port $PORT"

# Disable conflicting MPM
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true
a2enmod rewrite 2>/dev/null || true

# Set port
echo "Listen $PORT" > /etc/apache2/ports.conf

# Virtual host with AllowOverride All so .htaccess works
cat > /etc/apache2/sites-available/000-default.conf << APACHEEOF
<VirtualHost *:$PORT>
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>
</VirtualHost>
APACHEEOF

mkdir -p /tmp/sessions && chmod 777 /tmp/sessions

exec apache2-foreground
