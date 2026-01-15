FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    cron \
    curl \
    wget \
    git \
    zip \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libxml2-dev \
    openssl-dev \
    oniguruma-dev \
    ffmpeg \
    nodejs \
    npm \
    python3 \
    py3-pip

# Install Python dependencies for monitoring
RUN pip3 install requests python-telegram-bot

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_mysql \
        mysqli \
        zip \
        curl \
        mbstring \
        xml \
        sockets \
        opcache \
        pcntl

# Configure PHP
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=60'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'upload_max_filesize=100M'; \
    echo 'post_max_size=100M'; \
    echo 'max_execution_time=300'; \
    echo 'max_input_time=300'; \
    echo 'memory_limit=256M'; \
} > /usr/local/etc/php/conf.d/iptv.ini

# Configure Nginx
RUN mkdir -p /var/log/nginx /var/log/supervisor /run/nginx /var/log/streams \
    && rm /etc/nginx/nginx.conf

# Copy configuration files
COPY nginx.conf /etc/nginx/nginx.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy application
COPY . /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html /var/log/streams \
    && chmod -R 755 /var/www/html \
    && chmod 644 /etc/nginx/nginx.conf \
    && chmod +x /var/www/html/cron.sh

# Setup cron for auto tasks
RUN echo "* * * * * /usr/local/bin/php /var/www/html/cron.php >> /var/log/cron.log 2>&1" > /etc/crontabs/www-data \
    && echo "*/5 * * * * /usr/local/bin/php /var/www/html/auto.php check >> /var/log/auto.log 2>&1" >> /etc/crontabs/www-data \
    && echo "0 */2 * * * /usr/local/bin/php /var/www/html/auto.php cleanup >> /var/log/cleanup.log 2>&1" >> /etc/crontabs/www-data

# Health check endpoint
RUN echo "<?php header('Content-Type: application/json'); echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]); ?>" > /var/www/html/health.php

EXPOSE 80 8000-8050

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
