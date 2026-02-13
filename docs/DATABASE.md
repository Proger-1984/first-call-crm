# Схема базы данных First Call

**Дата обновления:** 14 февраля 2026

## Обзор

База данных PostgreSQL 15 с расширением PostGIS для геопространственных запросов.

**Подключение:**
- Host: `postgres` (Docker) / `localhost` (хост)
- Port: `5432`
- Database: `slim_api`
- Username: `postgres`
- Password: `postgres`

**Всего таблиц:** 37 (не считая `spatial_ref_sys`)

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
         │     └────────┬────────┘     └────────┬────────┘
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
         │     │   polygons      │     └────────┬────────┘
         │     └─────────────────┘              │
         │                              ┌───────┴────────┐
         │                              │ category_rooms │>──┐
         │                              └────────────────┘   │
         │                                                   │
         │     ┌─────────────────┐     ┌─────────────────┐   │
         ├────<│ refresh_tokens  │     │     rooms       │<──┘
         │     └─────────────────┘     └─────────────────┘
         │
         │     ┌─────────────────┐     ┌─────────────────┐
         ├────<│ agent_listings  │>───<│    listings     │>──┐
         │     └─────────────────┘     └────────┬────────┘   │
         │                                      │            │
         │     ┌─────────────────┐     ┌────────┴────────┐   │
         ├────<│ user_favorites  │>────│  listing_metro  │>──┤
         │     └────────┬────────┘     └─────────────────┘   │
         │              │                                    │
         │     ┌────────┴────────┐     ┌─────────────────┐   │
         ├────<│user_favorite_   │     │ metro_stations  │<──┘
         │     │   statuses      │     └─────────────────┘
         │     └─────────────────┘
         │
         │     ┌─────────────────┐
         ├────<│user_source_     │
         │     │   cookies       │
         │     └─────────────────┘
         │
