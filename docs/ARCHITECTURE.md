# Архитектура First Call API

## Обзор системы

First Call API — REST API сервис для управления подписками на недвижимость с автоматическими уведомлениями через Telegram Bot.

## Технологический стек

- **PHP 8.3** — строгая типизация, современный синтаксис
- **Slim 4** — легковесный PHP framework для REST API
- **PHP-DI** — Dependency Injection контейнер
- **Eloquent ORM** — работа с БД через модели
- **PostgreSQL 15 + PostGIS** — хранение данных с геолокацией
- **JWT** — авторизация через access/refresh токены
- **Docker** — изолированное окружение разработки

## Слои приложения

```
┌─────────────────────────────────────────┐
│         HTTP Request (Nginx)            │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│          Middleware Layer               │
│  - CORS                                 │
│  - JSON Body Parser                     │
│  - Auth (JWT verification)              │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│         Controller Layer                │
│  - Валидация запросов                   │
│  - Формирование ответов                 │
│  - Делегирование в сервисы              │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│          Service Layer                  │
│  - Бизнес-логика                        │
│  - Интеграции (Telegram)                │
│  - Логирование                          │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│           Model Layer                   │
│  - Eloquent модели                      │
│  - Связи (Relations)                    │
│  - Аксессоры/мутаторы                   │
└─────────────────┬───────────────────────┘
                  │
┌─────────────────▼───────────────────────┐
│      PostgreSQL Database                │
└─────────────────────────────────────────┘
```

## Ключевые паттерны

### 1. Dependency Injection

Все зависимости инжектируются через контейнер:

```php
// bootstrap/container.php
JwtService::class => factory(function (ContainerInterface $c) {
    return new JwtService($c);
})
```

### 2. Service Layer Pattern

Контроллеры тонкие, вся логика в сервисах:

```php
// Controller - только валидация и делегирование
$result = $this->subscriptionService->createSubscription($data);

// Service - вся бизнес-логика
public function createSubscription(array $data): UserSubscription
{
    // Проверки, создание, уведомления
}
```

### 3. Repository Pattern (через Eloquent)

Модели инкапсулируют запросы к БД:

```php
// Не делаем SQL напрямую, используем модели
$user->activeSubscriptions()->with(['category', 'location'])->get()
```

### 4. Response Trait Pattern

Унифицированные ответы через трейт:

```php
use ResponseTrait;

return $this->respondWithData($response, $data, 200);
return $this->respondWithError($response, $message, 'code', 400);
```

## Основные модули

### Аутентификация (Auth)

**Файлы:**
- `Controllers/AuthController.php` — логин через приложение
- `Controllers/TelegramAuthController.php` — авторизация через Telegram
- `Services/AuthService.php` — логика аутентификации
- `Services/JwtService.php` — работа с токенами
- `Middleware/AuthMiddleware.php` — проверка токенов

**Поток:**
1. Пользователь отправляет данные Telegram Widget → `TelegramAuthController::authenticate`
2. Создаётся/находится пользователь → `TelegramAuthService::authenticateViaTelegram`
3. Генерируются JWT токены → `JwtService::createTokens`
4. Токены сохраняются в БД (refresh_tokens)
5. Возвращаются access_token + refresh_token

### Подписки (Subscriptions)

**Файлы:**
- `Controllers/SubscriptionController.php` — CRUD подписок
- `Controllers/AdminSubscriptionController.php` — управление админом
- `Services/SubscriptionService.php` — бизнес-логика
- `Models/UserSubscription.php` — модель подписки
- `Models/Tariff.php` — модель тарифа

**Типы подписок:**
- **Demo** — 3 часа бесплатно, активируется автоматически
- **Premium** — платные тарифы (1 месяц, 3 месяца, 6 месяцев)

**Статусы подписки:**
- `pending` — ожидает оплаты и подтверждения админом
- `active` — активна, пользователь имеет доступ
- `expired` — истекла
- `cancelled` — отменена

### Уведомления (Telegram Bot)

