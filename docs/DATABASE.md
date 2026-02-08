# Схема базы данных First Call

**Дата обновления:** 8 февраля 2026

## Обзор

База данных PostgreSQL 15 с расширением PostGIS для геопространственных запросов.

**Подключение:**
- Host: `postgres` (Docker) / `localhost` (хост)
- Port: `5432`
- Database: `slim_api`
- Username: `postgres`
- Password: `postgres`

---

## ER-диаграмма (упрощённая)

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│     users       │────<│  user_settings  │     │    sources      │
└────────┬────────┘     └─────────────────┘     └────────┬────────┘
         │                                               │
         │     ┌─────────────────┐                       │
         ├────<│  user_sources   │>──────────────────────┘
         │     └─────────────────┘
         │
         │     ┌─────────────────┐     ┌─────────────────┐
         ├────<│user_subscriptions│>───<│    tariffs      │
         │     └────────┬────────┘     └─────────────────┘
         │              │                       │
         │              │              ┌────────┴────────┐
         │              │              │  tariff_prices  │>──┐
         │              │              └─────────────────┘   │
         │              │                                    │
         │     ┌────────┴────────┐     ┌─────────────────┐   │
         │     │subscription_    │     │   locations     │<──┘
         │     │    history      │     └────────┬────────┘
         │     └─────────────────┘              │
         │                                      │
         │     ┌─────────────────┐     ┌────────┴────────┐
         ├────<│user_location_   │>───<│   categories    │
         │     │   polygons      │     └─────────────────┘
         │     └─────────────────┘
         │
         │     ┌─────────────────┐
         ├────<│ refresh_tokens  │
         │     └─────────────────┘
         │
         │     ┌─────────────────┐     ┌─────────────────┐
         ├────<│ agent_listings  │>───<│    listings     │
         │     └─────────────────┘     └────────┬────────┘
         │                                      │
         │     ┌─────────────────┐     ┌────────┴────────┐
         ├────<│ user_favorites  │>────│  listing_metro  │>──┐
         │     └────────┬────────┘     └─────────────────┘   │
         │              │                                    │
         │     ┌────────┴────────┐     ┌─────────────────┐   │
         └────<│user_favorite_   │     │ metro_stations  │<──┘
               │   statuses      │     └─────────────────┘
               └─────────────────┘
