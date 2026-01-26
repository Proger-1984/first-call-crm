# First Call API

Проект API для сервиса First Call.

## Системные требования

- Docker и Docker Compose
- Git
- Make (опционально)
- PHP 8.3 или выше

## Структура проекта

```
├── app/                # Основная директория приложения
├── bootstrap/          # Загрузочные файлы приложения
├── config/             # Конфигурационные файлы
├── db/                 # Работа с базой данных
│   └── migrations/     # Миграции базы данных
├── docker/             # Конфигурации Docker
│   └── dev/            # Для разработки
├── docs/               # Документация API
├── logs/               # Логи приложения
├── public/             # Публичные файлы (точка входа)
├── routes/             # Маршруты приложения
├── src/                # Исходный код
│   ├── Console/        # Консольные команды
│   ├── Controllers/    # Контроллеры
│   ├── Middleware/     # Промежуточное ПО
│   ├── Models/         # Модели
│   ├── Services/       # Сервисы
│   ├── Traits/         # Трейты
│   └── Utils/          # Утилиты
├── tests/              # Тесты
├── vendor/             # Зависимости Composer
├── .env                # Переменные окружения
├── .env.example        # Пример переменных окружения
├── .gitignore          # Игнорируемые Git файлы
├── composer.json       # Конфигурация Composer
├── composer.lock       # Фиксированные версии зависимостей
├── docker-compose.yml  # Docker конфигурация
├── docker-compose.redoc.yml # Конфигурация Redoc
├── fix-permissions.sh  # Скрипт исправления прав доступа
├── Makefile           # Make команды
└── start-dev.sh       # Скрипт запуска
```

## Установка и запуск

1. Клонировать репозиторий
   ```bash
   git clone https://your-repo-url.git first-call
   cd first-call
   ```

2. Копирование .env файла (если отсутствует)
   ```bash
   cp .env.example .env
   # Отредактируйте .env, указав необходимые настройки
   ```

3. Исправление прав доступа (если необходимо)
   ```bash
   # Если у вас возникают ошибки прав доступа, выполните:
   chmod +x fix-permissions.sh
   ./fix-permissions.sh
   ```

4. Запуск контейнеров
   ```bash
   ./start-dev.sh
   # или
   make up
   ```

5. Установка зависимостей
   ```bash
   make install
   ```

6. Запуск миграций
   ```bash
   make migrate
   ```

Или используйте одну команду для инициализации:
```bash
make init
```

### Устранение проблем при запуске

Если вы столкнулись с ошибками при запуске:

1. **Ошибка прав доступа**: 
   ```
   cp: cannot create regular file '.env': Permission denied
   ```
   
   Решение:
   ```bash
   chmod +x fix-permissions.sh
   ./fix-permissions.sh
   ```

2. **Ошибка Bash в контейнере**:
   ```
   OCI runtime exec failed: exec failed: unable to start container process: exec: "bash": executable file not found in $PATH: unknown
   ```
   
   Решение:
   Убедитесь, что файл миграций `db/migrations/run.php` существует.

## Быстрый старт

```bash
# Запуск ВСЕГО проекта одной командой
# (бэкенд, БД, фронтенд, документация)
make dev
```

После запуска будут доступны:
- **Backend API:** https://local.firstcall.com/api/v1
- **Frontend:** http://localhost:5173
- **API Docs:** http://localhost:8080/redoc.html
- **pgAdmin:** http://localhost:5050

```bash
# Остановка всего
make dev-stop
```

## Базовые команды

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
make frontend-stop

# Только документация
make docs
make docs-stop

# Пересборка контейнеров
make build

# Перезапуск контейнеров
make restart

# Просмотр логов
make logs

# Запуск миграций
make migrate

# Исправление прав доступа
make fix-permissions
```

## Структура базы данных

Миграции базы данных находятся в директории `db/migrations/`. Файл `run.php` должен содержать логику запуска миграций. 

## Тестирование

Для запуска тестов используйте команду:
```bash
make test
# или
composer test
```

## Документация API

### Просмотр документации

Документация API создана с использованием OpenAPI 3.0 и Redoc.

**Запуск локально:**
```bash
cd docs/api && python3 -m http.server 8080
```

После запуска документация будет доступна по адресу: http://localhost:8080/redoc.html

**Файлы документации:**
- `docs/api/openapi.yaml` — OpenAPI спецификация всех эндпоинтов
- `docs/api/redoc.html` — интерактивная документация

### Что содержит документация

- Все доступные API эндпоинты (публичные, защищённые, административные)
- Форматы запросов и ответов
- Схемы данных
- Коды ошибок
- Примеры использования
- Требования к авторизации (JWT) 