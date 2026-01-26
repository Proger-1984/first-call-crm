# Деплой First Call

**Дата обновления:** 25 января 2026

---

## Локальная разработка (Development)

### Требования

- Docker и Docker Compose
- Git
- Node.js 18+ (для frontend)
- Make (опционально)

### Быстрый старт

```bash
# 1. Клонировать репозиторий
git clone https://your-repo-url.git first-call
cd first-call

# 2. Скопировать .env
cp .env.example .env
# Отредактировать .env при необходимости

# 3. Запустить всё одной командой
make init
```

### Полная пересборка с нуля (RESET)

Если нужно остановить всё и пересобрать проект с чистого листа:

```bash
cd /home/sokol/first-call

# 1. Остановить все контейнеры и УДАЛИТЬ данные БД
docker-compose down -v
# ⚠️ ВНИМАНИЕ: флаг -v удаляет volumes (все данные PostgreSQL!)

# 2. Удалить все образы проекта (опционально)
docker-compose down -v --rmi local

# 3. Удалить node_modules фронтенда (опционально)
rm -rf frontend-react/node_modules

# 4. Пересобрать и запустить
docker-compose build --no-cache
docker-compose up -d

# 5. Установить зависимости
make install

# 6. Запустить миграции (создаст таблицы и начальные данные)
make migrate

# 7. Пересобрать фронтенд
cd frontend-react && npm install && npm run build
```

**Одной командой (полный reset):**

```bash
docker-compose down -v && \
docker-compose build --no-cache && \
docker-compose up -d && \
sleep 5 && \
make install && \
make migrate && \
cd frontend-react && npm install && npm run build
```

**Что происходит при reset:**
- Удаляются все контейнеры
- Удаляются volumes (данные PostgreSQL)
- Пересобираются Docker образы
- Заново устанавливаются PHP зависимости
- Заново запускаются миграции (создаются таблицы + seed данные)
- Пересобирается фронтенд

### Пошаговый запуск

#### Backend + БД (Docker)

```bash
# Запуск контейнеров
docker-compose up -d

# Проверить статус
docker-compose ps

# Установить PHP зависимости
make install

# Запустить миграции
make migrate
```

**Контейнеры:**
| Контейнер | Сервис | Порт |
|-----------|--------|------|
| slim_postgres | PostgreSQL 15 + PostGIS | 5432 |
| slim_php-fpm | PHP-FPM 8.3 | 9000 (internal) |
| slim_php-cli | PHP CLI | — |
| slim_nginx | Nginx + SSL | 80, 443 |
| slim_pgadmin | pgAdmin 4 | 5050 |

#### Frontend (React)

```bash
cd frontend-react

# Установить зависимости
npm install

# Запустить dev-сервер
npm run dev
# http://localhost:3000
```

### Настройка hosts (для SSL)

Добавить в `/etc/hosts` (Linux/Mac) или `C:\Windows\System32\drivers\etc\hosts` (Windows):

```
127.0.0.1 local.firstcall.com
```

После этого приложение доступно по https://local.firstcall.com

### URLs локальной разработки

| Сервис | URL |
|--------|-----|
| Приложение (prod build) | https://local.firstcall.com |
| Frontend dev server | http://localhost:3000 |
| API | https://local.firstcall.com/api/v1 |
| pgAdmin | http://localhost:5050 |
| API документация | http://localhost:8090/redoc.html |

### Supervisor и Cron задачи

При запуске контейнера `slim_php-cli` автоматически:
1. Ожидается готовность PostgreSQL
2. Запускаются миграции
3. Запускается Supervisor, который управляет:
   - **cron** — планировщик задач
   - **parse-metro-stations** — запускается при старте контейнера

**Расписание cron задач:**

| Задача | Расписание | Команда |
|--------|------------|---------|
| Парсинг станций метро | Ежедневно в 3:00 + при старте контейнера | `parse-metro-stations` |
| Обновление истёкших подписок | Каждые 5 минут | `update-expired-subscriptions` |
| Уведомление об истекающих подписках | Ежедневно в 10:00 | `notify-subscription-expiring` |
| Уведомление об истёкших подписках | Ежедневно в 10:30 | `notify-subscription-expired` |

**Управление через Supervisor:**
```bash
# Статус всех процессов
docker exec slim_php-cli supervisorctl status

# Запустить задачу вручную
docker exec slim_php-cli supervisorctl start parse-metro-stations
docker exec slim_php-cli supervisorctl start update-expired-subscriptions
docker exec slim_php-cli supervisorctl start notify-subscription-expiring
docker exec slim_php-cli supervisorctl start notify-subscription-expired

# Остановить задачу
docker exec slim_php-cli supervisorctl stop <task-name>

# Перезапустить cron
docker exec slim_php-cli supervisorctl restart cron
```