```

---

## Таблицы

### 1. users — Пользователи

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(255) NOT NULL | Имя пользователя |
| `password_hash` | VARCHAR(255) NOT NULL | Хеш пароля (bcrypt) |
| `telegram_id` | VARCHAR(255) NOT NULL UNIQUE | ID в Telegram |
| `telegram_username` | VARCHAR(255) NULL | Username в Telegram |
| `telegram_photo_url` | VARCHAR(255) NULL | URL аватара |
| `telegram_auth_date` | INT NULL | Дата авторизации через Telegram |
| `telegram_hash` | VARCHAR(255) NULL | Хеш авторизации Telegram |
| `phone` | VARCHAR(255) NULL | Телефон |
| `phone_status` | BOOLEAN NOT NULL DEFAULT false | Статус верификации телефона |
| `role` | VARCHAR(255) NOT NULL DEFAULT 'user' | Роль: `user`, `admin` |
| `is_trial_used` | BOOLEAN NOT NULL DEFAULT false | Использован ли демо-период |
| `telegram_bot_blocked` | BOOLEAN NOT NULL DEFAULT false | Заблокировал ли бота |
| `app_connected` | BOOLEAN NOT NULL DEFAULT false | Подключено ли приложение (WebSocket) |
| `app_last_ping_at` | TIMESTAMP NULL | Время последнего пинга от приложения |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Индексы:** `users_telegram_id_unique` (UNIQUE on `telegram_id`)

---

### 2. user_settings — Настройки пользователя

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users |
| `log_events` | BOOLEAN NOT NULL DEFAULT false | Логировать события |
| `auto_call` | BOOLEAN NOT NULL DEFAULT false | Автозвонок включён |
| `auto_call_raised` | BOOLEAN NOT NULL DEFAULT false | Автозвонок на поднятые |
| `telegram_notifications` | BOOLEAN NOT NULL DEFAULT false | Уведомления в Telegram |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `user_id` -> `users(id)`

**Индексы:** `user_settings_user_id_unique` (UNIQUE on `user_id`)

---

### 3. sources — Источники объявлений

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(255) NOT NULL | Название источника |
| `is_active` | BOOLEAN NOT NULL DEFAULT true | Активен ли источник |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Начальные данные:**
1. Авито
2. Яндекс.Н
3. Циан
4. ЮЛА

---

### 4. user_sources — Источники пользователя (pivot, composite PK)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `user_id` | INT NOT NULL FK | Ссылка на users |
| `source_id` | INT NOT NULL FK | Ссылка на sources |
| `enabled` | BOOLEAN NOT NULL DEFAULT true | Включён ли источник |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**PK:** (`user_id`, `source_id`)

**FK:**
- `user_id` -> `users(id)`
- `source_id` -> `sources(id)`

---

### 5. categories — Категории недвижимости

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(255) NOT NULL | Название категории |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Начальные данные:**
1. Аренда жилая (Квартиры)
2. Аренда (Коммерческая недвижимость)
3. Продажа жилая (Квартиры)
4. Продажа (Коммерческая недвижимость)

---

### 6. rooms — Типы комнат

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(50) NOT NULL | Название (напр. "Студия", "1-комн") |
| `code` | VARCHAR(20) NOT NULL UNIQUE | Код (напр. "studio", "1", "2", "5+", "free") |
| `sort_order` | SMALLINT NOT NULL DEFAULT 0 | Порядок сортировки |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Индексы:** `rooms_code_unique` (UNIQUE on `code`)

**Начальные данные:**
1. Студия (studio)
2. 1-комн (1)
3. 2-комн (2)
4. 3-комн (3)
5. 4-комн (4)
6. 5+ комн (5+)
7. Свободная планировка (free)

---

### 7. category_rooms — Связь категорий и комнат (pivot)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `category_id` | INT NOT NULL FK | Ссылка на categories |
| `room_id` | INT NOT NULL FK | Ссылка на rooms |

**FK:**
- `category_id` -> `categories(id)`
- `room_id` -> `rooms(id)`

**Индексы:** `category_rooms_category_id_room_id_unique` (UNIQUE on `category_id`, `room_id`)

---

### 8. locations — Локации (регионы/города)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `city` | VARCHAR(255) NOT NULL | Город |
| `region` | VARCHAR(255) NOT NULL | Регион/область |
| `center_lat` | NUMERIC NULL | Широта центра |
| `center_lng` | NUMERIC NULL | Долгота центра |
| `bounds` | JSON NULL | Границы {north, east, south, west} |
| `center_point` | GEOMETRY NULL | PostGIS точка центра |
| `bounds_polygon` | GEOMETRY NULL | PostGIS полигон границ |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Индексы:**
- GIST on `center_point`
- GIST on `bounds_polygon`

**Начальные данные:** 19 городов (Москва, Санкт-Петербург, Новосибирск, Екатеринбург, Казань, Красноярск, Нижний Новгород, Челябинск, Уфа, Самара, Ростов-на-Дону, Краснодар, Омск, Воронеж, Пермь, Волгоград, Саратов, Тюмень, Тверь)

---

### 9. cities — Детальные города/районы

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(100) NOT NULL | Название |
| `city_parent_id` | INT NOT NULL | ID родительского города |
| `location_parent_id` | INT NOT NULL FK | Ссылка на locations |
| `lat` | NUMERIC NULL | Широта |
| `lng` | NUMERIC NULL | Долгота |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `location_parent_id` -> `locations(id)`

**Индексы:**
- `cities_name_city_parent_id_unique` (UNIQUE on `name`, `city_parent_id`)
- `cities_location_parent_id_index`
- `cities_city_parent_id_index`
- `cities_location_parent_id_city_parent_id_index`

---

### 10. tariffs — Тарифы

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(255) NOT NULL | Название тарифа |
| `code` | VARCHAR(255) NOT NULL | Код тарифа |
| `duration_hours` | INT NOT NULL | Длительность в часах |
| `price` | NUMERIC NOT NULL | Базовая цена |
| `description` | TEXT NULL | Описание |
| `is_active` | BOOLEAN NOT NULL DEFAULT true | Активен ли тариф |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Начальные данные:**

| id | name | code | duration_hours | price |
|----|------|------|----------------|-------|
| 1 | Демо | demo | 3 | 0 |
| 2 | Премиум 31 день | premium_31 | 744 | 5000 |

---

### 11. tariff_prices — Цены тарифов по локациям и категориям

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `tariff_id` | INT NOT NULL FK | Ссылка на tariffs |
| `location_id` | INT NOT NULL FK | Ссылка на locations |
| `price` | NUMERIC NOT NULL | Цена для комбинации |
| `category_id` | INT NULL FK | Ссылка на categories |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `tariff_id` -> `tariffs(id)`
- `location_id` -> `locations(id)`
- `category_id` -> `categories(id)`

**Индексы:** `tariff_prices_unique` (UNIQUE on `tariff_id`, `location_id`, `category_id`)

**Примечание:** Цена зависит от комбинации тариф + локация + категория. Например, Москва + Аренда жилая = 10000, остальные = 5000.

---

### 12. user_subscriptions — Подписки пользователей

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users |
| `tariff_id` | INT NOT NULL FK | Ссылка на tariffs |
| `category_id` | INT NOT NULL FK | Ссылка на categories |
| `location_id` | INT NOT NULL FK | Ссылка на locations |
| `price_paid` | NUMERIC NOT NULL | Уплаченная цена |
| `start_date` | TIMESTAMP NULL | Дата начала |
| `end_date` | TIMESTAMP NULL | Дата окончания |
| `status` | VARCHAR(255) NOT NULL DEFAULT 'pending' | Статус подписки |
| `is_enabled` | BOOLEAN NOT NULL DEFAULT true | Временно отключена |
| `payment_method` | VARCHAR(255) NULL | Способ оплаты |
| `admin_notes` | VARCHAR(255) NULL | Заметки админа |
| `approved_by` | INT NULL | ID админа |
| `approved_at` | TIMESTAMP NULL | Дата одобрения |
| `notified_expiring_3d_at` | TIMESTAMP NULL | Уведомление за 3 дня (премиум) |
| `notified_expiring_1d_at` | TIMESTAMP NULL | Уведомление за 1 день (премиум) |
| `notified_expiring_1h_at` | TIMESTAMP NULL | Уведомление за 1 час (демо) |
| `notified_expiring_15m_at` | TIMESTAMP NULL | Уведомление за 15 минут (демо) |
| `notified_expired_at` | TIMESTAMP NULL | Уведомление об истечении |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Статусы:**
- `pending` — ожидает подтверждения админом
- `active` — активна, пользователь имеет доступ
- `extend_pending` — ожидает продления (подписка работает, заявка отправлена)
- `expired` — истекла
- `cancelled` — отменена

**Поля уведомлений:**
Используются для предотвращения дубликатов уведомлений. При отправке уведомления записывается timestamp. При продлении подписки все поля сбрасываются в NULL.

**FK:**
- `user_id` -> `users(id)`
- `tariff_id` -> `tariffs(id)`
- `category_id` -> `categories(id)`
- `location_id` -> `locations(id)`

**Индексы:** `unique_subscription` (UNIQUE on `user_id`, `category_id`, `location_id` WHERE `status` IN ('active', 'pending'))

---

### 13. subscription_history — История подписок

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users |
| `subscription_id` | INT NULL FK | Ссылка на user_subscriptions |
| `action` | VARCHAR(255) NOT NULL | Действие: `created`, `expired`, `cancelled`, `renewed` |
| `tariff_name` | VARCHAR(255) NOT NULL | Название тарифа |
| `category_name` | VARCHAR(255) NOT NULL | Название категории |
| `location_name` | VARCHAR(255) NOT NULL | Название локации |
| `price_paid` | NUMERIC NOT NULL | Уплаченная цена |
| `action_date` | TIMESTAMP NOT NULL | Дата действия |
| `notes` | TEXT NULL | Примечания |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `user_id` -> `users(id)`
- `subscription_id` -> `user_subscriptions(id)`

---

### 14. refresh_tokens — JWT Refresh токены

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users |
| `token` | VARCHAR(255) NOT NULL | Токен |
| `device_type` | VARCHAR(20) NOT NULL | Тип устройства: `web`, `mobile` |
| `expires_at` | TIMESTAMP NOT NULL | Дата истечения |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `user_id` -> `users(id)`

**Индексы:** `refresh_tokens_user_id_device_type_unique` (UNIQUE on `user_id`, `device_type`)

---

### 15. user_location_polygons — Пользовательские полигоны

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users |
| `subscription_id` | INT NOT NULL FK | Ссылка на user_subscriptions |
| `name` | VARCHAR(255) NOT NULL | Название локации |
| `polygon_coordinates` | JSON NOT NULL | Координаты в GeoJSON |
| `center_lat` | NUMERIC NULL | Широта центра |
| `center_lng` | NUMERIC NULL | Долгота центра |
| `bounds` | JSON NULL | Границы |
| `polygon` | GEOMETRY NULL | PostGIS полигон |
| `center_point` | GEOMETRY NULL | PostGIS точка центра |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `user_id` -> `users(id)`
- `subscription_id` -> `user_subscriptions(id)`

**Индексы:**
- GIST on `polygon`
- GIST on `center_point`

---

### 16. listing_statuses — Статусы объявлений

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(50) NOT NULL UNIQUE | Название статуса |
| `color` | VARCHAR(20) NULL | Цвет для UI |
| `sort_order` | SMALLINT NOT NULL DEFAULT 0 | Порядок сортировки |

**Начальные данные:**
1. Новое (#4CAF50)
2. Поднятое (#2196F3)

---

### 17. call_statuses — Статусы звонков

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `name` | VARCHAR(100) NOT NULL | Название статуса |
| `color` | VARCHAR(20) NULL | Цвет для UI |
| `sort_order` | SMALLINT NOT NULL DEFAULT 0 | Порядок сортировки |

**Начальные данные:**
1. Наша квартира (#4CAF50)
2. Не дозвонился (#FFC107)
3. Не снял (#FF9800)
4. Агент (#F44336)
5. Не первые (#9C27B0)
6. Звонок (#2196F3)

---

### 18. metro_stations — Станции метро

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `location_id` | INT NOT NULL FK | Ссылка на locations (город) |
| `name` | VARCHAR(100) NOT NULL | Название станции |
| `line` | VARCHAR(100) NULL | Линия метро |
| `color` | VARCHAR(20) NULL | Цвет линии |
| `lat` | NUMERIC NULL | Широта |
| `lng` | NUMERIC NULL | Долгота |
| `line_id` | VARCHAR(20) NULL | ID линии из API hh.ru |
| `station_id` | VARCHAR(20) NULL | ID станции из API hh.ru |

**FK:** `location_id` -> `locations(id)`

**Индексы:**
- `metro_stations_location_id_name_line_unique` (UNIQUE on `location_id`, `name`, `line`)
- `metro_stations_station_id_index`

---

### 19. listings — Объявления

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `source_id` | INT NOT NULL FK | Ссылка на sources |
| `category_id` | INT NOT NULL FK | Ссылка на categories |
| `listing_status_id` | INT NOT NULL DEFAULT 1 FK | Ссылка на listing_statuses |
| `external_id` | VARCHAR(100) NOT NULL | ID во внешней системе |
| `title` | VARCHAR(255) NULL | Заголовок |
| `description` | TEXT NULL | Описание |
| `room_id` | INT NULL FK | Ссылка на rooms |
| `price` | NUMERIC NULL | Цена |
| `square_meters` | NUMERIC NULL | Площадь |
| `floor` | SMALLINT NULL | Этаж |
| `floors_total` | SMALLINT NULL | Всего этажей |
| `phone` | VARCHAR(20) NULL | Телефон |
| `phone_unavailable` | BOOLEAN NOT NULL DEFAULT false | Телефон недоступен (только звонки через приложение) |
| `city` | VARCHAR(100) NULL | Город (текст) |
| `street` | VARCHAR(150) NULL | Улица |
| `house` | VARCHAR(20) NULL | Номер дома |
| `address` | VARCHAR(255) NULL | Полный адрес |
| `url` | VARCHAR(255) NULL | URL объявления |
| `lat` | NUMERIC NULL | Широта |
| `lng` | NUMERIC NULL | Долгота |
| `is_paid` | BOOLEAN NOT NULL DEFAULT false | Платное |
| `location_id` | INT NULL FK | Ссылка на locations |
| `point` | GEOMETRY NULL | PostGIS точка (координаты) |
| `price_history` | JSONB NULL | История изменения цен |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Формат price_history:**
```json
[
  {"price": 50000, "date": "2026-02-01"},
  {"price": 45000, "date": "2026-02-05"}
]
```

**FK:**
- `source_id` -> `sources(id)`
- `category_id` -> `categories(id)`
- `listing_status_id` -> `listing_statuses(id)`
- `room_id` -> `rooms(id)`
- `location_id` -> `locations(id)`

**Индексы:**
- `listings_source_id_external_id_unique` (UNIQUE on `source_id`, `external_id`)
- `idx_listings_point` (GIST on `point`)
- `listings_category_id_created_at_index`
- `listings_is_paid_index`
- `listings_listing_status_id_created_at_index`
- `listings_location_id_index`
- `listings_room_id_index`

---

### 20. listing_metro — Связь объявлений с метро

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `listing_id` | INT NOT NULL FK | Ссылка на listings |
| `metro_station_id` | INT NOT NULL FK | Ссылка на metro_stations |
| `travel_time_min` | SMALLINT NULL | Время до метро (мин) |
| `travel_type` | VARCHAR(20) NOT NULL DEFAULT 'walk' | Тип: `walk`, `car`, `public_transport` |
| `distance` | VARCHAR(50) NULL | Расстояние до метро ("900 м", "2,7 км") |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `listing_id` -> `listings(id)`
- `metro_station_id` -> `metro_stations(id)`

**Индексы:** `listing_metro_listing_id_metro_station_id_unique` (UNIQUE on `listing_id`, `metro_station_id`)

---

### 21. agent_listings — Объявления агентов

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users (агент) |
| `listing_id` | INT NOT NULL FK | Ссылка на listings |
| `call_status_id` | INT NULL FK | Ссылка на call_statuses |
| `notes` | TEXT NULL | Заметки агента |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `user_id` -> `users(id)`
- `listing_id` -> `listings(id)`
- `call_status_id` -> `call_statuses(id)`

**Индексы:**
- `agent_listings_user_id_listing_id_unique` (UNIQUE on `user_id`, `listing_id`)
- `agent_listings_user_id_call_status_id_index`
- `agent_listings_listing_id_call_status_id_index`

---

### 22. user_favorites — Избранные объявления пользователей

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users |
| `listing_id` | BIGINT NOT NULL FK | Ссылка на listings |
| `comment` | VARCHAR(250) NULL | Комментарий пользователя |
| `status_id` | BIGINT NULL FK | Ссылка на user_favorite_statuses |
| `created_at` | TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP | Дата добавления в избранное |

**FK:**
- `user_id` -> `users(id)`
- `listing_id` -> `listings(id)`
- `status_id` -> `user_favorite_statuses(id)`

**Индексы:**
- `user_favorites_user_listing_unique` (UNIQUE on `user_id`, `listing_id`)
- `user_favorites_user_id_index`
- `user_favorites_listing_id_index`
- `user_favorites_status_id_index`

---

### 23. user_favorite_statuses — Пользовательские статусы избранного

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users |
| `name` | VARCHAR(50) NOT NULL | Название статуса |
| `color` | VARCHAR(7) NOT NULL DEFAULT '#808080' | Цвет в HEX формате (#RRGGBB) |
| `sort_order` | SMALLINT NOT NULL DEFAULT 0 | Порядок сортировки |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `user_id` -> `users(id)`

**Индексы:**
- `user_favorite_statuses_user_name_unique` (UNIQUE on `user_id`, `name`)
- `user_favorite_statuses_user_id_index`

---

### 24. listing_photo_tasks — Задачи парсинга фото (устаревшая)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | INT PK FK | ID = listings.id |
| `source_id` | INT NOT NULL FK | Ссылка на sources |
| `url` | VARCHAR(1000) NOT NULL | URL объявления |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'pending' | Статус: `pending`, `processing`, `completed`, `failed` |
| `created_at` | TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `id` -> `listings(id)`
- `source_id` -> `sources(id)`

**Индексы:**
- `listing_photo_tasks_status_index`
- `listing_photo_tasks_created_at_index`
- `listing_photo_tasks_source_id_index`

---

### 25. photo_tasks — Задачи обработки фото (удаление водяных знаков)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `listing_id` | BIGINT NOT NULL UNIQUE FK | Ссылка на listings (одна задача на объявление) |
| `source_id` | SMALLINT NOT NULL | ID источника |
| `external_id` | VARCHAR(50) NOT NULL | ID объявления на источнике |
| `url` | VARCHAR(500) NOT NULL | URL объявления |
| `status` | VARCHAR(255) NOT NULL DEFAULT 'pending' | Статус: `pending`, `processing`, `completed`, `failed` |
| `error_message` | VARCHAR(500) NULL | Сообщение об ошибке |
| `photos_count` | SMALLINT NOT NULL DEFAULT 0 | Количество обработанных фото |
| `archive_path` | VARCHAR(255) NULL | Путь к архиву с фото |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `listing_id` -> `listings(id)`

**Индексы:**
- `photo_tasks_listing_id_unique` (UNIQUE on `listing_id`)
- `photo_tasks_status_created_at_index`

---

### 26. location_proxies — Прокси для парсинга

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `proxy` | VARCHAR(255) NOT NULL | Прокси ip:port |
| `source_id` | INT NOT NULL FK | Ссылка на sources |
| `location_id` | INT NOT NULL FK | Ссылка на locations |
| `category_id` | INT NOT NULL FK | Ссылка на categories |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `source_id` -> `sources(id)`
- `location_id` -> `locations(id)`
- `category_id` -> `categories(id)`

**Индексы:**
- `location_proxies_proxy_source_id_location_id_category_id_unique` (UNIQUE on `proxy`, `source_id`, `location_id`, `category_id`)
- `location_proxies_location_id_index`
- `location_proxies_source_id_index`
- `location_proxies_category_id_index`

---

### 27. cian_auth — Авторизация ЦИАН

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `login` | VARCHAR(255) NOT NULL | Логин |
| `password` | VARCHAR(255) NULL | Пароль |
| `auth_token` | TEXT NOT NULL | Токен авторизации |
| `is_active` | BOOLEAN NOT NULL DEFAULT true | Активна ли запись |
| `last_used_at` | TIMESTAMP NULL | Последнее использование |
| `comment` | VARCHAR(500) NULL | Комментарий |
| `location_id` | INT NOT NULL FK | Ссылка на locations |
| `category_id` | INT NOT NULL FK | Ссылка на categories |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `location_id` -> `locations(id)`
- `category_id` -> `categories(id)`

**Индексы:**
- `cian_auth_location_id_category_id_unique` (UNIQUE on `location_id`, `category_id`)
- `cian_auth_is_active_index`

---

### 28. user_source_cookies — Куки авторизации на источниках

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users |
| `source_type` | VARCHAR(255) NOT NULL DEFAULT 'cian' | Тип источника: `cian`, `avito` |
| `cookies` | TEXT NULL | Строка с куками |
| `is_valid` | BOOLEAN NOT NULL DEFAULT false | Валидны ли куки |
| `subscription_info` | JSONB NULL | Информация о подписке на источнике |
| `last_validated_at` | TIMESTAMP NULL | Когда последний раз проверяли |
| `expires_at` | TIMESTAMP NULL | Когда истекает подписка на источнике |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**Формат subscription_info:**
```json
{
  "status": "active",
  "tariff": "Премиум",
  "expire_text": "До 18 февраля",
  "limit_info": "50 из 100 контактов",
  "phone": "+7 999 123-45-67"
}
```

**FK:** `user_id` -> `users(id)`

**Индексы:** `user_source_cookies_user_id_source_type_unique` (UNIQUE on `user_id`, `source_type`)

---

### 29. pipeline_stages — Стадии воронки продаж

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users (владелец) |
| `name` | VARCHAR(100) NOT NULL | Название стадии |
| `color` | VARCHAR(7) DEFAULT '#808080' | Цвет в HEX |
| `sort_order` | SMALLINT DEFAULT 0 | Порядок сортировки |
| `is_system` | BOOLEAN DEFAULT false | Системная стадия (нельзя удалить) |
| `is_final` | BOOLEAN DEFAULT false | Финальная стадия (сделка/отказ) |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `user_id` -> `users(id)` ON DELETE CASCADE

**Индексы:**
- `pipeline_stages_user_name_unique` (UNIQUE on `user_id`, `name`)
- `pipeline_stages_user_sort_index` (`user_id`, `sort_order`)

**Стадии по умолчанию:** Новый лид, Первый контакт, Квалификация, Подбор объектов, Показ, Переговоры, Задаток, Сделка закрыта, Отказ

---

### 30. clients — Карточка клиента CRM

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `user_id` | INT NOT NULL FK | Ссылка на users (агент-владелец) |
| `pipeline_stage_id` | INT NOT NULL FK | Ссылка на pipeline_stages |
| `name` | VARCHAR(255) NOT NULL | ФИО клиента |
| `phone` | VARCHAR(20) NULL | Основной телефон |
| `phone_secondary` | VARCHAR(20) NULL | Дополнительный телефон |
| `email` | VARCHAR(255) NULL | Email |
| `telegram_username` | VARCHAR(100) NULL | Telegram username |
| `client_type` | VARCHAR(20) DEFAULT 'buyer' | buyer/seller/renter/landlord |
| `source_type` | VARCHAR(50) NULL | Откуда пришёл |
| `source_details` | VARCHAR(255) NULL | Детали источника |
| `budget_min` | NUMERIC NULL | Минимальный бюджет |
| `budget_max` | NUMERIC NULL | Максимальный бюджет |
| `comment` | TEXT NULL | Комментарий агента |
| `is_archived` | BOOLEAN DEFAULT false | В архиве |
| `last_contact_at` | TIMESTAMP NULL | Дата последнего контакта |
| `next_contact_at` | TIMESTAMP NULL | Дата следующего контакта |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `user_id` -> `users(id)` ON DELETE CASCADE
- `pipeline_stage_id` -> `pipeline_stages(id)` ON DELETE RESTRICT

**Индексы:**
- `clients_user_archived_index` (`user_id`, `is_archived`)
- `clients_user_stage_index` (`user_id`, `pipeline_stage_id`)
- `clients_user_type_index` (`user_id`, `client_type`)
- `clients_phone_index` (`phone`)

---

### 31. client_search_criteria — Критерии поиска клиента

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | SERIAL PK | Первичный ключ |
| `client_id` | INT NOT NULL FK | Ссылка на clients |
| `category_id` | INT NULL FK | Категория недвижимости |
| `location_id` | INT NULL FK | Локация |
| `room_ids` | JSONB NULL | Типы комнат (массив ID) |
| `price_min` | NUMERIC NULL | Минимальная цена |
| `price_max` | NUMERIC NULL | Максимальная цена |
| `area_min` | NUMERIC NULL | Минимальная площадь |
| `area_max` | NUMERIC NULL | Максимальная площадь |
| `floor_min` | SMALLINT NULL | Минимальный этаж |
| `floor_max` | SMALLINT NULL | Максимальный этаж |
| `metro_ids` | JSONB NULL | Станции метро (массив ID) |
| `districts` | JSONB NULL | Районы (массив строк) |
| `notes` | TEXT NULL | Примечания |
| `is_active` | BOOLEAN DEFAULT true | Активен ли критерий |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `client_id` -> `clients(id)` ON DELETE CASCADE
- `category_id` -> `categories(id)` ON DELETE SET NULL
- `location_id` -> `locations(id)` ON DELETE SET NULL

**Индексы:**
- `client_search_criteria_client_index` (`client_id`)
- `client_search_criteria_cat_loc_active_index` (`category_id`, `location_id`, `is_active`)

---

### 32. client_listings — Привязка объявлений к клиенту

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `client_id` | INT NOT NULL FK | Ссылка на clients |
| `listing_id` | INT NOT NULL FK | Ссылка на listings |
| `status` | VARCHAR(20) DEFAULT 'proposed' | proposed/showed/liked/rejected |
| `comment` | VARCHAR(500) NULL | Комментарий агента |
| `showed_at` | TIMESTAMP NULL | Дата показа |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `client_id` -> `clients(id)` ON DELETE CASCADE
- `listing_id` -> `listings(id)` ON DELETE CASCADE

**Индексы:**
- `client_listings_client_listing_unique` (UNIQUE on `client_id`, `listing_id`)
- `client_listings_client_status_index` (`client_id`, `status`)
- `client_listings_listing_index` (`listing_id`)

---

### 33. properties — Объекты недвижимости (CRM v2)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users (агент-владелец) |
| `listing_id` | BIGINT NULL FK | Ссылка на listings (связь с парсером) |
| `title` | VARCHAR(500) NULL | Название объекта |
| `address` | VARCHAR(500) NULL | Адрес |
| `price` | DECIMAL(15,2) NULL | Цена |
| `rooms` | SMALLINT NULL | Количество комнат |
| `area` | DECIMAL(10,2) NULL | Площадь, м² |
| `floor` | SMALLINT NULL | Этаж |
| `floors_total` | SMALLINT NULL | Этажей в доме |
| `description` | TEXT NULL | Описание объекта |
| `url` | VARCHAR(1000) NULL | Ссылка на объявление |
| `deal_type` | VARCHAR(10) NOT NULL DEFAULT 'sale' | Тип сделки: `sale` / `rent` |
| `owner_name` | VARCHAR(255) NULL | Имя собственника |
| `owner_phone` | VARCHAR(20) NULL | Телефон собственника |
| `owner_phone_secondary` | VARCHAR(20) NULL | Доп. телефон собственника |
| `source_type` | VARCHAR(50) NULL | Источник: avito, cian, звонок и т.д. |
| `source_details` | VARCHAR(255) NULL | Детали источника |
| `comment` | TEXT NULL | Комментарий агента |
| `is_archived` | BOOLEAN DEFAULT false | В архиве |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `user_id` -> `users(id)` ON DELETE CASCADE
- `listing_id` -> `listings(id)` ON DELETE SET NULL

**Индексы:**
- `properties_user_archived_index` (`user_id`, `is_archived`)
- `properties_user_deal_type_index` (`user_id`, `deal_type`)
- `properties_listing_index` (`listing_id`)
- `properties_owner_phone_index` (`owner_phone`)

---

### 34. contacts — Контакты (CRM v2)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users (агент-владелец) |
| `name` | VARCHAR(255) NOT NULL | ФИО контакта |
| `phone` | VARCHAR(20) NULL | Основной телефон |
| `phone_secondary` | VARCHAR(20) NULL | Дополнительный телефон |
| `email` | VARCHAR(255) NULL | Email |
| `telegram_username` | VARCHAR(100) NULL | Telegram username |
| `comment` | TEXT NULL | Комментарий |
| `is_archived` | BOOLEAN DEFAULT false | В архиве |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:** `user_id` -> `users(id)` ON DELETE CASCADE

**Индексы:**
- `contacts_user_archived_index` (`user_id`, `is_archived`)
- `contacts_phone_index` (`phone`)

---

### 35. object_clients — Связка объект+контакт (CRM v2, воронка)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `property_id` | BIGINT NOT NULL FK | Ссылка на properties |
| `contact_id` | BIGINT NOT NULL FK | Ссылка на contacts |
| `pipeline_stage_id` | BIGINT NOT NULL FK | Ссылка на pipeline_stages (стадия воронки) |
| `comment` | TEXT NULL | Комментарий к связке |
| `next_contact_at` | TIMESTAMP NULL | Дата следующего контакта |
| `last_contact_at` | TIMESTAMP NULL | Дата последнего контакта |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `property_id` -> `properties(id)` ON DELETE CASCADE
- `contact_id` -> `contacts(id)` ON DELETE CASCADE
- `pipeline_stage_id` -> `pipeline_stages(id)` ON DELETE RESTRICT

**Индексы:**
- `object_clients_property_contact_unique` (UNIQUE on `property_id`, `contact_id`)
- `object_clients_stage_index` (`pipeline_stage_id`)
- `object_clients_contact_index` (`contact_id`)
- `object_clients_next_contact_index` (`next_contact_at`)

**Примечание:** Главная сущность воронки продаж. Kanban-карточка = пара (объект + контакт). Drag-n-drop перемещает эту связку между стадиями.

---

### 36. interactions — Таймлайн взаимодействий (CRM v2)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `object_client_id` | BIGINT NOT NULL FK | Ссылка на object_clients (связка объект+контакт) |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users (кто создал) |
| `type` | VARCHAR(20) NOT NULL | Тип: `call`, `meeting`, `showing`, `message`, `note`, `stage_change` |
| `description` | TEXT NULL | Описание взаимодействия |
| `metadata` | JSONB NULL | Дополнительные данные (например, старая/новая стадия при stage_change) |
| `interaction_at` | TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP | Дата/время взаимодействия |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `object_client_id` -> `object_clients(id)` ON DELETE CASCADE
- `user_id` -> `users(id)` ON DELETE CASCADE

**Индексы:**
- `interactions_oc_time_index` (`object_client_id`, `interaction_at`)
- `interactions_user_index` (`user_id`)
- `interactions_type_index` (`type`)

**Примечание:** Хронология взаимодействий по связке объект+контакт. Записи типа `stage_change` создаются автоматически при перемещении карточки на kanban-доске.

---

### 37. reminders — Напоминания с Telegram-уведомлениями (CRM v2)

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | BIGSERIAL PK | Первичный ключ |
| `object_client_id` | BIGINT NOT NULL FK | Ссылка на object_clients (связка объект+контакт) |
| `user_id` | BIGINT NOT NULL FK | Ссылка на users (владелец напоминания) |
| `remind_at` | TIMESTAMP NOT NULL | Дата/время напоминания |
| `message` | TEXT NOT NULL | Текст напоминания |
| `is_sent` | BOOLEAN NOT NULL DEFAULT false | Отправлено ли уведомление |
| `sent_at` | TIMESTAMP NULL | Дата/время отправки уведомления |
| `created_at` | TIMESTAMP NULL | Дата создания |
| `updated_at` | TIMESTAMP NULL | Дата обновления |

**FK:**
- `object_client_id` -> `object_clients(id)` ON DELETE CASCADE
- `user_id` -> `users(id)` ON DELETE CASCADE

**Индексы:**
- `idx_reminders_pending` (`is_sent`, `remind_at`)
- `idx_reminders_object_client` (`object_client_id`)
- `idx_reminders_user` (`user_id`, `is_sent`)

**Примечание:** Напоминания привязаны к связке объект+контакт. Cron-команда `SendRemindersCommand` проверяет неотправленные напоминания и отправляет уведомления в Telegram.

---

## Внешние ключи

| Таблица | Колонка | Ссылается на |
|---------|---------|--------------|
| client_listings | client_id | clients(id) |
| client_listings | listing_id | listings(id) |
| client_search_criteria | client_id | clients(id) |
| client_search_criteria | category_id | categories(id) |
| client_search_criteria | location_id | locations(id) |
| clients | user_id | users(id) |
| clients | pipeline_stage_id | pipeline_stages(id) |
| contacts | user_id | users(id) |
| interactions | object_client_id | object_clients(id) |
| interactions | user_id | users(id) |
| reminders | object_client_id | object_clients(id) |
| reminders | user_id | users(id) |
| object_clients | property_id | properties(id) |
| object_clients | contact_id | contacts(id) |
| object_clients | pipeline_stage_id | pipeline_stages(id) |
| pipeline_stages | user_id | users(id) |
| properties | user_id | users(id) |
| properties | listing_id | listings(id) |
| agent_listings | user_id | users(id) |
| agent_listings | listing_id | listings(id) |
| agent_listings | call_status_id | call_statuses(id) |
| category_rooms | category_id | categories(id) |
| category_rooms | room_id | rooms(id) |
| cian_auth | location_id | locations(id) |
| cian_auth | category_id | categories(id) |
| cities | location_parent_id | locations(id) |
| listing_metro | listing_id | listings(id) |
| listing_metro | metro_station_id | metro_stations(id) |
| listing_photo_tasks | id | listings(id) |
| listing_photo_tasks | source_id | sources(id) |
| listings | source_id | sources(id) |
| listings | category_id | categories(id) |
| listings | listing_status_id | listing_statuses(id) |
| listings | room_id | rooms(id) |
| listings | location_id | locations(id) |
| location_proxies | source_id | sources(id) |
| location_proxies | location_id | locations(id) |
| location_proxies | category_id | categories(id) |
| metro_stations | location_id | locations(id) |
| photo_tasks | listing_id | listings(id) |
| refresh_tokens | user_id | users(id) |
| subscription_history | user_id | users(id) |
| subscription_history | subscription_id | user_subscriptions(id) |
| tariff_prices | tariff_id | tariffs(id) |
| tariff_prices | location_id | locations(id) |
| tariff_prices | category_id | categories(id) |
| user_favorite_statuses | user_id | users(id) |
| user_favorites | user_id | users(id) |
| user_favorites | listing_id | listings(id) |
| user_favorites | status_id | user_favorite_statuses(id) |
| user_location_polygons | user_id | users(id) |
| user_location_polygons | subscription_id | user_subscriptions(id) |
| user_settings | user_id | users(id) |
| user_source_cookies | user_id | users(id) |
| user_sources | user_id | users(id) |
| user_sources | source_id | sources(id) |
| user_subscriptions | user_id | users(id) |
| user_subscriptions | tariff_id | tariffs(id) |
| user_subscriptions | category_id | categories(id) |
| user_subscriptions | location_id | locations(id) |

---

## Миграции

Миграции находятся в `db/migrations/` и запускаются через:

```bash
make migrate
# или
docker exec -it slim_php-cli php db/migrations/run.php
```

**Порядок миграций (60 всего):**
1. `20230101000001` — create users table
2. `20230101000002` — create sources table
3. `20230101000003` — create categories table
4. `20230101000004` — create user_settings table
5. `20230101000005` — create user_sources table
6. `20230101000007` — create refresh_tokens table
7. `20240320000001` — create tariffs table
8. `20240522000001` — add telegram_bot_blocked to users
9. `20240526000001` — create locations table
10. `20240526000002` — create tariff_prices table
11. `20240526000003` — create user_subscriptions table
12. `20240526000004` — create subscription_history table
13. `20240701000001` — create user_location_polygons table
14. `20240701000002` — add coordinates to locations table
15. `20240701000003` — add PostGIS fields to existing tables
16. `20240801000001` — fill location coordinates
17. `20240915000001` — update tariffs structure
18. `20240920000001` — fix subscription unique constraint
19. `20241001000001` — create listing_statuses table
20. `20241001000002` — create call_statuses table
21. `20241001000003` — create metro_stations table
22. `20241001000004` — create listings table
23. `20241001000005` — create listing_metro table
24. `20241001000006` — create agent_listings table
25. `20241001000009` — create listing_photo_tasks (parsing_tasks) table
26. `20241201000001` — create cities table
27. `20241201000002` — add city_id to listings table
28. `20241201000003` — fill cities table
29. `20241201000004` — create location_proxies table
30. `20241201000005` — create cian_auth table
31. `20260125000001` — add app_connected to users
32. `20260125000001` — add point (PostGIS) to listings
33. `20260125000002` — remove promoted fields from listings
34. `20260126000001` — update listings structure
35. `20260126000002` — create rooms table
36. `20260126000003` — rename rooms to room_id in listings
37. `20260128000001` — create user_favorites table
38. `20260128000002` — add comment to user_favorites
39. `20260128000003` — create user_favorite_statuses table
40. `20260128000004` — add status_id to user_favorites
41. `20260128000005` — add category_id to tariff_prices
42. `20260201000001` — add price_history to listings
43. `20260202000001` — add hh_ids to metro_stations
44. `20260202000002` — add metro fields to listings (phone_unavailable)
45. `20260202000003` — move metro_info to listing_metro (distance)
46. `20260206000001` — create photo_tasks table
47. `20260208000001` — add notification tracking to user_subscriptions
48. `20260208000002` — create user_source_cookies table
49. `20260208000003` — create bookmarklet_tokens table
50. `20260208100001` — drop bookmarklet_tokens table
51. `20260212000001` — create pipeline_stages table
52. `20260212000002` — create clients table
53. `20260212000003` — create client_search_criteria table
54. `20260212000004` — create client_listings table
55. `20260213000001` — create properties table (CRM v2)
56. `20260213000002` — create contacts table (CRM v2)
57. `20260213000003` — create object_clients table (CRM v2)
58. `20260213000004` — migrate clients to new model (CRM v2)
59. `20260214000001` — create interactions table (CRM v2, таймлайн)
60. `20260214000002` — create reminders table (CRM v2, напоминания)

---

## PostGIS

Расширение PostGIS используется для геопространственных запросов.

**Типы данных:**
- `GEOMETRY(Point, 4326)` — точка (SRID 4326 = WGS84)
- `GEOMETRY(Polygon, 4326)` — полигон

**Таблицы с PostGIS полями:**
- `locations` — `center_point`, `bounds_polygon`
- `listings` — `point`
- `user_location_polygons` — `polygon`, `center_point`

**Индексы:**
- GIST индексы для быстрого геопространственного поиска

**Примеры запросов:**

```sql
-- Найти объявления в полигоне пользователя
SELECT l.* FROM listings l
JOIN user_location_polygons ulp ON ST_Contains(ulp.polygon, l.point)
WHERE ulp.user_id = 1;

-- Расстояние между точками
SELECT ST_Distance(
  ST_SetSRID(ST_Point(lng1, lat1), 4326)::geography,
  ST_SetSRID(ST_Point(lng2, lat2), 4326)::geography
) AS distance_meters;

-- Найти объявления в пределах границ города
SELECT l.* FROM listings l
JOIN locations loc ON ST_Contains(loc.bounds_polygon, l.point)
WHERE loc.id = 1;
```
