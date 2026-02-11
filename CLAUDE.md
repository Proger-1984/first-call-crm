# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# First Call CRM — Правила проекта

## О проекте

**First Call CRM** — REST API сервис для управления подписками на недвижимость с автоматическими уведомлениями через Telegram Bot.

Основной функционал:
- Авторизация через Telegram Login Widget
- Управление подписками на объявления недвижимости
- Парсинг объявлений с CIAN, Avito
- Автозвонок по новым объявлениям
- Telegram уведомления

---

## КРИТИЧЕСКИЕ ПРАВИЛА

### PHP функции времени
- **НИКОГДА не использовать `now()`** — это Laravel helper, НЕ работает в Slim!
- **ВСЕГДА использовать `Carbon::now()`** с импортом `use Carbon\Carbon;`
- Касается ВСЕХ файлов: Controllers, Services, Middleware, Models

### Следование правилам проекта
- ПЕРЕД выполнением задачи — прочитать соответствующие правила (см. "Навигация по справочникам")
- Использовать шаблоны кода из правил как образец
- Если нужно отклониться от правил — ОСТАНОВИТЬСЯ, объяснить причину, СПРОСИТЬ разрешения

### Стиль кода
- Строгая типизация везде: `declare(strict_types=1);` в каждом PHP файле
- PHPDoc к ВСЕМ публичным методам
- Описательные имена переменных, НЕ однобуквенные (кроме итераторов)
- Избегать магических чисел — использовать константы
- Комментарии в коде на русском

### Безопасность
- Никогда не хардкодить секреты, API ключи, пароли
- Валидировать пользовательский ввод
- Параметризованные запросы к БД
- Санитизировать вывод для предотвращения XSS

### Git commits
- Commit messages на русском языке
- Формат: "Тип: краткое описание"
- Типы: feat, fix, refactor, docs, style, test, chore

---

## Технологический стек

### Backend
| Технология | Версия | Назначение |
|---|---|---|
| PHP | 8.3+ | Строгая типизация |
| Slim 4 | 4.x | REST API framework |
| PHP-DI | 7.x | Dependency Injection |
| Eloquent ORM | 10.x | Работа с БД (НЕ Laravel, только Eloquent) |
| PostgreSQL | 15 | Основная БД |
| PostGIS | 3.x | Геопространственные запросы |
| JWT | firebase/php-jwt | Авторизация (access + refresh токены) |
| Monolog | 3.4 | Логирование |
| GuzzleHTTP | 7.9 | HTTP клиент |

### Frontend
| Технология | Версия | Назначение |
|---|---|---|
| React | 18.3+ | UI библиотека |
| TypeScript | 5.9+ | Типизация |
| Vite | 5.4+ | Сборка |
| Zustand | 5.0+ | State management (НЕ Redux) |
| TanStack Query | 5.90+ | Серверный state |
| Axios | 1.13+ | HTTP клиент с auto-refresh токенов |
| React Router | 7.12+ | Роутинг |

### Инфраструктура Docker
| Сервис | Контейнер | Порт |
|---|---|---|
| PostgreSQL + PostGIS | slim_postgres | 5432 |
| PHP-FPM 8.3 | slim_php-fpm | 9000 |
| PHP CLI | slim_php-cli | — |
| Nginx (SSL) | slim_nginx | 80, 443 |
| pgAdmin 4 | slim_pgadmin | 5050 |
| CIAN Proxy (Go) | slim_cian-proxy | 4829 |

---

## Архитектура

```
HTTP Request (Nginx)
        ↓
  Middleware Layer (CORS → JSON Parser → Auth JWT)
        ↓
  Controller Layer (валидация → делегирование, использует ResponseTrait)
        ↓
  Service Layer (бизнес-логика, инжектируются через PHP-DI)
        ↓
  Model Layer (Eloquent модели, связи, аксессоры/мутаторы)
        ↓
  PostgreSQL Database
```

Ключевые паттерны: DI, Service Layer, Repository через Eloquent, Response Trait.

---

## Структура проекта