**Логи:**
```bash
# Логи Supervisor
docker exec slim_php-cli cat /var/log/supervisor/supervisord.log

# Логи конкретной задачи
docker exec slim_php-cli cat /var/log/supervisor/parse-metro-stations.log
docker exec slim_php-cli cat /var/log/supervisor/update-expired-subscriptions.log
docker exec slim_php-cli cat /var/log/supervisor/notify-subscription-expiring.log
docker exec slim_php-cli cat /var/log/supervisor/notify-subscription-expired.log

# Логи cron
docker exec slim_php-cli cat /var/log/supervisor/cron.log

# Или через docker logs
docker logs slim_php-cli
```

**Ручной запуск команд (напрямую):**
```bash
docker exec slim_php-cli php bin/app.php parse-metro-stations
docker exec slim_php-cli php bin/app.php update-expired-subscriptions
docker exec slim_php-cli php bin/app.php notify-subscription-expiring
docker exec slim_php-cli php bin/app.php notify-subscription-expired
```

### Полезные команды

```bash
# Docker
make up          # Запуск
make down        # Остановка
make restart     # Перезапуск
make logs        # Логи
make build       # Пересборка образов

# Backend
make install     # Composer install
make migrate     # Миграции

# Войти в контейнер
docker exec -it slim_php-cli sh
docker exec -it slim_postgres psql -U postgres -d slim_api

# Supervisor
docker exec slim_php-cli supervisorctl status
docker exec slim_php-cli crontab -l
```

---

## Production деплой

### Требования сервера

