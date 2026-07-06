# TaskBoard Bundle - Development
.PHONY: help up down build shell install test test-coverage test-coverage-100 coverage-php-percent test-ts cs-check cs-fix qa clean ensure-up rector rector-dry phpstan release-check composer-sync update validate

COMPOSE_FILE ?= docker-compose.yml
COMPOSE     ?= /usr/bin/docker compose -f $(COMPOSE_FILE)
SERVICE_PHP ?= php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

help:
	@echo "TaskBoard Bundle - Development Commands"
	@echo ""
	@echo "  up / down / build / shell / install"
	@echo "  test / test-coverage / test-ts / cs-check / cs-fix / phpstan / qa"
	@echo "  release-check / composer-sync"
	@echo ""
	@echo "Demo (with TimeTrack): make -C TimeTrackBundle/demo up-symfony8"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 3
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(COMPOSE) up -d; sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --coverage-html coverage --coverage-clover coverage.xml --coverage-text
	@$(MAKE) coverage-php-percent

coverage-php-percent:
	@php scripts/check-coverage.php coverage.xml --min-percent=0 2>/dev/null || true

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-100

test-ts: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) sh -c 'command -v pnpm >/dev/null && pnpm install && pnpm run test:coverage' || echo "Run pnpm test:coverage on the host if pnpm is not in the container."

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/php-cs-fixer fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/rector process

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/rector process --dry-run --no-progress-bar

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpstan analyse --memory-limit=512M

qa: cs-check test

release-check: ensure-up composer-sync cs-check rector-dry phpstan test

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: composer-sync

clean:
	rm -rf vendor coverage .phpunit.cache .php-cs-fixer.cache

include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
