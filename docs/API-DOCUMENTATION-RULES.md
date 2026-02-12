# Правила ведения документации REST API

## Текущее состояние

Документация API ведётся в формате **OpenAPI 3.0** (файл `docs/api/openapi.yaml`).
Просмотр через ReDoc: `docs/api/redoc.html` → http://localhost:8080/redoc.html

Описаны 15 групп эндпоинтов (78 операций):
- Аутентификация (`/auth/*`)
- Пользователь (`/me/*`)
- Подписки (`/subscriptions/*`)
- Локации (`/location-polygons/*`)
- Конфигурация (`/config/*`)
- Администрирование (`/admin/subscriptions/*`)
- Billing (`/billing/*`)
- Избранное (`/favorites/*`)
- Объявления (`/listings/*`)
- Фильтры (`/filters`)
- Обработка фото (`/photo-tasks/*`)
- Admin Users (`/admin/users/*`)
- Source Auth (`/source-auth/*`)
- Клиенты (`/clients/*`)
- Воронка продаж (`/clients/stages/*`)

---

## Формат документирования: OpenAPI 3.0

### Почему OpenAPI
- Стандарт индустрии, понимается всеми инструментами
- ReDoc для визуального просмотра
- Автоматическая генерация Postman-коллекций
- Возможность валидации запросов/ответов

### Структура файла openapi.yaml

```yaml
openapi: 3.0.0
info: ...          # Описание API
tags: ...          # Группы эндпоинтов
servers: ...       # Базовый URL
components:
  securitySchemes: # Схема аутентификации (JWT BearerAuth)
  schemas:         # Переиспользуемые схемы данных
paths: ...         # Эндпоинты
```

---

## Стандартный формат ответов API

Проект использует `ResponseTrait` с двумя методами:

### Успешный ответ (`respondWithData`)

```php
$this->respondWithData($response, [
    'key' => 'value',
], 200);
```

```json
{
  "code": 200,
  "status": "success",
  "message": "Описание результата",
  "data": { ... }
}
```

### Ответ с ошибкой (`respondWithError`)

```php
$this->respondWithError($response, 'Сообщение об ошибке', 'error_code', 400);
```

```json
{
  "code": 400,
  "status": "error",
  "message": "Сообщение об ошибке",
  "error": "validation_error"
}
```

### Допустимые коды ошибок (поле `error`)

| Код | Описание |
|-----|----------|
| `token_not_found` | Токен не найден |
| `token_expired` | Токен истёк |
| `invalid_token` | Невалидный токен |
| `invalid_token_type` | Неверный тип токена |
| `invalid_credentials` | Неверные учётные данные |
| `subscription_expired` | Подписка истекла |
| `subscription_required` | Требуется активная подписка |
| `access_denied` | Доступ запрещён |
| `not_found` | Ресурс не найден |
| `validation_error` | Ошибка валидации |
| `internal_error` | Внутренняя ошибка сервера |

---

## Правила описания эндпоинта

### Обязательные поля

Каждый эндпоинт **обязан** содержать:

```yaml
/api/v1/resource:
  post:
    tags:
      - Название группы              # Группировка в документации
    summary: Краткое описание        # Одна строка
    description: |                   # Подробное описание (если нужно)
      Развёрнутое описание.
    operationId: methodName          # Имя метода контроллера
    security:
      - BearerAuth: []               # Если требуется аутентификация
    requestBody:                     # Для POST/PUT/PATCH
      required: true
      content:
        application/json:
          schema: ...
    responses:
      '200': ...                     # Успешный ответ
      '400': ...                     # Ошибка валидации
      '401': ...                     # Не авторизован
      '500': ...                     # Внутренняя ошибка
```

### Правило для operationId

`operationId` **обязан** совпадать с именем метода контроллера:

```yaml
operationId: getUserInfo          # → UserController::getUserInfo()
operationId: activateSubscription # → AdminSubscriptionController::activateSubscription()
operationId: getListings          # → ListingController::getListings()
```

### Стандартные наборы кодов ответов

| HTTP метод | Обязательные коды |
|------------|-------------------|
| **GET** (получение) | 200, 401, 500 + 404 если по ID + 403 если требует подписку |
| **POST** (создание) | 200/201, 400, 401, 422, 500 + 403 если требует подписку |
| **PUT/PATCH** (обновление) | 200, 400, 401, 404, 422, 500 |
| **DELETE** (удаление) | 200, 401, 404, 500 |

---

## Правила описания полей схемы

Каждое поле **обязано** содержать `type`, `description` и `example`:

```yaml
field_name:
  type: integer
  description: Описание поля         # На русском языке
  example: 42                        # Реалистичный пример
```