**Файлы:**
- `Services/TelegramService.php` — отправка сообщений

**События с уведомлениями:**
- Регистрация → пароль для приложения
- Создание демо-подписки → подтверждение
- Запрос платной подписки → инструкция по оплате
- Активация подписки → уведомление о начале
- Продление подписки → новая дата окончания
- Отмена подписки → уведомление с причиной

### Геолокация (Locations)

**Файлы:**
- `Models/Location.php` — регионы/города
- `Models/City.php` — детальные города
- `Models/UserLocationPolygon.php` — пользовательские полигоны
- `Controllers/LocationPolygonController.php` — управление полигонами

**Структура:**
- Location (регион) → City (города в регионе) → UserLocationPolygon (области поиска)
- PostGIS используется для геометрических запросов

## База данных

### Основные таблицы

```
users                    # Пользователи
├── id, name, telegram_id, role
├── password_hash       # Для входа в приложение
├── phone_status        # Статус телефона
└── is_trial_used       # Флаг использования демо

user_settings            # Настройки пользователя
├── user_id
├── auto_call           # Автозвонок
└── auto_call_raised    # Автозвонок на поднятые

sources                  # Источники объявлений
└── name, is_active

user_sources            # Pivot: источники пользователя
└── user_id, source_id, enabled

categories              # Категории недвижимости
└── name

locations               # Локации (регионы)
├── city, region
└── center_lat, center_lng, bounds

tariffs                 # Тарифы
├── name, code
├── duration_hours      # Длительность
└── price, description

tariff_prices           # Цены по локациям
└── tariff_id, location_id, price

user_subscriptions      # Подписки пользователей
├── user_id, tariff_id
├── category_id, location_id
├── status, start_date, end_date
└── payment_method

subscription_history    # История операций
└── action, notes, action_date

refresh_tokens          # JWT refresh токены
├── user_id, token
└── device_type, expires_at

user_location_polygons  # Пользовательские полигоны
└── subscription_id, name, polygon
```

## JWT Авторизация

### Access Token
- **Срок жизни:** 1 час (3600 сек)
- **Назначение:** авторизация API запросов
- **Payload:** `user_id`, `role`, `device_type`
- **Секрет:** `JWT_SECRET`

### Refresh Token
- **Срок жизни:** 7 дней (604800 сек)
- **Назначение:** обновление access token
- **Хранение:** в БД (таблица refresh_tokens)
- **Секрет:** `JWT_REFRESH_SECRET`

### Поток обновления токена
1. Access token истёк → 401 ошибка
2. Клиент отправляет refresh token → `/api/v1/auth/refresh`
3. Проверка в БД → валиден?
4. Удаление старого refresh token
5. Создание новой пары токенов
6. Возврат новых токенов клиенту

## Конфигурация

### Окружение (bootstrap/app.php)
1. Загрузка `.env`
2. Загрузка конфигов из `config/`
3. Инициализация Eloquent ORM
4. Установка timezone

### DI Container (bootstrap/container.php)
Регистрация всех сервисов и контроллеров

### Middleware (bootstrap/middleware.php)
1. Body Parsing
2. Routing
3. CORS
4. JSON Body Parser
5. Error Handler

### Routes (bootstrap/routes.php)
Загрузка маршрутов из `routes/routes.php`

## API Endpoints

### Публичные
- `POST /api/v1/auth/telegram` — авторизация через Telegram
- `POST /api/v1/auth/login` — логин через приложение
- `GET /api/v1/auth/refresh` — обновление токена
- `GET /api/v1/config/telegram-bot-username` — имя бота

### Защищённые (требуют JWT)
- `GET /api/v1/me/*` — информация о текущем пользователе
- `GET|POST /api/v1/subscriptions` — работа с подписками
- `GET|POST|PUT|DELETE /api/v1/location-polygons` — управление полигонами
- `GET /api/v1/catalog/tariff-info` — каталог тарифов