### Backend
```
bootstrap/
├── app.php          # Конфигурация + Eloquent
├── container.php    # DI контейнер (регистрация сервисов)
├── middleware.php   # Глобальные middleware
routes/routes.php    # ВСЕ API маршруты
src/
├── Controllers/     # Тонкие контроллеры (валидация + ответ)
├── Services/        # Бизнес-логика
├── Models/          # Eloquent модели
├── Middleware/       # PSR-15 middleware
├── Commands/        # CLI команды (парсеры, cron)
├── Traits/          # ResponseTrait и другие
├── Utils/           # Утилиты
db/migrations/       # Миграции БД (НЕ изменять старые!)
docs/api/openapi.yaml # OpenAPI спецификация
```

### Frontend (`frontend-react/`)
```
src/
├── components/
│   ├── Layout/      # Header, Sidebar
│   ├── UI/          # Переиспользуемые компоненты
│   └── Auth/        # Авторизация
├── pages/           # Страницы (Dashboard, Login, Profile, Settings, Tariffs, Billing)
├── stores/          # Zustand stores (authStore, uiStore)
├── services/api.ts  # Axios клиент
├── types/           # TypeScript типы
└── styles/          # CSS (переменные, глобальные стили)
```

---

## JWT Авторизация

- Access token: 1 час, передаётся в `Authorization: Bearer <token>`
- Refresh token: 7 дней, хранится в httpOnly cookie
- Обновление: `POST /api/v1/auth/refresh` с refresh_token в body
- Авторизация: через Telegram Widget (`POST /api/v1/auth/telegram`)

---

## Система подписок

### Статусы
`pending` → `active` → (`extend_pending`) → `expired` / `cancelled`

### Тарифы (2 штуки)
| id | Название | code | Часы | Цена |
|---|---|---|---|---|
| 1 | Демо | demo | 3 | 0₽ |
| 2 | Премиум 31 день | premium_31 | 744 | 5000₽ |

Демо активируется автоматически (один раз). Платные требуют одобрения админа.

### Источники
- Авито (source_id 1), Яндекс.Н (2), Циан (3), ЮЛА (4)

### Защита эндпоинтов
SubscriptionMiddleware проверяет наличие активной подписки. Роли: user, admin.

---

## Основные таблицы БД (30 таблиц)

| Таблица | Назначение | Записей |
|---|---|---|
| `users` | Пользователи (Telegram авторизация) | 2 |
| `user_settings` | Настройки (автозвонок, уведомления) | 2 |
| `user_subscriptions` | Подписки пользователей | 2 |
| `subscription_history` | Аудит действий с подписками | 4 |
| `tariffs` | 2 тарифных плана (demo, premium) | 2 |
| `tariff_prices` | Цены по локациям и категориям | 156 |
| `categories` | 4 категории недвижимости | 4 |
| `locations` | 19 городов/регионов | 19 |
| `cities` | Детальные города/районы | 275 |
| `rooms` | 7 типов комнат (студия, 1-5+ комн) | 7 |
| `category_rooms` | Связь категорий с типами комнат | 14 |
| `sources` | 4 источника (Авито, Яндекс.Н, Циан, ЮЛА) | 4 |
| `listings` | Объявления недвижимости | 2190 |
| `listing_metro` | Связь объявлений с метро | 1905 |
| `metro_stations` | Станции метро | 587 |
| `user_favorites` | Избранные объявления | 4 |
| `user_favorite_statuses` | Пользовательские статусы избранного | 2 |
| `refresh_tokens` | JWT refresh токены | 2 |
| `user_source_cookies` | Куки авторизации на источниках | 2 |

Полная схема: `docs/DATABASE.md`

---

## Основные API Endpoints

| Метод | URL | Описание |
|---|---|---|
| POST | `/api/v1/auth/telegram` | Авторизация через Telegram |
| POST | `/api/v1/auth/refresh` | Обновление токенов |
| GET | `/api/v1/user/profile` | Профиль пользователя |
| GET | `/api/v1/subscriptions` | Список подписок |
| POST | `/api/v1/subscriptions` | Создание подписки |
| GET | `/api/v1/tariffs` | Список тарифов |
| GET | `/api/v1/listings` | Список объявлений |
| GET | `/api/v1/source-auth/status` | Статус авторизации на источниках |

Формат ответа: `{ code, status, message, data }`

Полная документация: `docs/API-QUICK-REFERENCE.md` и `docs/api/openapi.yaml`

---

## Быстрые команды