Для nullable полей:

```yaml
phone:
  type: string
  nullable: true
  description: Номер телефона
  example: "+7 999 123-45-67"
```

Для enum-полей — перечислить допустимые значения:

```yaml
status:
  type: string
  description: |
    Статус подписки:
    - active: Активна
    - pending: Ожидает оплаты
    - expired: Истекла
    - cancelled: Отменена
    - extend_pending: Ожидает продления
  enum: [active, pending, expired, cancelled, extend_pending]
  example: active
```

Для массивов:

```yaml
location_ids:
  type: array
  items:
    type: integer
  description: Массив ID локаций для фильтрации
  example: [1, 2]
```

---

## ОБЯЗАТЕЛЬНО: Примеры ответов (examples)

### Правило

Каждый эндпоинт **обязан** содержать блок `examples` с полными примерами ответов. Примеры берутся из кода контроллера — из вызовов `respondWithData()` и `respondWithError()`.

### Где брать данные для примеров

1. **Успешный ответ** — из `respondWithData($response, [...], code)` в контроллере
2. **Ошибки** — из всех `respondWithError($response, 'текст', 'код', http_code)` в контроллере
3. **Структура data** — из моделей и методов, вызываемых в контроллере

### Формат примеров для успешного ответа

```yaml
'200':
  description: Список объявлений получен успешно
  content:
    application/json:
      schema:
        # ... схема ...
      examples:
        success:
          summary: Успешный ответ с данными
          value:
            code: 200
            status: success
            message: "Список объявлений получен"
            data:
              listings:
                - id: 1234
                  title: "2-к. квартира, 55 м², 3/9 эт."
                  price: 45000
                  address: "Москва, ул. Ленина, 10"
                  source: { id: 3, name: "CIAN" }
                  created_at: "2026-02-10T12:00:00Z"
              pagination:
                page: 1
                per_page: 20
                total: 150
                total_pages: 8
        empty_list:
          summary: Пустой список (нет данных по фильтрам)
          value:
            code: 200
            status: success
            message: "Список объявлений получен"
            data:
              listings: []
              pagination:
                page: 1
                per_page: 20
                total: 0
                total_pages: 0
```

### Формат примеров для ошибок

Для каждого кода ошибки указывать **реальные сообщения из контроллера**:

```yaml
'400':
  description: Ошибка валидации входных данных
  content:
    application/json:
      schema:
        $ref: '#/components/schemas/Error'
      examples:
        missing_field:
          summary: Отсутствует обязательное поле
          value:
            code: 400
            status: error
            message: "Не указан tariff_id"
            error: validation_error
        invalid_format:
          summary: Неверный формат данных
          value:
            code: 400
            status: error
            message: "Неверный формат JSON"
            error: validation_error
```

```yaml
'401':
  description: Не авторизован
  content:
    application/json:
      schema:
        $ref: '#/components/schemas/Error'
      examples:
        token_missing:
          summary: Токен не передан
          value:
            code: 401
            status: error
            message: "Токен не найден"
            error: token_not_found
        token_expired:
          summary: Токен истёк
          value:
            code: 401
            status: error
            message: "Токен истёк"
            error: token_expired
```

```yaml
'403':
  description: Доступ запрещён
  content:
    application/json:
      schema:
        $ref: '#/components/schemas/Error'
      examples:
        subscription_required:
          summary: Требуется активная подписка
          value:
            code: 403
            status: error
            message: "Для доступа требуется активная подписка"
            error: subscription_required
        admin_only:
          summary: Только для администраторов
          value:
            code: 403
            status: error
            message: "Доступ только для администраторов"
            error: access_denied
```

```yaml
'422':
  description: Бизнес-ошибка
  content:
    application/json:
      schema:
        $ref: '#/components/schemas/Error'
      examples:
        trial_used:
          summary: Демо уже использовано
          value:
            code: 422
            status: error
            message: "Вы уже использовали демо-тариф"
            error: validation_error
```

```yaml
'500':
  description: Внутренняя ошибка сервера
  content:
    application/json:
      schema:
        $ref: '#/components/schemas/Error'
      examples:
        internal:
          summary: Внутренняя ошибка
          value:
            code: 500
            status: error
            message: "Внутренняя ошибка сервера"
            error: internal_error
```

### Как извлекать примеры из контроллера

1. Найти все вызовы `respondWithData()` — это примеры успешных ответов
2. Найти все вызовы `respondWithError()` — это примеры ошибок
3. Для каждого уникального HTTP-кода создать именованный пример
4. Текст `message` копировать **точно как в коде**
5. Для `data` — взять реалистичные значения на основе модели/таблицы

