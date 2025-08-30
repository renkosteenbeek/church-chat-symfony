.PHONY: help
help:
	@echo "Church Chat Symfony - Available commands:"
	@echo "  make up                     - Start all containers"
	@echo "  make down                   - Stop all containers"
	@echo "  make build                  - Build containers"
	@echo "  make restart                - Restart all containers"
	@echo "  make logs                   - Show container logs"
	@echo "  make shell                  - Enter app container shell"
	@echo "  make composer-install       - Install composer dependencies"
	@echo "  make composer-update        - Update composer dependencies"
	@echo "  make composer-require PKG=x - Add new package"
	@echo "  make migrate                - Run database migrations"
	@echo "  make cache-clear            - Clear Symfony cache"
	@echo "  make test                   - Run tests"
	@echo "  make test-chat              - Test chat service (all scenarios)"
	@echo "  make test-chat-input INPUT= - Test single chat input"
	@echo "  make test-chat-scenario SC= - Test specific scenario"

.PHONY: up
up:
	docker-compose up -d

.PHONY: down
down:
	docker-compose down

.PHONY: build
build:
	docker-compose build

.PHONY: restart
restart: down up

.PHONY: logs
logs:
	docker-compose logs -f

.PHONY: shell
shell:
	docker exec -it church-chat-app sh

.PHONY: composer-install
composer-install:
	docker exec church-chat-app composer install

.PHONY: composer-update
composer-update:
	docker exec church-chat-app composer update

.PHONY: composer-require
composer-require:
	docker exec church-chat-app composer require $(PKG)

.PHONY: migrate
migrate:
	docker exec church-chat-app php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: cache-clear
cache-clear:
	docker exec church-chat-app php bin/console cache:clear

.PHONY: test
test:
	docker exec church-chat-app php bin/phpunit

.PHONY: test-chat
test-chat:
	docker exec church-chat-app php bin/console app:test-chat --all --format=json

.PHONY: test-chat-input
test-chat-input:
	@if [ -z "$(INPUT)" ]; then echo "Usage: make test-chat-input INPUT='Your message here'"; exit 1; fi
	docker exec church-chat-app php bin/console app:test-chat --input="$(INPUT)" --format=json

.PHONY: test-chat-scenario
test-chat-scenario:
	@if [ -z "$(SC)" ]; then echo "Usage: make test-chat-scenario SC=scenario_name"; exit 1; fi
	docker exec church-chat-app php bin/console app:test-chat --scenario="$(SC)" --format=json