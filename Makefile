# TaskBoard Bundle - Development
.PHONY: help up down build shell install test test-coverage test-coverage-100 coverage-php-percent test-ts cs-check cs-fix qa clean ensure-up rector rector-dry phpstan release-check composer-sync update validate dev-composer-file resolve-composer-file check-no-cursor-coauthor strip-cursor-coauthor-from-history

COMPOSE_FILE ?= docker-compose.yml
COMPOSE     ?= /usr/bin/docker compose -f $(COMPOSE_FILE)
SERVICE_PHP ?= php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

dev-composer-file:
	@if [ -d ../TimeTrackBundle ]; then \
		$(COMPOSE) exec -T $(SERVICE_PHP) php -r '$$j=json_decode(file_get_contents("/app/composer.json"),true);$$j["repositories"]=[["type"=>"path","url"=>"/var/time-track-bundle","options"=>["symlink"=>true,"versions"=>["nowo-tech/time-track-bundle"=>"1.0.0"]]]];$$j["require-dev"]["nowo-tech/time-track-bundle"]="^1.0";file_put_contents("/app/composer.dev.json",json_encode($$j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");'; \
		echo "Using composer.dev.json (path repo → ../TimeTrackBundle)"; \
	else \
		rm -f composer.dev.json; \
	fi

resolve-composer-file: dev-composer-file
	@if [ -f composer.dev.json ]; then \
		echo "COMPOSER_FILE=composer.dev.json" > .composer-file.env; \
	else \
		echo "COMPOSER_FILE=composer.json" > .composer-file.env; \
	fi

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
	@$(MAKE) resolve-composer-file
	@. ./.composer-file.env; $(COMPOSE) exec -T -e COMPOSER=/app/$$COMPOSER_FILE $(SERVICE_PHP) composer update --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(MAKE) up; \
	else \
		$(MAKE) resolve-composer-file; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	@. ./.composer-file.env; $(COMPOSE) exec -T -e COMPOSER=/app/$$COMPOSER_FILE $(SERVICE_PHP) composer install --no-interaction

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --coverage-html coverage --coverage-clover coverage.xml --coverage-text | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

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

release-check: check-no-cursor-coauthor ensure-up composer-sync cs-check rector-dry phpstan test

composer-sync: ensure-up
	@. ./.composer-file.env; $(COMPOSE) exec -T -e COMPOSER=/app/$$COMPOSER_FILE $(SERVICE_PHP) composer validate --strict
	@. ./.composer-file.env; $(COMPOSE) exec -T -e COMPOSER=/app/$$COMPOSER_FILE $(SERVICE_PHP) composer install --no-interaction

update: ensure-up
	@. ./.composer-file.env; $(COMPOSE) exec -T -e COMPOSER=/app/$$COMPOSER_FILE $(SERVICE_PHP) composer update --no-interaction

validate: composer-sync

clean:
	rm -rf vendor coverage .phpunit.cache .php-cs-fixer.cache composer.dev.json composer.json.tmp .composer-file.env

include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD
setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
