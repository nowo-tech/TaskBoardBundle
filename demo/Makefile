.PHONY: help up-symfony8 down-symfony8 update-bundle-symfony8 release-check

help:
	@echo "TaskBoard Bundle — Demos"
	@echo ""
	@echo "  up-symfony8            Start Symfony 8 demo (port 8024)"
	@echo "  down-symfony8          Stop demo"
	@echo "  update-bundle-symfony8 Sync bundle in demo"
	@echo "  release-check          Demo healthcheck"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

release-check:
	@$(MAKE) up-symfony8
	@$(MAKE) update-bundle-symfony8
	@curl -sf http://localhost:8024/tools/tasks >/dev/null && echo "Symfony 8 demo healthcheck OK" || (echo "Symfony 8 demo healthcheck failed" && exit 1)
