FROM php:8.2-apache

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN apt-get update && apt-get install -y \
    libpng-dev libcurl4-openssl-dev libssl-dev \
    && docker-php-ext-install gd curl \
    && rm -rf /var/lib/apt/lists/*

# Disable conflicting MPM modules at BUILD time
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite headers

# Copy project files
COPY . /var/www/html/

# Move start script to root
RUN cp /var/www/html/start.sh /start.sh && chmod +x /start.sh

# Permissions
RUN mkdir -p /var/www/html/uploads /tmp/sessions \
    && chmod -R 777 /var/www/html/uploads /tmp/sessions \
    && chown -R www-data:www-data /var/www/html

# PHP settings
RUN echo "session.save_path = /tmp/sessions" >> /usr/local/etc/php/php.ini \
    && echo "upload_max_filesize = 10M" >> /usr/local/etc/php/php.ini \
    && echo "post_max_size = 10M" >> /usr/local/etc/php/php.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/php.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/php.ini

EXPOSE 80

CMD ["/start.sh"]
