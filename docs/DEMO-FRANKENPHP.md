# Demo notes

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

## Demo smoke (REQ-TEST-011)

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
