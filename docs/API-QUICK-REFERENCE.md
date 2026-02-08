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
  "tariff_id": 2,       // опционально, по умолчанию текущий тариф подписки
  "notes": "комментарий"
}

Response: { subscription_id, new_status: "extend_pending" }
```
Примечание: После отправки заявки статус подписки меняется на `extend_pending`. 
Подписка продолжает работать. Повторная заявка невозможна пока статус `extend_pending`.

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

### Создать подписку для пользователя
```http
POST /api/v1/admin/subscriptions/create
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "user_id": 1,
  "tariff_id": 2,
  "category_id": 1,
  "location_id": 1,
  "payment_method": "card|cash|transfer",
  "notes": "миграция со старой CRM",  // опционально
  "duration_hours": 720,               // опционально, по умолчанию из тарифа
  "price": 5000,                       // опционально, по умолчанию из тарифа
  "auto_activate": true                // опционально, по умолчанию true
}

Response: { subscription_id, status, start_date, end_date }
```
Примечание: Используется для миграции пользователей со старой CRM или ручного создания подписок.

## ⭐ Избранное

### Получить список избранного
```http
GET /api/v1/favorites?page=1&per_page=20&order=desc&date_from=2026-01-01&date_to=2026-01-31&comment=текст&status_id=1
Authorization: Bearer {access_token}

Response: {
  listings: [ { id, title, price, phone, address, comment, status, ... } ],
  pagination: { page, per_page, total, total_pages }
}
```

### Добавить/удалить из избранного (toggle)
```http
POST /api/v1/favorites/toggle
Authorization: Bearer {access_token}
Content-Type: application/json

{ "listing_id": 123 }

Response: { is_favorite: true|false }
```

### Проверить, в избранном ли объявление
```http
GET /api/v1/favorites/check/{listing_id}
Authorization: Bearer {access_token}

Response: { is_favorite: true|false }
```

### Получить количество избранных
```http
GET /api/v1/favorites/count
Authorization: Bearer {access_token}

Response: { count: 15 }
```

### Обновить комментарий
```http
PUT /api/v1/favorites/comment
Authorization: Bearer {access_token}
Content-Type: application/json

{ "listing_id": 123, "comment": "Текст комментария (max 250)" }

Response: { message: "Комментарий обновлён" }
```

### Обновить статус объявления
```http
PUT /api/v1/favorites/status
Authorization: Bearer {access_token}
Content-Type: application/json

{ "listing_id": 123, "status_id": 1 }  // status_id: null для сброса

Response: { message: "Статус обновлён", status: { id, name, color } }
```

### Получить пользовательские статусы
```http
GET /api/v1/favorites/statuses
Authorization: Bearer {access_token}

Response: {
  statuses: [ { id, name, color, sort_order, favorites_count } ]
}
```

### Создать статус
```http
POST /api/v1/favorites/statuses
Authorization: Bearer {access_token}
Content-Type: application/json

{ "name": "Название", "color": "#FF5733" }

Response: { status: { id, name, color, sort_order } }
```

### Обновить статус
```http
PUT /api/v1/favorites/statuses/{id}
Authorization: Bearer {access_token}
Content-Type: application/json

{ "name": "Новое название", "color": "#00FF00" }

Response: { status: { id, name, color } }
```

### Удалить статус
```http
DELETE /api/v1/favorites/statuses/{id}
Authorization: Bearer {access_token}

Response: { message: "Статус удалён" }
```

### Изменить порядок статусов
```http
PUT /api/v1/favorites/statuses/reorder
Authorization: Bearer {access_token}
Content-Type: application/json

{ "order": [3, 1, 2] }  // массив ID в нужном порядке

Response: { message: "Порядок обновлён" }
```

---

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

## 📊 Аналитика (только админ)

### Получить данные для графиков
```http
POST /api/v1/admin/analytics/charts
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "period": "week|month|quarter|year",  // по умолчанию week
  "date_from": "01.01.2026",            // опционально, формат DD.MM.YYYY
  "date_to": "31.01.2026"               // опционально
}

Response: {
  period: { from, to, group_by },
  chart_data: [ { date, label, revenue, users, subscriptions } ],
  totals: { revenue, users, subscriptions }
}
```
Примечание: Данные админов исключены из статистики.

### Получить сводную статистику
```http
GET /api/v1/admin/analytics/summary
Authorization: Bearer {access_token}

Response: {
  revenue: { today, week, month },
  users: { today, week, month, total },
  subscriptions: { today, week, month, active }
}
```
Примечание: Учитываются только активные подписки. Админы исключены из статистики.

## 👥 Управление пользователями (только админ)

### Получить список пользователей
```http
POST /api/v1/admin/users
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "page": 1,                    // номер страницы
  "per_page": 20,               // записей на страницу (макс. 100)
  "search": "имя",              // поиск по ID, имени или @username
  "role": "user|admin",         // фильтр по роли
  "has_subscription": true,     // фильтр по наличию активной подписки
  "sort": "created_at",         // поле сортировки (id, name, role, created_at)
  "order": "desc"               // направление (asc, desc)
}