### Административные (роль admin)
- `POST /api/v1/admin/subscriptions/activate` — активация подписки
- `POST /api/v1/admin/subscriptions/extend` — продление подписки
- `POST /api/v1/admin/subscriptions/cancel` — отмена подписки
- `POST /api/v1/billing/admin/*` — биллинг панель

## Логирование

### LogService
- Файлы: `logs/{date}.log` или `logs/{filename}.log`
- Уровни: error, warning, info, debug
- Формат: JSON с timestamp и контекстом

### Логируемые события
- Ошибки авторизации
- Неверные форматы запросов
- Внутренние ошибки сервера
- Telegram API ошибки

## Обработка ошибок

### Коды ответов
- `200` — успех
- `400` — неверный запрос (validation_error)
- `401` — не авторизован (invalid_token, token_expired)
- `403` — доступ запрещён (access_denied)
- `404` — не найдено (not_found)
- `422` — ошибка валидации
- `500` — внутренняя ошибка (internal_error)

### Формат ошибки
```json
{
  "code": 400,
  "status": "error",
  "message": "Человекочитаемое сообщение",
  "error": "machine_readable_code"
}
```

## Docker инфраструктура

### Контейнеры
- `nginx` — веб-сервер (80, 443)
- `php-fpm` — обработка PHP
- `php-cli` — консольные команды
- `postgres` — база данных (5432)
- `pgadmin` — управление БД (5050)

### Volumes
- `postgres_data` — персистентные данные БД
- Монтирование проекта в `/var/www`

### Networks
- `slim_network` — внутренняя сеть

## Безопасность

### Защита данных
- Пароли хешируются через `password_hash()` (bcrypt)
- JWT секреты в `.env` (не в коде)
- HTTPS обязателен в продакшене
- CORS настроен для фронтенда

### Валидация
- Все входные данные валидируются в контроллерах
- Приватные методы `validate*Data()`
- Строгая типизация PHP 8.3

### Права доступа
- AuthMiddleware проверяет JWT
- Проверка роли в админ-эндпоинтах
- user_id из токена, а не из запроса

## Производительность

### Оптимизации
- Eager loading связей через `with()`
- Индексы на внешних ключах
- PostGIS для геопространственных запросов
- Docker volumes для быстрого I/O

### Кэширование
- Opcache для PHP (в php-fpm)
- Eloquent query cache (при необходимости)

## API Документация

### Спецификация OpenAPI
Проект использует OpenAPI 3.0 для документирования всех API эндпоинтов.

**Файлы:**
- `docs/api/openapi.yaml` — спецификация всех эндпоинтов
- `docs/api/redoc.html` — интерактивная документация (Redoc)

### Запуск документации
```bash
cd docs/api && python3 -m http.server 8080
```

Документация будет доступна по адресу: http://localhost:8080/redoc.html

### Структура документации
- Описание всех эндпоинтов (публичных, защищённых, административных)
- Форматы запросов и ответов
- Коды ошибок
- Примеры использования
- Схемы данных

### Обновление документации
При добавлении новых эндпоинтов обязательно обновляйте `openapi.yaml`:
1. Добавьте путь в секцию `paths`
2. Определите request/response schemas
3. Укажите необходимые security схемы (Bearer токен)
4. Добавьте примеры запросов/ответов

## Расширение проекта

### Добавление нового эндпоинта
1. Создать контроллер в `src/Controllers/`
2. Создать сервис в `src/Services/`
3. Зарегистрировать сервис в `bootstrap/container.php`
4. Добавить маршрут в `routes/routes.php`
5. Добавить middleware если нужна защита
6. **Обновить `docs/api/openapi.yaml`**

### Добавление новой модели
1. Создать миграцию в `db/migrations/`
2. Создать модель в `src/Models/`
3. Определить fillable, casts, hidden
4. Добавить PHPDoc с @property
5. Определить relations

### Добавление middleware
1. Создать класс в `src/Middleware/`
2. Реализовать `MiddlewareInterface`
3. Применить к группе маршрутов через `->add()`
