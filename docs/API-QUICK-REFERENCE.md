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

## 🔐 Авторизация источников (CIAN, Avito)

### Получить статус авторизации
```http
GET /api/v1/source-auth/status?source=cian
Authorization: Bearer {access_token}

Response: {
  cian: {
    is_authorized: true,
    has_cookies: true,
    is_expired: false,
    last_validated_at: "2026-02-08T12:00:00Z",
    expires_at: "2026-02-18T23:59:59Z",
    subscription_info: {
      status: "active",
      tariff: "Премиум",
      expire_text: "До 18 февраля",
      limit_info: "50 из 100 контактов",
      phone: "+7 999 123-45-67"
    }
  },
  avito: { is_authorized: false, has_cookies: false }
}
```

### Сохранить куки (ручной ввод)
```http
POST /api/v1/source-auth/cookies
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "source": "cian",
  "cookies": "session_id=abc123; _CIAN_GK=xyz789; ..."
}

Response: {
  success: true,
  message: "Куки сохранены и проверены",
  auth_status: true,
  subscription_info: { tariff, expire_text, limit_info }
}
```

### Удалить авторизацию
```http
DELETE /api/v1/source-auth/cookies?source=cian
Authorization: Bearer {access_token}

Response: { success: true, message: "Авторизация удалена" }
```

### Перепроверить авторизацию
```http
POST /api/v1/source-auth/revalidate
Authorization: Bearer {access_token}
Content-Type: application/json

{ "source": "cian" }  // или "avito"

Response:
{
  success: true,
  message: "Авторизация подтверждена",
  auth_status: true,
  subscription_info: { ... },
  cookies_updated: false  // true если куки были обновлены
}
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

---

## CRM — Объекты недвижимости (v2)

> Требует `Authorization: Bearer <token>` + активная подписка (`SubscriptionMiddleware`)
>
> Новая модель CRM: Объект → Контакт → Связка (object_clients) с воронкой.

### Список объектов
```http
GET /api/v1/properties?page=1&per_page=20&search=Ленина&deal_type=sale&stage_ids[]=1&stage_ids[]=2&is_archived=false&sort=created_at&order=desc

Response: {
  data: {
    properties: [ { id, title, address, price, deal_type, owner_name, contacts_count, ... } ],
    pagination: { total, page, per_page, total_pages }
  }
}
```

### Карточка объекта
```http
GET /api/v1/properties/{id}

Response: {
  data: {
    property: {
      id, title, address, price, rooms, area, floor, floors_total,
      description, url, deal_type, owner_name, owner_phone, owner_phone_secondary,
      source_type, source_details, comment, is_archived, listing_id,
      object_clients: [ { id, contact_id, contact: {...}, pipeline_stage: {...}, next_contact_at } ],
      listing: { id, url, external_id },
      contacts_count, created_at, updated_at
    }
  }
}
```

### Создать объект
```http
POST /api/v1/properties
Content-Type: application/json

{
  "listing_id": "7852879731",       // ID объявления (external_id или internal id)
  "title": "2к квартира на Ленина",
  "address": "ул. Ленина 15",
  "price": 5200000,
  "rooms": 2,
  "area": 65,
  "floor": 15,
  "floors_total": 17,
  "deal_type": "sale",
  "owner_name": "Петров И.И.",
  "owner_phone": "+79001234567",
  "source_type": "cian",
  "comment": "Хороший вариант"
}
```
Примечание: Если указан `listing_id`, данные объявления (адрес, цена, площадь, комнаты, этаж) подтягиваются автоматически. Поиск по `external_id`, затем по внутреннему ID.

### Обновить объект
```http
PUT /api/v1/properties/{id}
```

### Удалить объект
```http
DELETE /api/v1/properties/{id}
```

### Архивировать/разархивировать
```http
PATCH /api/v1/properties/{id}/archive
Content-Type: application/json

{ "is_archived": true }
```

### Kanban-доска (стадии + пары объект+контакт)
```http
GET /api/v1/properties/pipeline

Response: {
  data: {
    columns: [
      {
        id, name, color, sort_order, is_system, is_final,
        cards: [ { id, property: {...}, contact: {...}, comment, next_contact_at } ]
      }
    ]
  }
}
```
Примечание: Карточка на kanban = пара (объект + контакт). Drag-n-drop перемещает связку `object_client`.

### Статистика
```http
GET /api/v1/properties/stats

