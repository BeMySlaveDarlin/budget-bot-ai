include .env

.PHONY: help install build up start stop restart logs shell composer status clean reset health migrate seed

help:
	@echo "Available commands:"
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*##/ { printf "  %-20s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

install: build up composer migrate seed ## Full install

build: ## Build containers
	docker compose build

up: ## Start containers
	docker compose up -d

start: up ## Alias for up

stop: ## Stop containers
	docker compose down

restart: stop start ## Restart containers

logs: ## Show app logs
	docker compose logs -f app

logs-all: ## Show all logs
	docker compose logs -f

shell: ## Enter app container
	docker compose exec app bash

composer: ## Run composer install
	docker compose run --rm composer

status: ## Show container status
	docker compose ps

health: ## Check health endpoint
	curl -s http://localhost:$(SWOOLE_PORT)/health

migrate: ## Run migrations
	docker compose exec app php bin/cli.php migrate

migrate-fresh: ## Drop all tables and re-run migrations
	docker compose exec app php bin/cli.php migrate --fresh

migrate-status: ## Show migration status
	docker compose exec app php bin/cli.php migrate --status

seed: ## Run seeders
	docker compose exec app php bin/cli.php seed

rates: ## Update exchange rates
	docker compose exec app php bin/cli.php exchange:update

webhook-set: ## Set Telegram webhook
	docker compose exec app php bin/cli.php webhook:set

webhook-delete: ## Delete Telegram webhook
	docker compose exec app php bin/cli.php webhook:delete

webhook-info: ## Get Telegram webhook info
	docker compose exec app php bin/cli.php webhook:info

llm-test: ## Test LLM provider
	docker compose exec app php bin/cli.php llm:test

clean: stop ## Stop and remove volumes
	docker compose down -v --remove-orphans

reset: clean install ## Full reset
