# Demo notes (FrankenPHP)

- [Start the demo in development](#start-the-demo-in-development)
- [Start the demo in production mode](#start-the-demo-in-production-mode)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Troubleshooting](#troubleshooting)

**REQ-DEMO-001:** FrankenPHP demos must install **Nowo Twig Inspector** and **Nowo Hot Reload** together (`nowo-tech/twig-inspector-bundle` + `nowo-tech/hot-reload-bundle` in `require-dev`). Caddyfile: Mercure + `hot_reload` (and `worker { file …; watch }` in worker mode). Do not enable Hot Reload in production.

This bundle includes `demo/symfony8` with a sample Symfony application (boards UI + optional TimeTrack integration).

Each demo has its own `docker-compose.yml`, `Dockerfile`, and FrankenPHP Caddyfile variants for local development.

The **repository root** `docker-compose.yml` is for **bundle** development (PHP, Composer, pnpm/Vite, tests). It is not the same as launching a demo as a standalone hosted app.

To run the demo: `make -C demo up-symfony8` (port **8024** by default).

This bundle is **FrankenPHP worker mode friendly**. Demos default to `FRANKENPHP_MODE=worker`.

Full audit (scenario B = kernel not reset between requests): [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

### Host checklist for `reset_kernel: false`

- Prefer keeping DoctrineBundle's `services_resetter` / `kernel.reset` when possible (scenario A).
- If the kernel is never reset, clear the EntityManager between requests (e.g. `EntityManager::clear()` on `kernel.terminate`) so kanban/list pages do not serve a stale identity map, and set `max_requests` / `FRANKENPHP_LOOP_MAX` to bound memory.
- Custom `access_checker`, `team_membership_resolver`, and `TaskImportUserResolverInterface` implementations must stay stateless (or implement `ResetInterface`).
- Large imports: prefer `nowo:task-board:import` or async processing; set FrankenPHP `max_wait_time` if imports stay in HTTP.

## Start the demo in development

```bash
make -C demo up-symfony8        # or: cd demo/symfony8 && make up
```

`make up` copies `.env.example` to `.env` (first run), installs Composer dependencies in an ephemeral container,
starts FrankenPHP + MySQL, recreates the schema and loads the fixtures (a demo user is signed in automatically).
Open `http://127.0.0.1:8024/tools/task-board` (port: `PORT` in `.env`). The web port is published on the loopback
interface only.

Development defaults: `APP_ENV=dev`, `APP_DEBUG=true`, Twig cache off (`config/packages/dev/twig.yaml`),
`docker/php-dev.ini` (`opcache.revalidate_freq=0`), Symfony WebProfiler, Twig Inspector and Hot Reload (Mercure in
the Caddyfile). Set `FRANKENPHP_MODE=classic` for a plain `php_server` (`Caddyfile.dev`) while you edit PHP.

After changing bundle code: `make -C demo/symfony8 update-bundle` (dump-autoload, `assets:install`, cache clear).
After changing the bundle's TypeScript: `make assets` at the repository root (the demo serves the built files through
`assets:install --symlink`).

## Start the demo in production mode

The demo is a development showcase, but you can run it the way a host runs FrankenPHP in production:

1. In `demo/symfony8/.env` set `APP_ENV=prod`, `APP_DEBUG=false`, a random `APP_SECRET` and keep
   `FRANKENPHP_MODE=worker` (the default `Caddyfile`: `php_server { worker { file /app/public/index.php; watch } }`).
2. Recreate the container so the environment is reloaded: `cd demo/symfony8 && docker compose up -d`.
3. Warm the cache: `docker compose exec php php bin/console cache:clear --env=prod` (Hot Reload, Twig Inspector and the
   WebProfiler are dev-only bundles and are not loaded in `prod`).

Do not expose the demo publicly: its fixtures sign every visitor in as the demo user.



From the repository root:

```bash
make demo-smoke
```

Boots `demo/symfony8`, asserts **HTTP 200** on `http://127.0.0.1:<PORT>/tools/task-board` (from `.env` / `.env.example`, default **8024**), then tears down. CI: `.github/workflows/demo-smoke.yml`.

Per-demo: `make -C demo/symfony8 verify`.

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Keep the worker Caddyfile (`php_server { worker ... }`) |
| **`classic`** | Entrypoint uses classic/`Caddyfile.dev` (plain `php_server`, hot-reload friendly) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `composer install` fails with `Could not resolve host: repo.packagist.org` (curl error 6) | Docker / WSL DNS inside the container | The compose file sets `dns: 8.8.8.8, 8.8.4.4` on the PHP service (REQ-DEMO-009); behind a corporate network use your resolvers in a local `docker-compose.override.yml` |
| `Bind for 127.0.0.1:8024 failed: port is already allocated` | Another demo or service uses the port | Change `PORT` (and `DEFAULT_URI`) in `.env`, then `make up` |
| `MySQL not reachable from demo PHP container` | MySQL still initialising (first run) or a stale volume | Wait and rerun `make up`; reset with `docker compose down -v` (drops the demo data) |
| Bundle PHP changes do not show up | Worker mode keeps the kernel in memory / stale cache | `make update-bundle`; in worker mode the `watch` directive restarts workers, otherwise `docker compose restart php` |
| Board drag and drop / dialogs do nothing | Bundle assets not built or not installed | `make assets` at the root, then `make -C demo/symfony8 update-bundle` |
| A change to `.env` is ignored | `restart` does not reload `env_file` | `docker compose up -d` recreates the container |
| HTTP 500 after switching `APP_ENV` | Cache built for the other environment | `docker compose exec php rm -rf var/cache/*` |
| Kanban shows stale data in `worker` mode with `reset_kernel: false` | Doctrine identity map kept across requests | See the host checklist above (clear the EntityManager on `kernel.terminate`) |
