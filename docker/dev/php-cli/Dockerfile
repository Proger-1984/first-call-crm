FROM php:8.3-zts-alpine

# Устанавливаем timezone
ENV TZ=Europe/Moscow
RUN apk add --no-cache tzdata && \
    cp /usr/share/zoneinfo/Europe/Moscow /etc/localtime && \
    echo "Europe/Moscow" > /etc/timezone

# Устанавливаем зависимости
RUN apk add --no-cache \
    postgresql-dev \
    postgresql-client \
    mysql-client \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    autoconf \
    gcc \
    g++ \
    make \
    supervisor \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev

# Конфигурируем и устанавливаем GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Устанавливаем расширения PHP (PostgreSQL + MySQL + GD)
RUN docker-php-ext-install pdo pdo_pgsql pgsql pdo_mysql zip gd

# Устанавливаем расширение parallel
RUN pecl install parallel && docker-php-ext-enable parallel

# Устанавливаем composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Создаем директории для логов
RUN mkdir -p /var/log/php /var/log/supervisor && \
    chown -R www-data:www-data /var/log/php /var/log/supervisor

# Копируем конфигурацию
COPY php.ini /usr/local/etc/php/conf.d/custom.ini
COPY supervisord.conf /etc/supervisord.conf
COPY supervisor.d/ /etc/supervisor.d/
COPY crontab /etc/cron.d/app-crontab
COPY entrypoint.sh /entrypoint.sh

# Устанавливаем права на crontab
RUN chmod 0644 /etc/cron.d/app-crontab

# Устанавливаем права
RUN chmod +x /entrypoint.sh

WORKDIR /var/www

# Используем entrypoint для инициализации
ENTRYPOINT ["/entrypoint.sh"]