```bash
make dev              # Запуск ВСЕГО (бэкенд + БД + фронтенд + документация)
make dev-stop         # Остановка всего
make up               # Только Docker контейнеры
make down             # Остановка Docker
make frontend         # Только React фронтенд
make migrate          # Запуск миграций БД
make logs             # Просмотр логов
make build            # Пересборка контейнеров
make restart          # Перезапуск
make init             # Первоначальная настройка
```

### URLs после запуска
- Frontend: https://local.firstcall.com
- Backend API: https://local.firstcall.com/api/v1
- API Docs: http://localhost:8090/redoc.html
- pgAdmin: http://localhost:5050

### Работа с контейнерами
```bash
docker exec -it slim_php-cli bash       # Войти в PHP контейнер
docker exec -it slim_php-cli php bin/app.php transfer:listings  # Перенос объявлений
docker exec -it slim_php-cli php bin/app.php photo-tasks        # Обработка фото
```

---

## Навигация по справочникам

ПЕРЕД началом работы прочитай `docs/PROJECT_STATUS.md` — там текущая задача, контекст и блокеры.

При работе с конкретной областью — читай соответствующий справочник:

| Область работы | Файл для чтения |
|---|---|
| Архитектура (общая структура, принципы, стек) | `docs/ARCHITECTURE.md` |
| База данных (таблицы, связи, индексы, PostGIS) | `docs/DATABASE.md` |
| API эндпоинты (все маршруты с примерами) | `docs/API-QUICK-REFERENCE.md` |
| Деплой, Docker, Supervisor, Cron, SSL | `docs/DEPLOYMENT.md` |
| Консольные команды (парсеры, уведомления) | `docs/SCRIPTS.md` |
| Подписки (статусы, тарифы, уведомления, бизнес-логика) | `docs/SUBSCRIPTIONS.md` |
| Telegram интеграция | `docs/TELEGRAM_SETUP.md` |
| Незнакомый термин проекта | `docs/GLOSSARY.md` |
| Обновление API документации | `docs/api/openapi.yaml` |
| **Правила ведения REST API документации** | `docs/API-DOCUMENTATION-RULES.md` |

---

## Чеклисты

### При изменении API
> Полные правила документирования: `docs/API-DOCUMENTATION-RULES.md`
- [ ] Обновить `docs/api/openapi.yaml` по правилам из `docs/API-DOCUMENTATION-RULES.md`
- [ ] Указать `operationId` совпадающий с методом контроллера
- [ ] Добавить `examples` с реальными ответами из `respondWithData()`/`respondWithError()`
- [ ] Добавить стандартные коды ошибок (400, 401, 500 минимум) с примерами
- [ ] Обновить `docs/API-QUICK-REFERENCE.md`
- [ ] Добавить `try-catch` в новые методы контроллеров
- [ ] Зарегистрировать сервис в `bootstrap/container.php`
- [ ] Добавить маршрут в `routes/routes.php`
- [ ] Проверить отображение в ReDoc (http://localhost:8090/redoc.html)

### При изменении БД
- [ ] Создать НОВУЮ миграцию (НЕ изменять старые!)
- [ ] Обновить `docs/DATABASE.md`
- [ ] Timestamps (created_at, updated_at)
- [ ] Индексы на внешних ключах

### При изменении Frontend
- [ ] TypeScript типы для новых данных
- [ ] Обработать loading/error состояния
- [ ] CSS переменные вместо хардкода цветов
- [ ] Именованный + default экспорт компонентов

### Общее
- [ ] Строгая типизация (`declare(strict_types=1)` в PHP, TypeScript во фронте)
- [ ] PHPDoc к публичным методам
- [ ] `Carbon::now()` вместо `now()`
- [ ] Контроллеры тонкие — логика в сервисах
- [ ] ResponseTrait для ответов API
- [ ] Обновить PROJECT_STATUS.md после завершения задачи

---

## Важно помнить

- **Парсинг ответа API:** `response.data` = `{ meta, data }` (без обёртки success)
- **PHP-DI + Slim 4:** Параметры роута через `$request->getAttribute('id')`
- **Кеширование отключено** для ВСЕХ страниц
- **Миграции:** НЕ изменять старые, создавать новые
- **PostGIS:** Используется для геопространственных запросов (GEOMETRY Point/Polygon, SRID 4326)
