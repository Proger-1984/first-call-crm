# First Call CRM

CRM-система для управления подписками на недвижимость с автоматическими уведомлениями через Telegram Bot.

## Основной функционал

- Авторизация через Telegram Login Widget
- Управление подписками на объявления недвижимости
- Парсинг объявлений с CIAN, Avito
- Автозвонок по новым объявлениям
- Telegram уведомления

## Технологический стек

### Backend
| Технология | Версия | Назначение |
|------------|--------|------------|
| PHP | 8.3+ | Строгая типизация, современный синтаксис |
| Slim 4 | 4.x | Легковесный REST API framework |
| PHP-DI | 7.x | Dependency Injection контейнер |
| Eloquent ORM | 10.x | Работа с БД через модели |
| PostgreSQL | 15 | Основная БД |
| PostGIS | 3.x | Геопространственные запросы |
| JWT | - | Авторизация (access + refresh токены) |

### Frontend
| Технология | Версия | Назначение |
|------------|--------|------------|
| React | 18.3+ | UI библиотека |
| TypeScript | 5.9+ | Типизация |
| Vite | 5.4+ | Сборка |
| Zustand | 5.0+ | State management |
| TanStack Query | 5.90+ | Серверный state |
| Axios | 1.9+ | HTTP клиент |
| React Router | 7.12+ | Роутинг |

## Системные требования

- Docker и Docker Compose
- Git
- Make (опционально)

## Структура проекта

```
├── bootstrap/           # Инициализация приложения
│   ├── app.php         # Конфигурация + Eloquent
│   ├── container.php   # DI контейнер
│   └── middleware.php  # Глобальные middleware
├── config/             # Конфигурационные файлы
├── db/migrations/      # Миграции базы данных
├── docker/             # Docker конфигурации
├── docs/               # Документация
├── frontend-react/     # React фронтенд
│   ├── src/
│   │   ├── components/ # UI компоненты
│   │   ├── pages/      # Страницы
│   │   ├── stores/     # Zustand stores
│   │   ├── services/   # API клиент
│   │   └── types/      # TypeScript типы
│   └── public/         # Статика
├── public/             # Точка входа backend
├── routes/             # API маршруты
├── src/                # Исходный код backend
│   ├── Controllers/    # Контроллеры
│   ├── Services/       # Бизнес-логика
│   ├── Models/         # Eloquent модели
│   ├── Middleware/     # PSR-15 middleware
│   └── Commands/       # CLI команды
└── storage/            # Загрузки, файлы
```

## Быстрый старт

### 1. Клонирование репозитория

```bash
git clone https://github.com/Proger-1984/first-call-crm.git
cd first-call-crm
```

### 2. Настройка окружения

```bash
cp .env.example .env
# Отредактируйте .env, указав необходимые настройки
```

### 3. Первоначальная инициализация

```bash
make init
```

Или запуск всего проекта одной командой:

```bash
make dev
```

## Доступные адреса

После запуска `make dev` доступны:

| Сервис | URL |
|--------|-----|
| Frontend | https://local.firstcall.com |
| Backend API | https://local.firstcall.com/api/v1 |
| API Docs | http://localhost:8080/redoc.html |
| pgAdmin | http://localhost:5050 |

## Основные команды

```bash
# Запуск всего проекта
make dev

# Остановка всего
make dev-stop

# Только Docker контейнеры (бэкенд + БД)
make up
make down

# Только фронтенд
make frontend

# Только документация API
make docs

# Пересборка контейнеров
make build

# Просмотр логов
make logs

# Запуск миграций
make migrate

# Войти в PHP контейнер
docker exec -it slim_php-cli bash
```

## Документация

| Документ | Описание |
|----------|----------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Архитектура проекта |
| [docs/DATABASE.md](docs/DATABASE.md) | Схема базы данных |
| [docs/API-QUICK-REFERENCE.md](docs/API-QUICK-REFERENCE.md) | Справочник API |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Деплой и запуск |
| [docs/FRONTEND_REACT.md](docs/FRONTEND_REACT.md) | Документация фронтенда |
| [docs/TELEGRAM_SETUP.md](docs/TELEGRAM_SETUP.md) | Настройка Telegram бота |

## API Документация

Интерактивная документация API доступна через Redoc:

```bash
make docs
# Открыть http://localhost:8080/redoc.html
```

Файлы:
- `docs/api/openapi.yaml` — OpenAPI спецификация
- `docs/api/redoc.html` — интерактивная документация

## Устранение проблем

### Ошибка прав доступа

```bash
chmod +x fix-permissions.sh
./fix-permissions.sh
```

### Пересборка контейнеров

```bash
make build
make restart
```

## Лицензия

Проприетарное ПО. Все права защищены.
