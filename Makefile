.PHONY: up down build restart logs ps migrate composer install create-dirs

# Создание необходимых директорий
create-dirs:
	mkdir -p logs

# Команды для разработки
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