.PHONY: up down build restart logs ps migrate composer install create-dirs dev dev-stop frontend frontend-stop docs docs-stop

# Создание необходимых директорий
create-dirs:
	mkdir -p logs

# ===========================================
# ГЛАВНАЯ КОМАНДА - ЗАПУСК ВСЕГО ПРОЕКТА
# ===========================================

# Запуск всего: бэкенд + БД + фронтенд + документация
dev:
	@echo "🚀 Запуск First Call CRM..."
	@echo ""
	@echo "📦 Запуск Docker контейнеров (бэкенд, БД, nginx)..."
	@./start-dev.sh
	@echo ""
	@echo "⏳ Ожидание запуска контейнеров..."
	@sleep 3
	@echo ""
	@echo "📚 Запуск документации API (Redoc)..."
	@docker-compose -f docker-compose.redoc.yml up -d 2>/dev/null || true
	@echo ""
	@echo "⚛️  Запуск React фронтенда..."
	@cd frontend-react && npm install --silent 2>/dev/null && npm run dev &
	@sleep 2
	@echo ""
	@echo "✅ Всё запущено!"
	@echo ""
	@echo "🌐 Доступные адреса:"
	@echo "   • Backend API:    https://local.firstcall.com/api/v1"
	@echo "   • Frontend:       http://localhost:5173"
	@echo "   • API Docs:       http://localhost:8080/redoc.html"
	@echo "   • pgAdmin:        http://localhost:5050"
	@echo ""
	@echo "💡 Для остановки: make dev-stop"

# Остановка всего
dev-stop:
	@echo "🛑 Остановка всех сервисов..."
	@-pkill -f "vite" 2>/dev/null || true
	@docker-compose -f docker-compose.redoc.yml down 2>/dev/null || true
	@docker-compose down
	@echo "✅ Все сервисы остановлены"

# ===========================================
# ОТДЕЛЬНЫЕ КОМАНДЫ
# ===========================================

# Запуск только фронтенда
frontend:
	@echo "⚛️  Запуск React фронтенда..."
	@cd frontend-react && npm run dev

# Остановка фронтенда
frontend-stop:
	@-pkill -f "vite" 2>/dev/null || true
	@echo "✅ Фронтенд остановлен"

# Запуск документации
docs:
	@echo "📚 Запуск документации API..."
	@docker-compose -f docker-compose.redoc.yml up -d
	@echo "📖 Документация: http://localhost:8080/redoc.html"

# Остановка документации
docs-stop:
	@docker-compose -f docker-compose.redoc.yml down
	@echo "✅ Документация остановлена"

# ===========================================
# DOCKER КОМАНДЫ
# ===========================================

# Запуск Docker контейнеров
up:
	./start-dev.sh

down:
	docker-compose down

build:
	docker-compose build

restart:
	docker-compose restart

logs:
	docker-compose logs -f

ps:
	docker-compose ps

# Миграции БД
migrate:
	@if docker-compose exec php-cli sh -c '[ -f "db/migrations/run.php" ]'; then \
		docker-compose exec php-cli php db/migrations/run.php; \
	else \
		echo "ОШИБКА: Файл миграций db/migrations/run.php не найден"; \
		exit 1; \
	fi

# Команды для работы с Composer
composer:
	docker-compose exec php-cli composer $(filter-out $@,$(MAKECMDGOALS))

# Установка зависимостей
install:
	docker-compose exec php-cli composer install

# Исправление прав доступа
fix-permissions:
	sudo chown -R $(shell whoami):$(shell whoami) .
	sudo chmod +x start-dev.sh fix-permissions.sh

# Команда для запуска проекта с нуля
init: create-dirs build up install 
	-sleep 3
	make migrate

# Позволяет передавать аргументы в команды
%:
	@: 