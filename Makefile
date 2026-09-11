# MicroHelium Makefile
# Docker commands for development and production

.PHONY: help build up down restart logs shell migrate seed fresh test lint format deploy rollback smoke

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
	@echo "  make deploy      - Build+tag a new image, roll it out, run smoke tests"
	@echo "  make rollback PREV=<tag> - Re-point :prod at a previously built image, no rebuild"
	@echo "  make smoke       - Run post-deploy smoke checks against the live stack"

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

# IMAGE_TAG defaults to :prod here (not :latest) so `make prod`/`prod-up`
# share the same tag universe as `make deploy`/`rollback` below -- otherwise
# `prod-up` would run :latest while `rollback` only knows about :prod/sha
# tags, and a rollback after a plain `prod-up` would silently swap in
# whatever :prod last happened to reference instead of anything related to
# what's actually running. Override with `make prod-up IMAGE_TAG=<tag>` if
# you need a specific tag.
IMAGE_TAG ?= prod
PROD_COMPOSE = docker compose -f docker-compose.yml -f docker-compose.prod.yml

prod:
	@IMAGE_TAG=$(IMAGE_TAG) $(PROD_COMPOSE) build

prod-up:
	@IMAGE_TAG=$(IMAGE_TAG) $(PROD_COMPOSE) up -d

prod-down:
	@$(PROD_COMPOSE) down

# =============================================================================
# Deploy / Rollback (issue #54)
#
# Every build is tagged with the git sha that produced it (microhelium-app,
# microhelium-judge), so a rollback is just re-pointing the ":prod" tag at a
# previously built image and restarting -- no rebuild, no migration replay.
# `docker images microhelium-app` / `microhelium-judge` lists what's
# available to roll back to.
# =============================================================================

# := (not ?=/lazy) so this is computed once per `make` invocation instead of
# re-running `git rev-parse` on every one of the several references below.
DEPLOY_TAG := $(shell git rev-parse --short HEAD)

deploy:
	@echo "Building images tagged $(DEPLOY_TAG)..."
	@IMAGE_TAG=$(DEPLOY_TAG) $(PROD_COMPOSE) build app autojudge
	@echo "Promoting $(DEPLOY_TAG) to :prod..."
	@docker tag microhelium-app:$(DEPLOY_TAG) microhelium-app:prod
	@docker tag microhelium-judge:$(DEPLOY_TAG) microhelium-judge:prod
	@echo "Starting db/redis/app/webserver and running migrations before touching background workers..."
	@IMAGE_TAG=prod $(PROD_COMPOSE) up -d --wait --wait-timeout 120 db redis app webserver
	@$(PROD_COMPOSE) exec -T app php artisan migrate --force
	@echo "Migrations applied. Starting queue/scheduler/autojudge on the new image..."
	@IMAGE_TAG=prod $(PROD_COMPOSE) up -d --wait --wait-timeout 120 queue scheduler autojudge
	@echo "Deployed $(DEPLOY_TAG). Running smoke tests..."
	@$(MAKE) smoke || (echo "" && echo "Smoke tests FAILED after deploying $(DEPLOY_TAG)." && echo "Roll back with: make rollback PREV=<previous-tag>" && exit 1)
	@echo "Deploy of $(DEPLOY_TAG) verified healthy."

# NOTE: this re-points images and restarts containers only -- it does NOT
# revert database migrations. If the deploy being rolled back included a
# schema-breaking migration (a dropped/renamed column the old code still
# reads), rolling back the image alone will not restore compatibility; you
# must also manually run the down migration(s) for that deploy first.
rollback:
ifndef PREV
	$(error Usage: make rollback PREV=<git-sha-tag>. List available tags with: docker images microhelium-app)
endif
	@echo "Rolling back :prod to $(PREV) (no rebuild, migrations NOT reverted -- see Makefile comment)..."
	@docker tag microhelium-app:$(PREV) microhelium-app:prod
	@docker tag microhelium-judge:$(PREV) microhelium-judge:prod
	@IMAGE_TAG=prod $(PROD_COMPOSE) up -d --no-build --wait --wait-timeout 120
	@echo "Rolled back to $(PREV). Running smoke tests..."
	@$(MAKE) smoke
	@echo "Rollback to $(PREV) verified healthy."

smoke:
	@./scripts/smoke.sh

# =============================================================================
# Cleanup
# =============================================================================

clean:
	@docker compose down -v --remove-orphans
	@docker system prune -f

clean-all:
	@docker compose down -v --remove-orphans --rmi all
	@docker system prune -af --volumes
