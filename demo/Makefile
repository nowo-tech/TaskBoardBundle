.PHONY: test-symfony8 help up-symfony8 down-symfony8 update-bundle-symfony8 release-check verify demo-smoke verify-symfony8

help:
	@echo "TaskBoard Bundle — Demos"
	@echo ""
	@echo "  up-symfony8            Start Symfony 8 demo (port 8024)"
	@echo "  down-symfony8          Stop demo"
	@echo "  update-bundle-symfony8 Sync bundle in demo"
	@echo "  verify / demo-smoke    REQ-TEST-011: HTTP 200 on /tools/task-board"
	@echo "  test-symfony8          update-bundle + demo PHPUnit suite"
	@echo "  release-check          up + update-bundle + tests + healthcheck, then down (REQ-DEMO-007)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

verify-symfony8:
	@$(MAKE) -C symfony8 verify

verify:
	@$(MAKE) verify-symfony8

# REQ-TEST-011: boot primary demo + HTTP 200 on manage UI
demo-smoke:
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8024; \
	echo "Demo smoke: symfony8 (http://127.0.0.1:$$PORT/tools/task-board)"; \
	$(MAKE) -C symfony8 up || exit 1; \
	code=000; \
	i=0; \
	while [ $$i -lt 30 ]; do \
		code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/tools/task-board" || true); \
		[ -z "$$code" ] && code=000; \
		if [ "$$code" = "200" ]; then break; fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	$(MAKE) -C symfony8 down 2>/dev/null || true; \
	if [ "$$code" != "200" ]; then echo "symfony8 failed with HTTP $$code"; exit 1; fi; \
	echo "symfony8 OK (HTTP $$code)"

test-symfony8:
	@$(MAKE) -C symfony8 test

# REQ-DEMO-007: up + update-bundle + demo tests + healthcheck, then the stack goes down.
release-check:
	@$(MAKE) -C symfony8 release-check; status=$$?; $(MAKE) -C symfony8 down 2>/dev/null || true; exit $$status
