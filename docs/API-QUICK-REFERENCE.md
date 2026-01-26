# API Quick Reference — Быстрая справка

## 🔑 Аутентификация

### Получить токены
```http
POST /api/v1/auth/telegram
Content-Type: application/json

{
  "id": "telegram_user_id",
  "first_name": "Имя",
  "username": "username",
  "photo_url": "url",
  "auth_date": 1234567890,
  "hash": "telegram_hash"
}

Response: { access_token, refresh_token, expires_in }
```

### Обновить токен
```http
GET /api/v1/auth/refresh
Authorization: Bearer {refresh_token}

Response: { access_token, refresh_token, expires_in }
```

### Выход
```http
GET /api/v1/auth/logout
Authorization: Bearer {access_token}

Response: { code: 200, status: "success" }
```

## 👤 Пользователь

### Получить информацию о себе
```http
GET /api/v1/me/info
Authorization: Bearer {access_token}

Response: {
  user: { id, name, role, phone_status, auto_call, ... }
}
```

### Получить настройки
```http
GET /api/v1/me/settings
Authorization: Bearer {access_token}

Response: {
  settings: { log_events, auto_call, auto_call_raised, telegram_notifications },
  sources: [ { id, name, enabled } ],
  active_subscriptions: [ { id, name, enabled } ]
}
```

### Обновить настройки
```http
PUT /api/v1/me/settings
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "settings": { "log_events": true, "auto_call": false, ... },
  "sources": [ { "id": 1, "name": "Авито", "enabled": true }, ... ],
  "active_subscriptions": [ { "id": 1, "name": "...", "enabled": true }, ... ]
}

Response: {
  message: "Настройки успешно обновлены",
  data: { settings, sources, active_subscriptions }
}
```

### Получить логин для приложения
```http
GET /api/v1/me/app-login
Authorization: Bearer {access_token}

Response: { login: "user_id" }
```

### Сгенерировать новый пароль
```http
POST /api/v1/me/generate-password
Authorization: Bearer {access_token}

Response: { message: "Новый пароль отправлен в Telegram" }
```

### Получить информацию о приложениях для скачивания
```http
GET /api/v1/me/download-info
Authorization: Bearer {access_token}

Response: {
  android: { available: true, size: 15728640, size_formatted: "15 MB", download_url: "/api/v1/me/download/android" },
  ios: { available: false, size: null, download_url: null }
}
```

### Скачать Android приложение
```http
GET /api/v1/me/download/android
Authorization: Bearer {access_token}

Response: APK файл (application/vnd.android.package-archive)
```

## 📋 Подписки

### Получить активные подписки (для настроек локаций)
```http
GET /api/v1/subscriptions
Authorization: Bearer {access_token}

Response: {
  subscriptions: [
    { id, location: { id, name, center_lat, center_lng, bounds } }
  ]
}
```

### Получить все подписки (для профиля)
```http
GET /api/v1/subscriptions/all
Authorization: Bearer {access_token}

Response: {
  subscriptions: [
    { 
      id, category_id, category_name, location_id, location_name,
      tariff_id, tariff_name, status, start_date, end_date, 
      price_paid, is_enabled 
    }
  ]
}
```

### Создать заявку на подписку
```http
POST /api/v1/subscriptions
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "tariff_id": 1,
  "category_id": 1,
  "location_id": 1
}

Response: { subscription_id, status }
```

### Создать запрос на продление
```http
POST /api/v1/subscriptions/extend-request
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "subscription_id": 1,
  "tariff_id": 2,
  "notes": "комментарий"
}
```

## 📍 Локации (полигоны)

### Получить полигоны по подписке
```http
GET /api/v1/location-polygons/subscription/{subscription_id}
Authorization: Bearer {access_token}

Response: {
  polygons: [ { id, name, coordinates } ]
}
```

### Создать полигон
```http
POST /api/v1/location-polygons
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "subscription_id": 1,
  "name": "Название области",
  "coordinates": [ [lat, lng], ... ]
}
```

### Обновить полигон
```http
PUT /api/v1/location-polygons/{id}
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "name": "Новое название",
  "coordinates": [ [lat, lng], ... ]
}
```