Response: {
  users: [
    {
      id, name, telegram_username, telegram_id, role, created_at,
      has_active_subscription, active_subscriptions_count,
      subscriptions: [ { id, category, location, status, end_date } ]
    }
  ],
  pagination: { page, per_page, total, total_pages }
}
```

### Имперсонация (вход под пользователем)
```http
POST /api/v1/admin/users/impersonate
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "user_id": 123
}

Response: {
  access_token: "...",
  user: { id, name, telegram_username, role },
  impersonated_by: 1  // ID админа
}
```
Примечание: Генерирует access_token для указанного пользователя. Используется для тестирования системы от имени пользователя.

## 📋 Объявления

### Получить список объявлений
```http
POST /api/v1/listings
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "page": 1,
  "per_page": 10,
  "sort": "created_at",
  "order": "desc",
  "date_from": "2026-01-01",
  "date_to": "2026-01-31",
  "status": "new",
  "source_id": [1, 2],
  "category_id": 1,
  "location_id": [1, 2],
  "price_from": 30000,
  "price_to": 100000,
  "room_id": [1, 2],
  "metro_id": [1, 2, 3],
  "phone": "79001234567",
  "external_id": "123456",
  "call_status_id": [0, 1]
}

Response: {
  data: {
    listings: [ { id, title, price, phone, address, ... } ],
    pagination: { total, page, per_page, total_pages },
    stats: { new_count, raised_count, ... }
  }
}
```

### Получить одно объявление
```http
GET /api/v1/listings/{id}
Authorization: Bearer {access_token}

Response: { data: { listing: { id, title, price, phone, address, metro, ... } } }
```

### Обновить статус объявления
```http
PATCH /api/v1/listings/{id}/status
Authorization: Bearer {access_token}
Content-Type: application/json

{ "status": "new" }

Response: { message: "Статус объявления успешно обновлён", data: { listing } }
```

### Получить статистику объявлений
```http
GET /api/v1/listings/stats
Authorization: Bearer {access_token}

Response: { data: { new_count, raised_count, ... } }
```

---

## 🔍 Фильтры

### Получить данные для фильтров
```http
GET /api/v1/filters?category_id=1&location_id[]=1&location_id[]=2
Authorization: Bearer {access_token}

Response: {
  data: {
    categories: [ { id, name } ],
    locations: [ { id, name } ],
    metro: [ { id, name, line, color } ],
    rooms: [ { id, name, code } ],
    sources: [ { id, name } ],
    call_statuses: [ { id, name, color } ],
    meta: { is_admin, selected_category_id, selected_location_ids }
  }
}
```
Примечание: Обычный пользователь видит только категории/локации по своим подпискам. Админ видит всё.

---

## 📷 Обработка фото

### Создать задачу на обработку фото
```http
POST /api/v1/photo-tasks
Authorization: Bearer {access_token}
Content-Type: application/json

{ "listing_id": 123 }

Response: {
  code: 201,
  data: { id, listing_id, status, photos_count, archive_path }
}
```
Примечание: Удаление водяных знаков с фото объявления. Одна задача на объявление.

### Скачать архив с фото
```http
GET /api/v1/photo-tasks/{id}/download
Authorization: Bearer {access_token}

Response: ZIP файл (application/zip)
```

---

## 📊 Коды ответов

| Код | Описание | error |
|-----|----------|-------|
| 200 | Успешно | - |
| 400 | Неверный запрос | validation_error |
| 401 | Не авторизован | invalid_token, token_expired, invalid_credentials |
| 403 | Доступ запрещён | access_denied, subscription_required |
| 404 | Не найдено | not_found |
| 422 | Ошибка валидации | validation_error |
| 500 | Ошибка сервера | internal_error |

## 🔒 Защита по подписке (SubscriptionMiddleware)

Некоторые эндпоинты требуют наличия активной подписки (статус `active` или `extend_pending`).
Администраторы имеют доступ ко всем эндпоинтам без ограничений.

### Эндпоинты, требующие активную подписку:

| Группа | Эндпоинты |
|--------|-----------|
| Настройки | `GET/PUT /api/v1/me/settings`, `PUT /api/v1/me/phone-status`, `PUT /api/v1/me/auto-call`, `PUT /api/v1/me/auto-call-raised`, `GET /api/v1/me/app-login`, `POST /api/v1/me/generate-password` |
| Локации | `/api/v1/location-polygons/*` |
| Фильтры | `GET /api/v1/filters` |
| Объявления | `/api/v1/listings/*` |
| Обработка фото | `/api/v1/photo-tasks/*` |
| Избранное | `/api/v1/favorites/*` |

### Эндпоинты, доступные без подписки:

| Группа | Эндпоинты |
|--------|-----------|
| Информация | `GET /api/v1/me/info`, `GET /api/v1/me/status`, `GET /api/v1/me/download-info`, `GET /api/v1/me/download/android` |
| Подписки | `/api/v1/subscriptions/*` |
| Каталог | `GET /api/v1/catalog/tariff-info` |
| Биллинг | `/api/v1/billing/*` |

### Ответ при отсутствии подписки:

```json
{
  "code": 403,
  "status": "error",
  "message": "Для доступа к этому разделу необходима активная подписка",
  "error": "subscription_required"
}
```

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
| extend_pending | Ожидает продления (подписка работает, заявка отправлена) |
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