- Ubuntu 22.04 LTS (рекомендуется)
- Docker 24+ и Docker Compose v2
- Nginx (как reverse proxy, опционально)
- SSL сертификат (Let's Encrypt)
- Минимум 2GB RAM, 20GB SSD

### Подготовка сервера

```bash
# Обновить систему
sudo apt update && sudo apt upgrade -y

# Установить Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Установить Docker Compose
sudo apt install docker-compose-plugin

# Установить Make
sudo apt install make

# Перелогиниться для применения группы docker
exit
```

### Деплой приложения

```bash
# 1. Клонировать репозиторий
git clone https://your-repo-url.git /var/www/first-call
cd /var/www/first-call

# 2. Создать .env для production
cp .env.example .env
nano .env
```

### Настройка .env для production

```env
# Приложение
APP_NAME="FIRST CALL REST API"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# База данных
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=slim_api
DB_USERNAME=postgres
DB_PASSWORD=STRONG_PASSWORD_HERE  # Сменить!

# JWT (сгенерировать новые!)
JWT_SECRET=your_production_access_secret_min_32_chars
JWT_REFRESH_SECRET=your_production_refresh_secret_min_32_chars
JWT_ACCESS_EXPIRATION=3600
JWT_REFRESH_EXPIRATION=604800

# Telegram Bot
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_BOT_USERNAME=your_bot_username
TELEGRAM_ADMIN_CHAT_ID=your_admin_chat_id
```

**Генерация JWT секретов:**
```bash
openssl rand -hex 32  # Для JWT_SECRET
openssl rand -hex 32  # Для JWT_REFRESH_SECRET
```

### Запуск в production

```bash
# 3. Собрать и запустить
docker-compose -f docker-compose.yml up -d --build

# 4. Установить зависимости
docker exec -it slim_php-cli composer install --no-dev --optimize-autoloader

# 5. Запустить миграции
docker exec -it slim_php-cli php db/migrations/run.php

# 6. Собрать frontend
cd frontend-react
npm ci
npm run build
```

### Настройка Nginx (внешний)

Если используете внешний Nginx вместо контейнера:

```nginx
# /etc/nginx/sites-available/firstcall
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    # Frontend (React build)
    root /var/www/first-call/frontend-react/dist;
    index index.html;

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API proxy
    location /api {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Static files
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

```bash
# Активировать конфиг
sudo ln -s /etc/nginx/sites-available/firstcall /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL с Let's Encrypt

```bash
# Установить certbot
sudo apt install certbot python3-certbot-nginx

# Получить сертификат
sudo certbot --nginx -d your-domain.com

# Автообновление (добавляется автоматически)
sudo certbot renew --dry-run
```

---

## CI/CD (GitHub Actions)

### Пример workflow

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Deploy to server
        uses: appleboy/ssh-action@v1.0.0
        with:
          host: ${{ secrets.SERVER_HOST }}
          username: ${{ secrets.SERVER_USER }}
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          script: |
            cd /var/www/first-call
            git pull origin main
            docker-compose up -d --build
            docker exec slim_php-cli composer install --no-dev --optimize-autoloader
            docker exec slim_php-cli php db/migrations/run.php
            cd frontend-react && npm ci && npm run build
```

### Secrets для GitHub

Добавить в Settings → Secrets:
- `SERVER_HOST` — IP сервера
- `SERVER_USER` — пользователь SSH
- `SSH_PRIVATE_KEY` — приватный ключ

---

## Мониторинг и логи

### Просмотр логов

```bash
# Все контейнеры
docker-compose logs -f

# Конкретный сервис
docker-compose logs -f nginx
docker-compose logs -f php-fpm

# Логи приложения
tail -f /var/www/first-call/logs/*.log
```

### Проверка здоровья

```bash
# Статус контейнеров
docker-compose ps

# Использование ресурсов
docker stats

# Проверка API
curl -s https://your-domain.com/api/v1/health | jq
```

---

## Бэкапы

### База данных

```bash
# Создать бэкап
docker exec slim_postgres pg_dump -U postgres slim_api > backup_$(date +%Y%m%d).sql

# Восстановить
docker exec -i slim_postgres psql -U postgres slim_api < backup_20260125.sql
```

### Автоматические бэкапы (cron)

```bash
# Добавить в crontab
crontab -e

# Ежедневный бэкап в 3:00
0 3 * * * docker exec slim_postgres pg_dump -U postgres slim_api | gzip > /backups/db_$(date +\%Y\%m\%d).sql.gz

# Удалять бэкапы старше 30 дней
0 4 * * * find /backups -name "db_*.sql.gz" -mtime +30 -delete
```

---

## Обновление

### Обновление кода

```bash
cd /var/www/first-call

# Получить изменения
git pull origin main

# Пересобрать контейнеры (если изменился Dockerfile)
docker-compose up -d --build

# Обновить зависимости
docker exec slim_php-cli composer install --no-dev --optimize-autoloader

# Запустить новые миграции
docker exec slim_php-cli php db/migrations/run.php

# Пересобрать frontend
cd frontend-react && npm ci && npm run build
```

### Откат

```bash
# Откатить на предыдущий коммит
git reset --hard HEAD~1

# Или на конкретный коммит
git reset --hard abc123

# Пересобрать
docker-compose up -d --build
```

---

## Troubleshooting

### Контейнер не запускается

```bash
# Проверить логи
docker-compose logs php-fpm

# Проверить конфигурацию
docker-compose config

# Пересобрать с нуля
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Ошибки прав доступа

```bash
# Исправить права
chmod +x fix-permissions.sh
./fix-permissions.sh

# Или вручную
sudo chown -R www-data:www-data /var/www/first-call
sudo chmod -R 755 /var/www/first-call
sudo chmod -R 777 /var/www/first-call/logs
```

### База данных недоступна

```bash
# Проверить статус PostgreSQL
docker exec slim_postgres pg_isready

# Проверить подключение
docker exec slim_postgres psql -U postgres -c "SELECT 1"

# Перезапустить
docker-compose restart postgres
```

### Nginx 502 Bad Gateway

```bash
# Проверить php-fpm
docker-compose logs php-fpm

# Проверить сокет/порт
docker exec slim_nginx cat /etc/nginx/conf.d/default.conf

# Перезапустить
docker-compose restart nginx php-fpm
```

---

## Безопасность production

### Чек-лист

- [ ] Сменить пароль PostgreSQL
- [ ] Сгенерировать новые JWT секреты
- [ ] Отключить APP_DEBUG
- [ ] Настроить firewall (ufw)
- [ ] Настроить fail2ban
- [ ] Включить HTTPS
- [ ] Настроить бэкапы
- [ ] Ограничить доступ к pgAdmin

### Firewall (ufw)

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow http
sudo ufw allow https
sudo ufw enable
```

### Ограничить pgAdmin

В production лучше отключить pgAdmin или ограничить доступ:

```yaml
# docker-compose.yml
pgadmin:
  # Закомментировать ports для production
  # ports:
  #   - "5050:80"
```

Или использовать SSH туннель для доступа:
```bash
ssh -L 5050:localhost:5050 user@server
# Затем открыть http://localhost:5050
```
