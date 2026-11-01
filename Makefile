# TaskBoard Bundle - Development
SHELL := /bin/bash
.PHONY: assets help up down down-dev build shell install test test-coverage test-coverage-100 coverage-php-percent test-ts cs-check cs-fix qa clean ensure-up rector rector-dry phpstan igor release-check release-check-demos demo-smoke composer-sync update validate validate-translations setup-hooks check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history check-twig-extra check-no-raw-html-form

COMPOSE_FILE ?= docker-compose.yml
# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell PATH="/usr/local/bin:/usr/bin:/bin:$$PATH" command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE_BIN ?= docker-compose
else
COMPOSE_BIN ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
COMPOSE     ?= $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP ?= php
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

help:
	@echo "TaskBoard Bundle - Development Commands"
	@echo ""
	@echo "Container:     up / down / down-dev / build / shell"
	@echo "Dependencies:  install"
	@echo "Assets:        assets              Build the Stimulus/Vite assets (pnpm build -> src/Resources/public)"
	@echo "Tests:         test / test-coverage / test-coverage-100 / test-ts (Vitest + global TS %)"
	@echo "Quality:       cs-check / cs-fix / rector / rector-dry / phpstan / igor / qa / twig-lint"
	@echo "Release:       release-check / composer-sync"
	@echo "Cleanup:       clean"
	@echo "Composer:      update / update-deps / validate"
	@echo ""
	@echo "Bundle:"
	@echo "  validate-translations     YAML syntax + key parity of the 7 locales against en (REQ-MAKE-004)"
	@echo "  check-twig-extra          Twig Extra installed and enabled (REQ-TWIG-004)"
	@echo "  check-no-raw-html-form    Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  check-no-cursor-coauthor / check-open-prs / setup-hooks"
	@echo ""
	@echo "Demos:"
	@echo "  demo-smoke / release-check-demos   (make -C demo up-symfony8 | make -C demo help)"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 3
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

down-dev:
	$(COMPOSE) down --remove-orphans

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(MAKE) up; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --coverage-html coverage --coverage-clover coverage.xml --coverage-text | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-100

# REQ-TEST-009: Vitest coverage, then one global TS % (min of Statements/Branches/Functions/Lines).
test-ts: ensure-up
	@set -o pipefail; $(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) sh -c 'pnpm install --frozen-lockfile && pnpm run test:coverage' | tee coverage-ts.txt
	@chmod +x .scripts/ts-coverage-percent.sh
	@./.scripts/ts-coverage-percent.sh coverage-ts.txt

# REQ-MAKE-001: build the bundle's Stimulus/Vite assets into src/Resources/public (committed build output).
assets: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'pnpm install --frozen-lockfile && pnpm run build'

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

qa: cs-check twig-lint test

# REQ-MAKE-004 / REQ-I18N-002: YAML syntax and key parity of every locale against en (non-zero exit on any gap).
validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

release-check-demos:
	@if [ -d demo ]; then $(MAKE) -C demo release-check; fi

demo-smoke:
	@$(MAKE) -C demo demo-smoke


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor

# REQ-MAKE-002 order (coverage gate: test-coverage-100).
release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form composer-sync cs-fix cs-check rector-dry phpstan igor validate-translations test-coverage-100 release-check-demos test-ts

# REQ-TWIG-005 — no raw <form>/<input>/<textarea>/<select> in bundle views or demo templates.
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

composer-sync: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction

update: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: composer-sync

clean:
	rm -rf vendor coverage .phpunit.cache .php-cs-fixer.cache composer.json.tmp

# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