```

---

## Таблицы

### 1. users — Пользователи

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR | Имя пользователя |
| `password_hash` | VARCHAR | Хеш пароля (bcrypt) |
| `telegram_id` | VARCHAR UNIQUE | ID в Telegram |
| `telegram_username` | VARCHAR NULL | Username в Telegram |
| `telegram_photo_url` | VARCHAR NULL | URL аватара |
| `telegram_auth_date` | INT NULL | Дата авторизации через Telegram |
| `telegram_hash` | VARCHAR NULL | Хеш авторизации Telegram |
| `phone` | VARCHAR NULL | Телефон |
| `phone_status` | BOOLEAN | Статус верификации телефона |
| `app_connected` | BOOLEAN | Подключено ли приложение (WebSocket) |
| `app_last_ping_at` | TIMESTAMP NULL | Время последнего пинга от приложения |
| `role` | VARCHAR | Роль: `user`, `admin` |
| `is_trial_used` | BOOLEAN | Использован ли демо-период |
| `telegram_bot_blocked` | BOOLEAN | Заблокировал ли бота |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Индексы:** `telegram_id` (UNIQUE)

---

### 2. user_settings — Настройки пользователя

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK UNIQUE | Ссылка на users |
| `log_events` | BOOLEAN | Логировать события |
| `auto_call` | BOOLEAN | Автозвонок включён |
| `auto_call_raised` | BOOLEAN | Автозвонок на поднятые |
| `telegram_notifications` | BOOLEAN | Уведомления в Telegram |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:** `user_id` → `users(id)` ON DELETE CASCADE

---

### 3. sources — Источники объявлений

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR | Название источника |
| `is_active` | BOOLEAN | Активен ли источник |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Начальные данные:**
- Авито
- Яндекс.Н
- Циан
- ЮЛА

---

### 4. user_sources — Источники пользователя (pivot)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `user_id` | INT FK | Ссылка на users |
| `source_id` | INT FK | Ссылка на sources |
| `enabled` | BOOLEAN | Включён ли источник |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**PK:** (`user_id`, `source_id`)

**FK:**
- `user_id` → `users(id)` ON DELETE CASCADE
- `source_id` → `sources(id)` ON DELETE CASCADE

---

### 5. categories — Категории недвижимости

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR | Название категории |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Начальные данные:**
1. Аренда жилая (Квартиры)
2. Аренда (Коммерческая недвижимость)
3. Продажа жилая (Квартиры)
4. Продажа (Коммерческая недвижимость)

---

### 6. locations — Локации (регионы/города)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `city` | VARCHAR | Город |
| `region` | VARCHAR | Регион/область |
| `center_lat` | DECIMAL(10,6) | Широта центра |
| `center_lng` | DECIMAL(10,6) | Долгота центра |
| `bounds` | JSON | Границы {north, east, south, west} |
| `center_point` | GEOMETRY(Point) | PostGIS точка центра |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Начальные данные:** 19 городов (Москва, СПб, Новосибирск и др.)

---

### 7. cities — Детальные города/районы

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(100) | Название |
| `city_parent_id` | INT | ID родительского города |
| `location_parent_id` | INT FK | Ссылка на locations |
| `lat` | DECIMAL(10,8) | Широта |
| `lng` | DECIMAL(11,8) | Долгота |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:** `location_parent_id` → `locations(id)` ON DELETE CASCADE

**UNIQUE:** (`name`, `city_parent_id`)

---

### 8. tariffs — Тарифы

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR | Название тарифа |
| `code` | VARCHAR | Код: `demo`, `premium` |
| `duration_hours` | INT | Длительность в часах |
| `price` | DECIMAL(10,2) | Базовая цена |
| `description` | TEXT NULL | Описание |
| `is_active` | BOOLEAN | Активен ли тариф |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Начальные данные:**
- Demo: 3 часа, 0₽
- Premium: 744 часа (31 день), 5000₽

---

### 9. tariff_prices — Цены тарифов по локациям и категориям

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `tariff_id` | INT FK | Ссылка на tariffs |
| `location_id` | INT FK | Ссылка на locations |
| `category_id` | INT FK NULL | Ссылка на categories |
| `price` | DECIMAL(10,2) | Цена для комбинации |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `tariff_id` → `tariffs(id)` ON DELETE CASCADE
- `location_id` → `locations(id)` ON DELETE CASCADE
- `category_id` → `categories(id)` ON DELETE CASCADE

**UNIQUE:** (`tariff_id`, `location_id`, `category_id`)

**Примечание:** Цена зависит от комбинации тариф + локация + категория. Например, Москва + Аренда жилая = 10000₽, остальные = 5000₽.

---

### 10. user_subscriptions — Подписки пользователей

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users |
| `tariff_id` | INT FK | Ссылка на tariffs |
| `category_id` | INT FK | Ссылка на categories |
| `location_id` | INT FK | Ссылка на locations |
| `price_paid` | DECIMAL(10,2) | Уплаченная цена |
| `start_date` | TIMESTAMP NULL | Дата начала |
| `end_date` | TIMESTAMP NULL | Дата окончания |
| `status` | VARCHAR | Статус: `pending`, `active`, `extend_pending`, `expired`, `cancelled` |
| `is_enabled` | BOOLEAN | Временно отключена |
| `payment_method` | VARCHAR NULL | Способ оплаты |
| `admin_notes` | VARCHAR NULL | Заметки админа |
| `approved_by` | INT NULL | ID админа |
| `approved_at` | TIMESTAMP NULL | Дата одобрения |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**Статусы:**
- `pending` — ожидает подтверждения админом
- `active` — активна, пользователь имеет доступ
- `extend_pending` — ожидает продления (подписка работает, заявка отправлена)
- `expired` — истекла
- `cancelled` — отменена

**FK:**
- `user_id` → `users(id)` ON DELETE CASCADE
- `tariff_id` → `tariffs(id)` ON DELETE CASCADE
- `category_id` → `categories(id)` ON DELETE CASCADE
- `location_id` → `locations(id)` ON DELETE CASCADE

**UNIQUE:** (`user_id`, `category_id`, `location_id`, `status`)

---

### 11. subscription_history — История подписок

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users |
| `subscription_id` | INT FK NULL | Ссылка на user_subscriptions |
| `action` | VARCHAR | Действие: `created`, `expired`, `cancelled`, `renewed` |
| `tariff_name` | VARCHAR | Название тарифа |
| `category_name` | VARCHAR | Название категории |
| `location_name` | VARCHAR | Название локации |
| `price_paid` | DECIMAL(10,2) | Уплаченная цена |
| `action_date` | TIMESTAMP | Дата действия |
| `notes` | TEXT NULL | Примечания |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `user_id` → `users(id)` ON DELETE CASCADE
- `subscription_id` → `user_subscriptions(id)` ON DELETE SET NULL

---

### 12. refresh_tokens — JWT Refresh токены

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users |
| `token` | VARCHAR(255) | Токен |
| `device_type` | VARCHAR(20) | Тип устройства: `web`, `mobile` |
| `expires_at` | TIMESTAMP | Дата истечения |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:** `user_id` → `users(id)` ON DELETE CASCADE

**UNIQUE:** (`user_id`, `device_type`)

---

### 13. user_location_polygons — Пользовательские полигоны

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users |
| `subscription_id` | INT FK | Ссылка на user_subscriptions |
| `name` | VARCHAR | Название локации |
| `polygon_coordinates` | JSON | Координаты в GeoJSON |
| `center_lat` | DECIMAL(10,6) | Широта центра |
| `center_lng` | DECIMAL(10,6) | Долгота центра |
| `bounds` | JSON NULL | Границы |
| `polygon` | GEOMETRY(Polygon) | PostGIS полигон |
| `center_point` | GEOMETRY(Point) | PostGIS точка центра |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `user_id` → `users(id)` ON DELETE CASCADE
- `subscription_id` → `user_subscriptions(id)` ON DELETE CASCADE

**Индексы:** GIST на `polygon`, `center_point`

---

### 14. listing_statuses — Статусы объявлений

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(50) UNIQUE | Название статуса |
| `color` | VARCHAR(20) | Цвет для UI |
| `sort_order` | SMALLINT | Порядок сортировки |

**Начальные данные:**
- Новое (#4CAF50)
- Поднятое (#2196F3)

---

### 15. call_statuses — Статусы звонков

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(100) | Название статуса |
| `color` | VARCHAR(20) | Цвет для UI |
| `sort_order` | SMALLINT | Порядок сортировки |

**Начальные данные:**
- Наша квартира (#4CAF50)
- Не дозвонился (#FFC107)
- Не снял (#FF9800)
- Агент (#F44336)
- Не первые (#9C27B0)
- Звонок (#2196F3)

---

### 16. metro_stations — Станции метро

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `location_id` | INT FK | Ссылка на locations (город) |
| `name` | VARCHAR(100) | Название станции |
| `line` | VARCHAR(100) NULL | Линия метро |
| `line_id` | VARCHAR(20) NULL | ID линии из API hh.ru |
| `station_id` | VARCHAR(20) NULL | ID станции из API hh.ru |
| `color` | VARCHAR(20) NULL | Цвет линии |
| `lat` | DECIMAL(10,8) | Широта |
| `lng` | DECIMAL(11,8) | Долгота |

**FK:** `location_id` → `locations(id)` ON DELETE CASCADE

**UNIQUE:** (`location_id`, `name`, `line`)

**Индексы:** `station_id`

---

### 17. listings — Объявления

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `source_id` | INT FK | Ссылка на sources |
| `category_id` | INT FK | Ссылка на categories |
| `listing_status_id` | INT FK | Ссылка на listing_statuses |
| `city_id` | INT FK NULL | Ссылка на cities |
| `external_id` | VARCHAR(100) | ID во внешней системе |
| `title` | VARCHAR NULL | Заголовок |
| `description` | TEXT NULL | Описание |
| `rooms` | SMALLINT NULL | Количество комнат |
| `price` | DECIMAL(12,2) NULL | Цена |
| `price_history` | JSONB NULL | История изменения цен |
| `square_meters` | DECIMAL(8,2) NULL | Площадь |
| `floor` | SMALLINT NULL | Этаж |
| `floors_total` | SMALLINT NULL | Всего этажей |
| `phone` | VARCHAR(20) NULL | Телефон |
| `phone_unavailable` | BOOLEAN | Телефон недоступен (только звонки через приложение) |
| `city` | VARCHAR(100) NULL | Город (текст) |
| `street` | VARCHAR(150) NULL | Улица |
| `building` | VARCHAR(20) NULL | Номер дома |
| `address` | VARCHAR(255) NULL | Полный адрес |
| `url` | VARCHAR(255) NULL | URL объявления |
| `lat` | DECIMAL(10,8) NULL | Широта |
| `lng` | DECIMAL(11,8) NULL | Долгота |
| `is_promoted` | BOOLEAN | Поднятое |
| `is_paid` | BOOLEAN | Платное |
| `promoted_at` | TIMESTAMP NULL | Дата поднятия |
| `auto_call_processed_at` | TIMESTAMP NULL | Дата автозвонка |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |
| `deleted_at` | TIMESTAMP NULL | Soft delete |

**Формат price_history:**
```json
[
  {"price": 50000, "date": "2026-02-01"},
  {"price": 45000, "date": "2026-02-05"}
]
```

**FK:**
- `source_id` → `sources(id)`
- `category_id` → `categories(id)`
- `listing_status_id` → `listing_statuses(id)`
- `city_id` → `cities(id)` ON DELETE SET NULL

**UNIQUE:** (`source_id`, `external_id`)

**Индексы:**
- (`listing_status_id`, `created_at`)
- (`is_promoted`, `promoted_at`)
- (`is_paid`)
- (`auto_call_processed_at`)
- (`category_id`, `created_at`)

---

### 18. listing_metro — Связь объявлений с метро

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `listing_id` | INT FK | Ссылка на listings |
| `metro_station_id` | INT FK | Ссылка на metro_stations |
| `travel_time_min` | SMALLINT NULL | Время до метро (мин) |
| `distance` | VARCHAR(50) NULL | Расстояние до метро ("900 м", "2,7 км") |
| `travel_type` | VARCHAR(20) | Тип: `walk`, `car`, `public_transport` |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `listing_id` → `listings(id)` ON DELETE CASCADE
- `metro_station_id` → `metro_stations(id)` ON DELETE CASCADE

**UNIQUE:** (`listing_id`, `metro_station_id`)

---

### 19. user_favorites — Избранные объявления пользователей

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users |
| `listing_id` | INT FK | Ссылка на listings |
| `comment` | VARCHAR(250) NULL | Комментарий пользователя |
| `status_id` | INT FK NULL | Ссылка на user_favorite_statuses |
| `created_at` | TIMESTAMP | Дата добавления в избранное |

**FK:**
- `user_id` → `users(id)` ON DELETE CASCADE
- `listing_id` → `listings(id)` ON DELETE CASCADE
- `status_id` → `user_favorite_statuses(id)` ON DELETE SET NULL

**UNIQUE:** (`user_id`, `listing_id`)

**Индексы:**
- `user_id`
- `listing_id`
- `status_id`

---

### 20. user_favorite_statuses — Пользовательские статусы избранного

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users |
| `name` | VARCHAR(50) | Название статуса |
| `color` | VARCHAR(7) | Цвет в HEX формате (#RRGGBB) |
| `sort_order` | SMALLINT | Порядок сортировки |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:** `user_id` → `users(id)` ON DELETE CASCADE

**UNIQUE:** (`user_id`, `name`)

**Индексы:** `user_id`

---

### 21. agent_listings — Объявления агентов

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT FK | Ссылка на users (агент) |
| `listing_id` | INT FK | Ссылка на listings |
| `call_status_id` | INT FK NULL | Ссылка на call_statuses |
| `notes` | TEXT NULL | Заметки агента |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `user_id` → `users(id)` ON DELETE CASCADE
- `listing_id` → `listings(id)` ON DELETE CASCADE
- `call_status_id` → `call_statuses(id)`

**UNIQUE:** (`user_id`, `listing_id`)

**Индексы:**
- (`user_id`, `call_status_id`)
- (`listing_id`, `call_status_id`)

---

### 22. listing_photo_tasks — Задачи парсинга фото (устаревшая)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT PK FK | ID = listings.id |
| `source_id` | INT FK | Ссылка на sources |
| `url` | VARCHAR(1000) | URL объявления |
| `status` | VARCHAR(20) | Статус: `pending`, `processing`, `completed`, `failed` |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `id` → `listings(id)` ON DELETE CASCADE
- `source_id` → `sources(id)`

**Индексы:** `status`, `created_at`, `source_id`

---

### 23. photo_tasks — Задачи обработки фото (удаление водяных знаков)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `listing_id` | BIGINT FK UNIQUE | Ссылка на listings (одна задача на объявление) |
| `source_id` | TINYINT | ID источника |
| `external_id` | VARCHAR(50) | ID объявления на источнике |
| `url` | VARCHAR(500) | URL объявления |
| `status` | ENUM | Статус: `pending`, `processing`, `completed`, `failed` |
| `error_message` | VARCHAR(500) NULL | Сообщение об ошибке |
| `photos_count` | SMALLINT | Количество обработанных фото |
| `archive_path` | VARCHAR(255) NULL | Путь к архиву с фото |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:** `listing_id` → `listings(id)` ON DELETE CASCADE

**Индексы:** (`status`, `created_at`)

---

### 24. location_proxies — Прокси для парсинга

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `proxy` | VARCHAR(255) | Прокси ip:port |
| `source_id` | INT FK | Ссылка на sources |
| `location_id` | INT FK | Ссылка на locations |
| `category_id` | INT FK | Ссылка на categories |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `source_id` → `sources(id)`
- `location_id` → `locations(id)`
- `category_id` → `categories(id)`

**UNIQUE:** (`proxy`, `source_id`, `location_id`, `category_id`)

---

### 25. cian_auth — Авторизация ЦИАН

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `login` | VARCHAR(255) | Логин |
| `password` | VARCHAR(255) NULL | Пароль |
| `auth_token` | TEXT | Токен авторизации |
| `is_active` | BOOLEAN | Активна ли запись |
| `last_used_at` | TIMESTAMP NULL | Последнее использование |
| `comment` | VARCHAR(500) NULL | Комментарий |
| `location_id` | INT FK | Ссылка на locations |
| `category_id` | INT FK | Ссылка на categories |
| `created_at` | TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP | Дата обновления |

**FK:**
- `location_id` → `locations(id)`
- `category_id` → `categories(id)`

**UNIQUE:** (`location_id`, `category_id`)

---

## Миграции

Миграции находятся в `db/migrations/` и запускаются через:

```bash
make migrate
# или
docker exec -it slim_php-cli php db/migrations/run.php
```

**Порядок миграций:**
1. `20230101000001` — users
2. `20230101000002` — sources
3. `20230101000003` — categories
4. `20230101000004` — user_settings
5. `20230101000005` — user_sources
6. `20230101000007` — refresh_tokens
7. `20240320000001` — tariffs
8. `20240522000001` — telegram_bot_blocked (alter users)
9. `20240526000001` — locations
10. `20240526000002` — tariff_prices
11. `20240526000003` — user_subscriptions
12. `20240526000004` — subscription_history
13. `20240701000001` — user_location_polygons
14. `20240701000002` — coordinates to locations
15. `20240701000003` — PostGIS fields
16. `20240801000001` — fill location coordinates
17. `20240915000001` — update tariffs structure
18. `20240920000001` — fix subscription constraint
19. `20241001000001` — listing_statuses
20. `20241001000002` — call_statuses
21. `20241001000003` — metro_stations
22. `20241001000004` — listings
23. `20241001000005` — listing_metro
24. `20241001000006` — agent_listings
25. `20241001000009` — listing_photo_tasks
26. `20241201000001` — cities
27. `20241201000002` — city_id to listings
28. `20241201000003` — fill cities
29. `20241201000004` — location_proxies
30. `20241201000005` — cian_auth
31. `20260128000001` — user_favorites
32. `20260128000002` — add comment to user_favorites
33. `20260128000003` — user_favorite_statuses
34. `20260128000004` — add status_id to user_favorites
35. `20260128000005` — add category_id to tariff_prices
36. `20260201000001` — add price_history to listings
37. `20260202000001` — add hh_ids to metro_stations
38. `20260202000002` — add metro_fields to listings (phone_unavailable)
39. `20260202000003` — move metro_info to listing_metro (distance)
40. `20260206000001` — photo_tasks

---

## PostGIS

Расширение PostGIS используется для геопространственных запросов.

**Типы данных:**
- `GEOMETRY(Point, 4326)` — точка (SRID 4326 = WGS84)
- `GEOMETRY(Polygon, 4326)` — полигон

**Индексы:**
- GIST индексы для быстрого поиска

**Примеры запросов:**

```sql
-- Найти объявления в полигоне пользователя
SELECT l.* FROM listings l
JOIN user_location_polygons ulp ON ST_Contains(ulp.polygon, ST_SetSRID(ST_Point(l.lng, l.lat), 4326))
WHERE ulp.user_id = 1;

-- Расстояние между точками
SELECT ST_Distance(
  ST_SetSRID(ST_Point(lng1, lat1), 4326)::geography,
  ST_SetSRID(ST_Point(lng2, lat2), 4326)::geography
) AS distance_meters;
```
