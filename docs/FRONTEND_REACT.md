# First Call CRM - React Frontend

**Статус:** Активная разработка  
**Технологии:** React 18 + TypeScript + Vite

## 🎯 Описание

Современный SPA фронтенд для CRM системы риелторов.

## 🚀 Быстрый старт

```bash
cd frontend-react

# Установить зависимости
npm install

# Запустить dev-сервер
npm run dev
# http://localhost:3000

# Сборка для production
npm run build
```

## 📁 Структура проекта

```
frontend-react/
├── src/
│   ├── components/
│   │   ├── Auth/                    # Авторизация
│   │   │   ├── TelegramLoginButton.tsx   # Виджет Telegram
│   │   │   ├── ProtectedRoute.tsx        # Защита роутов
│   │   │   └── index.ts
│   │   ├── Layout/                  # Каркас приложения
│   │   │   ├── Header.tsx           # Шапка с профилем
│   │   │   ├── Sidebar.tsx          # Боковое меню
│   │   │   ├── Layout.tsx           # Общий layout
│   │   │   └── *.css
│   │   └── UI/                      # UI компоненты
│   │       ├── StatsCard.tsx        # Карточки статистики
│   │       ├── Badge.tsx            # Бейджи
│   │       ├── MultiSelect.tsx      # Мультиселект
│   │       ├── DatePicker.tsx       # Выбор даты
│   │       └── *.css
│   ├── pages/
│   │   ├── Login/                   # Страница авторизации
│   │   │   ├── Login.tsx
│   │   │   └── LoginTest.tsx        # Тестовая страница
│   │   └── Dashboard/               # Главная страница
│   │       ├── Dashboard.tsx
│   │       └── Dashboard.css
│   ├── services/
│   │   └── api.ts                   # Axios клиент + interceptors
│   ├── stores/
│   │   ├── authStore.ts             # Zustand store авторизации
│   │   └── uiStore.ts               # UI состояние
│   ├── types/
│   │   ├── auth.ts                  # Типы авторизации
│   │   └── index.ts                 # Общие типы
│   ├── styles/
│   │   └── index.css                # Глобальные стили + CSS переменные
│   ├── App.tsx                      # Роутинг
│   └── main.tsx                     # Entry point
├── index.html
├── package.json
├── tsconfig.json
└── vite.config.ts
```

## ✅ Реализованный функционал

### Авторизация
- [x] Telegram Login Widget интеграция
- [x] JWT токены (access + refresh)
- [x] Автоматическое обновление токенов
- [x] Logout функционал
- [x] Protected routes

### Header (шапка)
- [x] Логотип "FIRST CALL" (SVG)
- [x] Кнопка "Поддержка" → https://t.me/firstcall_support
- [x] Кнопка "Биллинг"
- [x] Информация о подписке (дата, остаток дней)
- [x] Статус автозвонка (переключение через API)
- [x] Аватар пользователя с инициалами
- [x] Dropdown меню (Профиль, Настройки, Выход)

### Dashboard (главная)
- [x] Компактная статистика (баннеры)
- [x] Фильтры в скрываемой карточке
- [x] MultiSelect компоненты для фильтров
- [x] DatePicker (flatpickr)
- [x] Таблица объявлений (компактный режим)
- [x] 25 моковых записей с московскими адресами
- [x] Колонка "Метро" с цветами линий
- [x] Телефон кликабельный (tel:)
- [x] Иконки действий при наведении
- [x] Пагинация + кнопки "Столбцы" и "Экспорт"

### Стилизация
- [x] Палитра AssetFinder (оливково-зелёный + золотой)
- [x] Шрифт Plus Jakarta Sans
- [x] Material Icons
- [x] Адаптивная вёрстка

## 🔄 В процессе / Следующие шаги

### Приоритет 1: Подключение реальных данных
- [ ] API для получения списка объявлений
- [ ] Реальная статистика из API
- [ ] Работающие фильтры
- [ ] Пагинация с API

### Приоритет 2: Функционал таблицы
- [ ] Сортировка по колонкам
- [ ] Клик по объявлению → детальная информация
- [ ] Кнопка звонка → интеграция с телефонией
- [ ] Избранное

### Приоритет 3: Другие страницы
- [ ] Страница профиля
- [ ] Страница настроек
- [ ] Страница тарифов/биллинга

## 🛠 Технологии

| Технология | Версия | Назначение |
|------------|--------|------------|
| React | 18 | UI библиотека |
| TypeScript | 5 | Типизация |
| Vite | 5 | Сборка и dev server |
| Zustand | 4 | State management |
| Axios | 1 | HTTP клиент |
| flatpickr | 4 | DatePicker |

## 🔧 Конфигурация

### Переменные окружения (.env.local)

```env
VITE_API_URL=/api/v1
VITE_TELEGRAM_BOT_USERNAME=firstcall_service_bot
```

### Vite proxy (vite.config.ts)

```typescript
server: {
  proxy: {
    '/api': {
      target: 'https://local.firstcall.com',
      changeOrigin: true,
      secure: false
    }
  }
}
```

## 📚 Связанная документация

- [Настройка Telegram авторизации](./TELEGRAM_SETUP.md)
- [Backend API](./API-QUICK-REFERENCE.md)
- [Деплой](./DEPLOYMENT.md)
- [Архитектура](./ARCHITECTURE.md)
