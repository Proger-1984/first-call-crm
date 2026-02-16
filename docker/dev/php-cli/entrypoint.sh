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

# Запускаем Supervisor в фоне
/usr/bin/supervisord -c /etc/supervisord.conf &
SUPERVISORD_PID=$!

# Ждём инициализации supervisor
sleep 3

# Проверяем рабочее время для parse-yandex (7:00-0:59, остановка в 1:00)
CURRENT_HOUR=$(date +%-H)
if [ "$CURRENT_HOUR" -ge 7 ] || [ "$CURRENT_HOUR" -eq 0 ]; then
    echo "Рабочее время ($CURRENT_HOUR:xx), запускаем parse-yandex..."
    /usr/bin/supervisorctl start parse-yandex >> /var/log/supervisor/cron.log 2>&1 || true
else
    echo "Нерабочее время ($CURRENT_HOUR:xx), parse-yandex запустится по крону в 7:00"
fi

# Перехватываем сигналы для корректной остановки контейнера
trap "kill -TERM $SUPERVISORD_PID" SIGTERM SIGINT
wait $SUPERVISORD_PID