### Минимальные требования к примерам

| Тип ответа | Минимум примеров |
|------------|-----------------|
| Успешный ответ (200/201) | 1 пример с данными + 1 пустой (для списков) |
| 400 Bad Request | 1 пример на каждый тип ошибки валидации |
| 401 Unauthorized | 1 пример (токен не найден / истёк) |
| 403 Forbidden | 1 пример (подписка / права) |
| 404 Not Found | 1 пример (ресурс не найден) |
| 422 Unprocessable | 1 пример на каждую бизнес-ошибку |
| 500 Internal Error | 1 пример (общая ошибка) |

---

## Переиспользуемые компоненты (components/schemas)

### Существующие общие схемы

| Схема | Описание |
|-------|----------|
| `Error` | Стандартный формат ошибки (`code`, `status`, `message`, `error`) |
| `Pagination` | Блок пагинации (`page`, `per_page`, `total`, `total_pages`) |
| `SourceSubscriptionInfo` | Информация о подписке на CIAN/Avito |
| `SourceAuthStatus` | Статус авторизации на источнике |
| `AdminUser` | Пользователь для админ-панели |
| `UserInfo` | Информация о текущем пользователе |
| `Tokens` | JWT токены (access + refresh) |
| `Subscription` | Данные подписки |
| `LocationPolygon` | Полигон локации |

### Правило: когда создавать новую схему

- Схема используется в **2+ местах** → выносить в `components/schemas`
- Схема используется только в **1 месте** → описать inline
- Вложенные объекты с **3+ полями** → всегда выносить в отдельную схему

### Именование схем

- **PascalCase**: `CreateSubscriptionRequest`, `ListingsResponse`
- **Суффиксы**: `Request` для тела запроса, `Response` для ответа (если выносится)
- **Для сущностей**: имя без суффикса (`Listing`, `Subscription`, `Tariff`)

---

## Правила для tags (групп)

### Формат

```yaml
tags:
  - name: Объявления
    description: Методы для работы с объявлениями недвижимости
```

### Именование
- На **русском языке** (допускаются английские для Admin Users, Source Auth)
- Одна группа = один контроллер
- Формат: существительное или «Действие + сущность»

### Порядок описания эндпоинтов внутри группы

1. `GET /resource` — получение списка
2. `GET /resource/stats` — статистика
3. `POST /resource` — создание / получение с фильтрами
4. `GET /resource/{id}` — получение по ID
5. `PUT /resource/{id}` — обновление
6. `PATCH /resource/{id}/status` — изменение статуса
7. `DELETE /resource/{id}` — удаление
8. Специальные методы (toggle, reorder, download)

---

## Валидация openapi.yaml

### Проверка после каждого изменения

После любого изменения `docs/api/openapi.yaml` — открыть ReDoc и убедиться что всё отображается без ошибок.

### Чеклист валидации

1. Файл открывается без ошибок в ReDoc (`docs/api/redoc.html`)
2. Все `$ref` ссылки указывают на существующие schemas
3. У каждого эндпоинта есть `operationId`
4. У каждого эндпоинта есть `responses` с хотя бы `200` и `500`
5. У каждого response есть `examples`
6. Нет дублирующихся `operationId`
7. Нет дублирующихся путей (path + method)

### Типичные ошибки

| Ошибка | Причина | Как исправить |
|--------|---------|---------------|
| `$ref not found` | Ссылка на несуществующую схему | Создать схему или исправить имя |
| Duplicate `operationId` | Одинаковый ID у разных операций | Сделать уникальным |
| Невалидный YAML | Неправильные отступы | Только пробелы, 2 пробела на уровень |
| Пример не соответствует type | `example: "42"` при `type: integer` | Привести пример к правильному типу |
| Нет `examples` | Забыли добавить | Взять из контроллера (`respondWithData/Error`) |

---

## Чеклист при добавлении нового эндпоинта

1. Добавить описание в `docs/api/openapi.yaml`
2. Указать `operationId`, совпадающий с методом контроллера
3. Описать все поля запроса с `type`, `description`, `example`
4. Описать структуру успешного ответа
5. Добавить `examples` с полными примерами ответов из контроллера
6. Добавить стандартные коды ошибок (400, 401, 500 минимум)
7. Добавить `examples` для каждого кода ошибки с реальными сообщениями из кода
8. Если есть бизнес-ошибки — добавить 422 с примерами
9. Если нужны новые схемы — добавить в `components/schemas`
10. Проверить что тег (группа) существует в секции `tags`
11. Открыть ReDoc и убедиться что эндпоинт отображается корректно
12. Обновить `docs/API-QUICK-REFERENCE.md`