Response: {
  data: {
    total, active, archived, sale_count, rent_count,
    by_stage: [ { stage_id, stage_name, color, count } ]
  }
}
```

---

## CRM — Связки объект+контакт

### Привязать контакт к объекту
```http
POST /api/v1/properties/{id}/contacts
Content-Type: application/json

{ "contact_id": 5 }

Response: { data: { object_client: { id, property_id, contact_id, pipeline_stage_id } } }
```
Примечание: Контакт привязывается с начальной стадией воронки (первая по sort_order).

### Отвязать контакт
```http
DELETE /api/v1/properties/{id}/contacts/{contact_id}
```

### Сменить стадию связки (drag-n-drop на kanban)
```http
PATCH /api/v1/properties/{id}/contacts/{contact_id}/stage
Content-Type: application/json

{ "stage_id": 3 }
```

### Обновить связку (комментарий, дата контакта)
```http
PATCH /api/v1/properties/{id}/contacts/{contact_id}
Content-Type: application/json

{ "comment": "Обсудили условия", "next_contact_at": "2026-02-20 10:00:00" }
```

---

## CRM — Справочник контактов (v2)

> Требует `Authorization: Bearer <token>` + активная подписка (`SubscriptionMiddleware`)

### Список контактов
```http
GET /api/v1/contacts?page=1&per_page=20&search=Иванов

Response: {
  data: {
    contacts: [ { id, name, phone, email, telegram_username, properties_count, created_at } ],
    pagination: { total, page, per_page, total_pages }
  }
}
```

### Поиск контактов (для модалки привязки)
```http
GET /api/v1/contacts/search?q=Иванов

Response: {
  data: {
    contacts: [ { id, name, phone, email } ]
  }
}
```

### Карточка контакта
```http
GET /api/v1/contacts/{id}

Response: {
  data: {
    contact: {
      id, name, phone, phone_secondary, email, telegram_username, comment,
      object_clients: [ { id, property: {...}, pipeline_stage: {...}, next_contact_at } ],
      created_at, updated_at
    }
  }
}
```

### Создать контакт
```http
POST /api/v1/contacts
Content-Type: application/json

{
  "name": "Иванов Иван",
  "phone": "+79001234567",
  "email": "ivanov@mail.ru",
  "telegram_username": "ivanov",
  "comment": "Ищет 2к квартиру"
}

Response: { data: { contact: { id, name, phone, ... } } }
```

### Обновить контакт
```http
PUT /api/v1/contacts/{id}
```

### Удалить контакт
```http
DELETE /api/v1/contacts/{id}
```

---

## CRM — Клиенты (DEPRECATED — старая модель v1)

> **Устаревшие эндпоинты.** Используйте `/properties` и `/contacts` (v2).
> Старые маршруты оставлены для обратной совместимости.

### Список клиентов
```http
GET /api/v1/clients?page=1&per_page=20&search=Иванов&client_type=buyer&stage_id=1&is_archived=false&sort=created_at&order=desc
```

### Карточка клиента
```http
GET /api/v1/clients/{id}
```

### Создать клиента
```http
POST /api/v1/clients
Content-Type: application/json

{
  "name": "Иванов Иван",
  "phone": "+79001234567",
  "client_type": "buyer",
  "budget_min": 5000000,
  "budget_max": 8000000,
  "source_type": "cian",
  "comment": "Ищет 2к в центре"
}
```

### Обновить / Удалить / Архивировать / Переместить
```http
PUT /api/v1/clients/{id}
DELETE /api/v1/clients/{id}
PATCH /api/v1/clients/{id}/archive
PATCH /api/v1/clients/{id}/stage
```

### Kanban / Статистика
```http
GET /api/v1/clients/pipeline
GET /api/v1/clients/stats
```

---

## CRM — Стадии воронки

### Получить все стадии
```http
GET /api/v1/clients/stages
```

### Создать стадию
```http
POST /api/v1/clients/stages
Content-Type: application/json

{ "name": "Ожидание документов", "color": "#FF9800" }
```

### Обновить стадию
```http
PUT /api/v1/clients/stages/{id}
Content-Type: application/json

{ "name": "Новое название", "color": "#4CAF50" }
```

### Удалить стадию
```http
DELETE /api/v1/clients/stages/{id}
```

### Изменить порядок
```http
PUT /api/v1/clients/stages/reorder
Content-Type: application/json

{ "order": [1, 3, 2, 5, 4] }
```