### Удалить полигон
```http
DELETE /api/v1/location-polygons/{id}
Authorization: Bearer {access_token}
```

## 📦 Каталог

### Получить информацию о тарифах
```http
GET /api/v1/catalog/tariff-info
Authorization: Bearer {access_token}

Response: {
  categories: [ { id, name } ],
  locations: [ { id, name } ],
  tariffs: [ { id, name, description } ],
  tariff_prices: [ { tariff_id, location_id, price } ]
}
```

## 👨‍💼 Административные (требуют роль admin)

### Активировать подписку
```http
POST /api/v1/admin/subscriptions/activate
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "subscription_id": 1,
  "payment_method": "card|cash|transfer",
  "notes": "комментарий",
  "duration_hours": 720  // опционально
}
```

### Продлить подписку
```http
POST /api/v1/admin/subscriptions/extend
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "subscription_id": 1,
  "payment_method": "card|cash|transfer",
  "price": 5000,         // опционально
  "notes": "комментарий",
  "duration_hours": 720  // опционально
}
```

### Отменить подписку
```http
POST /api/v1/admin/subscriptions/cancel
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "subscription_id": 1,
  "reason": "причина отмены"
}
```

## 💰 Биллинг

### Получить подписки пользователя
```http
POST /api/v1/billing/user-subscriptions
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "user_id": 1  // опционально для админа
}
```

### Текущие подписки (только админ)
```http
POST /api/v1/billing/admin/current-subscriptions
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "status": "active|pending|expired",
  "location_id": 1,
  "category_id": 1
}
```

### История подписок (только админ)
```http
POST /api/v1/billing/admin/subscription-history
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "user_id": 1,
  "subscription_id": 1,
  "date_from": "2024-01-01",
  "date_to": "2024-12-31"
}
```

## 📊 Коды ответов

| Код | Описание | error |
|-----|----------|-------|
| 200 | Успешно | - |
| 400 | Неверный запрос | validation_error |
| 401 | Не авторизован | invalid_token, token_expired, invalid_credentials |
| 403 | Доступ запрещён | access_denied, subscription_expired |
| 404 | Не найдено | not_found |
| 422 | Ошибка валидации | validation_error |
| 500 | Ошибка сервера | internal_error |

## 🔐 Заголовки

### Обязательные для защищённых эндпоинтов
```http
Authorization: Bearer {access_token}
Content-Type: application/json
```

### Для обновления токена
```http
Authorization: Bearer {refresh_token}
```

## 📝 Формат ответа

### Успешный ответ
```json
{
  "code": 200,
  "status": "success",
  "message": "Описание",
  "data": { ... }
}
```

### Ответ с ошибкой
```json
{
  "code": 400,
  "status": "error",
  "message": "Описание ошибки",
  "error": "machine_readable_code"
}
```

## 🎯 Типы тарифов

| Код | Название | Длительность | Особенности |
|-----|----------|--------------|-------------|
| demo | Демо | 3 часа | Бесплатно, один раз |
| premium_1m | Премиум 1 месяц | 720 часов | Платный |
| premium_3m | Премиум 3 месяца | 2160 часов | Платный |
| premium_6m | Премиум 6 месяцев | 4320 часов | Платный |

## 🔄 Статусы подписки

| Статус | Описание |
|--------|----------|
| pending | Ожидает подтверждения администратором |
| active | Активна, пользователь имеет доступ |
| expired | Истекла |
| cancelled | Отменена администратором или пользователем |

## 🚀 Быстрый старт для разработки

1. Запустить проект: `make up`
2. Установить зависимости: `make install`
3. Запустить миграции: `make migrate`
4. Проверить API: открыть Redoc документацию
5. Получить токен через Telegram Widget
6. Использовать токен для запросов

## 🐛 Отладка

### Логи приложения
```bash
tail -f logs/app.log
tail -f logs/{date}.log
```

### Логи Docker
```bash
make logs
docker-compose logs -f nginx
docker-compose logs -f php-fpm
```

### База данных
```bash
# Подключиться к PostgreSQL
docker-compose exec postgres psql -U postgres -d slim_api

# Через pgAdmin
# http://localhost:5050
# Email: admin@example.com
# Password: admin
```
