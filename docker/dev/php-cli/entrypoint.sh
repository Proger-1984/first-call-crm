#!/bin/sh
set -e

echo "=== First Call CLI Container Starting ==="
echo "Время: $(date)"

# Создаём директории для логов
mkdir -p /var/log/supervisor
chmod 755 /var/log/supervisor

# Ждём готовности PostgreSQL
echo "Ожидание готовности PostgreSQL..."
MAX_TRIES=30
TRIES=0
until pg_isready -h postgres -U postgres -d slim_api > /dev/null 2>&1; do
    TRIES=$((TRIES + 1))
    if [ $TRIES -ge $MAX_TRIES ]; then
        echo "ОШИБКА: PostgreSQL не готов после $MAX_TRIES попыток"
        exit 1
    fi
    echo "PostgreSQL не готов, попытка $TRIES/$MAX_TRIES..."
    sleep 2
done
echo "PostgreSQL готов!"

# Запускаем миграции
echo "Запуск миграций..."
cd /var/www
php db/migrations/run.php || echo "Миграции завершились с ошибкой (возможно, уже выполнены)"

# Устанавливаем crontab
echo "Настройка cron..."
crontab /etc/cron.d/app-crontab

echo "=== Запуск Supervisor ==="
echo "Cron задачи:"
crontab -l

# Запускаем Supervisor (он запустит cron и parse-metro-stations)
exec /usr/bin/supervisord -c /etc/supervisord.conf
