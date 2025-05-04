FROM php:8.3-fpm-alpine

# Устанавливаем зависимости
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl

# Устанавливаем расширения PHP
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip

# Устанавливаем composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Создаем директорию для логов PHP
RUN mkdir -p /var/log/php && \
    chown -R www-data:www-data /var/log/php

# Настройка PHP для разработки
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www

CMD ["php-fpm"] 