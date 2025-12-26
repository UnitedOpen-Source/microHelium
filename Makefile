# MicroHelium Makefile
# Docker commands for development and production

.PHONY: help build up down restart logs shell migrate seed fresh test lint format

# Default target
help:
	@echo "MicroHelium Docker Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make setup       - Initial project setup"
	@echo "  make build       - Build Docker images"
	@echo "  make up          - Start all containers"
	@echo "  make down        - Stop all containers"
	@echo "  make restart     - Restart all containers"
	@echo ""
	@echo "Development:"
	@echo "  make dev         - Start with development tools (Vite, Mailpit)"
	@echo "  make logs        - View container logs"
	@echo "  make shell       - Open shell in app container"
	@echo "  make shell-db    - Open MySQL shell"
	@echo "  make shell-redis - Open Redis CLI"
	@echo ""
	@echo "Laravel:"
	@echo "  make migrate     - Run database migrations"
	@echo "  make seed        - Run database seeders"
	@echo "  make fresh       - Fresh migrate with seeders"
	@echo "  make key         - Generate application key"
	@echo "  make cache       - Clear all caches"
	@echo "  make optimize    - Optimize application"
	@echo ""
	@echo "Queue:"
	@echo "  make queue       - Start queue worker"
	@echo "  make judge       - Start auto-judge worker"
	@echo ""
	@echo "Testing:"
	@echo "  make test        - Run PHPUnit tests"
	@echo "  make lint        - Run PHP linter"
	@echo "  make format      - Format code with Pint"
	@echo ""
	@echo "Production:"
	@echo "  make prod        - Build for production"
	@echo "  make prod-up     - Start production containers"

# =============================================================================
# Setup
# =============================================================================

setup: build
	@echo "Creating .env file..."
	@cp -n .env.docker .env 2>/dev/null || true
	@echo "Starting containers..."
	@docker compose up -d db redis
	@echo "Waiting for database..."
	@sleep 10
	@docker compose up -d
	@echo "Generating application key..."
	@docker compose exec app php artisan key:generate
	@echo "Running migrations..."
	@docker compose exec app php artisan migrate --force
	@echo "Creating storage link..."
	@docker compose exec app php artisan storage:link
	@echo ""
	@echo "Setup complete! Access the application at http://localhost:8000"

build:
	@docker compose build

up:
	@docker compose up -d

down:
	@docker compose down

restart: down up

# =============================================================================
# Development
# =============================================================================

dev:
	@docker compose --profile dev up -d

dev-tools:
	@docker compose --profile dev --profile tools up -d

logs:
	@docker compose logs -f

logs-app:
	@docker compose logs -f app

logs-queue:
	@docker compose logs -f queue

logs-judge:
	@docker compose logs -f autojudge

shell:
	@docker compose exec app sh

shell-root:
	@docker compose exec -u root app sh

shell-db:
	@docker compose exec db mysql -u microhelium -psecret microhelium

shell-redis:
	@docker compose exec redis redis-cli

# =============================================================================
# Laravel Commands
# =============================================================================

migrate:
	@docker compose exec app php artisan migrate

seed:
	@docker compose exec app php artisan db:seed

fresh:
	@docker compose exec app php artisan migrate:fresh --seed

key:
	@docker compose exec app php artisan key:generate

cache:
	@docker compose exec app php artisan cache:clear
	@docker compose exec app php artisan config:clear
	@docker compose exec app php artisan route:clear
	@docker compose exec app php artisan view:clear

optimize:
	@docker compose exec app php artisan optimize
	@docker compose exec app php artisan view:cache
	@docker compose exec app php artisan event:cache

tinker:
	@docker compose exec app php artisan tinker

# =============================================================================
# Queue & Judge
# =============================================================================

queue:
	@docker compose exec app php artisan queue:work --verbose

queue-restart:
	@docker compose exec app php artisan queue:restart

judge:
	@docker compose exec autojudge php artisan autojudge:start --sleep=5

# =============================================================================
# Testing & Code Quality
# =============================================================================

test:
	@docker compose exec app php artisan test

test-coverage:
	@docker compose exec app php artisan test --coverage

lint:
	@docker compose exec app ./vendor/bin/pint --test

format:
	@docker compose exec app ./vendor/bin/pint

# =============================================================================
# Assets
# =============================================================================

npm-install:
	@docker compose exec node npm install

npm-dev:
	@docker compose exec node npm run dev

npm-build:
	@docker compose exec node npm run build

# =============================================================================
# Production
# =============================================================================

prod:
	@docker compose -f docker-compose.yml -f docker-compose.prod.yml build

prod-up:
	@docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

prod-down:
	@docker compose -f docker-compose.yml -f docker-compose.prod.yml down

# =============================================================================
# Cleanup
# =============================================================================

clean:
	@docker compose down -v --remove-orphans
	@docker system prune -f

clean-all:
	@docker compose down -v --remove-orphans --rmi all
	@docker system prune -af --volumes
