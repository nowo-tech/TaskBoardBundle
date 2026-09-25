# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/task-board-bundle` (`symfony-bundle`) |
| Audited revision | `v1.5.5` / `22a87cf` |
| Audit date | 2026-09-23 |
| Method | Manual review of every PHP file under `src/` (controller, services, importers, repositories, Doctrine and event listeners, Twig extension, form types, route loader, command, DI extension, compiler pass, `services*.yaml`) |
| Remediation (2026-09-25) | W-01, W-03 (positions), W-04, W-05 resolved; W-02 resolved for access decisions and timer aggregation (refresh) with identity-map clearing for rendered pages left to the host; closed EntityManagers are reset by the bundle after failed flushes (`Doctrine\RecoveringFlusher`); regression tests simulate consecutive requests without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — bundle services hold no per-request state, a failed flush no longer leaves the worker with a closed EntityManager, time-tracking access and timer aggregation reload managed entities before deciding/mutating. Clearing Doctrine's identity map between requests (freshness of rendered HTML, memory) remains the application's responsibility |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | All services, repositories and listeners are `final readonly` or have no properties; the controller only holds `readonly` dependencies and config |
| Static properties / `static` locals | ✅ | Only pure static helpers (`SlugGenerator`, `UserIdResolver`, `Uuid`); no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ | The bundle has nothing of its own to reset; closed-manager recovery no longer depends on DoctrineBundle's reset (W-01) |
| Request / user / locale captured in services | ✅ | The user comes from `getUser()` per action and is passed as a method argument; nothing is captured at construction |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ Resolved / host | Slug and member duplicates are prevented and any failed flush resets the closed manager (W-01); access decisions refresh their entities; identity-map clearing stays with the host (W-02) |
| Output, headers, `exit`, shutdown functions | ✅ | None |
| Resources (files, sockets, cURL) held open | ✅ | Uploads are read with `file_get_contents()`; no handles are kept |
| Memory growth across requests | ⚠️ Host | Only through the Doctrine identity map under B if the host never clears it (W-02); `strtok()` removed (W-05) |
| Blocking I/O and timeouts | ⚠️ Low (accepted) | Imports still run synchronously in the HTTP request, but with one position query per import instead of one per row (W-03) |
| Third-party static state | ✅ | FormKit `FormOptionsTrait` only memoizes the profile name from a class attribute (safe); the builder binding is restored in `finally` |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/symfony8/Caddyfile` runs `php_server` with a `worker` block (`FRANKENPHP_MODE=worker` is the default in `docker-compose.yml`).

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Controller\TaskBoardManageController` | yes (public) | none (`readonly` dependencies, routes, templates, user class) | ✅ | ✅ |
| `Service\TaskManager`, `TaskChangeRecorder`, `TaskLinkAttacher`, `TaskMemberAssigner`, `BoardColumnManager`, `TaskBoardCreator`, `TaskGanttBuilder`, `TaskAccessGuard` | yes | none (`final readonly`) | ✅ | ✅ (`TaskAccessGuard` refreshes task/board before deciding) |
| `Import\TaskImportOrchestrator` | yes | none (`final readonly`; per-call arrays only) | ✅ | ✅ (resets closed EM on failed flush; identity map: host) |
| 4 importers (`ClickUpCsvImporter`, `ClickUpJsonImporter`, `JiraCsvImporter`, `TrelloJsonImporter`) + `DelimitedTableParser`, `ImportFieldMapper`, `NullTaskImportUserResolver` | yes | none | ✅ | ✅ |
| 10 `DoctrineOrm*Repository` classes | yes | none (`readonly` EntityManager reference) | ✅ | ✅ (flushing repositories reset a closed EM; identity map: host) |
| `Doctrine\TaskBoardMetadataListener` (`loadClassMetadata`) | yes | none (`readonly` table names / user class) | ✅ | ✅ |
| `EventListener\TimeSpentAggregatorListener` (TimeTrack bridge only) | yes | none | ✅ | ✅ (refreshes managed task before mutating `total_time_seconds`) |
| `Bridge\TimeTrack\TaskBoardTaskProvider`, `TaskBoardTeamContextProvider` | yes | none | ✅ | ✅ (`canTrack` refreshes; team context uses DQL for membership sets) |
| `Security\ConfigurableTaskBoardAccessChecker` / `AllowAllTaskBoardAccessChecker` / `NullTaskBoardTeamMembershipResolver` | yes | none (role lists are config) | ✅ | ✅ |
| `Twig\TaskBoardTwigExtension` | yes | none (two config strings returned by `getGlobals()`) | ✅ | ✅ |
| `Routing\TaskBoardRouteLoader` | yes | none (`$loaded` guard removed, W-04) | ✅ | ✅ |
| 6 form types | yes | FormKit trait memo of the `#[FormKitConfig]` name (same value for every request) | ✅ | ✅ |
| `Command\TaskBoardImportCommand` | CLI only | none | N/A | N/A |

Entities, DTOs, enums and events are created per request and are never stored in a service property.

## Findings

### W-01 — Unique-constraint violations close the EntityManager (Medium)

- **Where:** `src/Service/TaskBoardCreator.php:30-50` builds a board with a user-supplied or generated slug and flushes it, while `src/Entity/TaskBoard.php:43` declares `slug` as `unique: true`. There is no uniqueness check (`UniqueEntity` or lookup) before the flush, so two boards with the same name produce a `UniqueConstraintViolationException`. The same applies to `src/Service/TaskMemberAssigner.php:28-49` (assigning the same user and role twice) against the unique constraint in `src/Entity/TaskMember.php:15`, and to the batch `flush()` in `src/Import/TaskImportOrchestrator.php:164-166`. Repositories get the manager injected directly (`src/DependencyInjection/TaskBoardExtension.php:255`).
- **Worker impact:** after a failed flush, Doctrine closes the EntityManager. Under **A**, DoctrineBundle's `kernel.reset` hook resets the closed manager before the next request, so only the failing request returns an error. Under **B**, the manager stays closed, and every later request on that worker that touches Doctrine fails with "The EntityManager is closed" until the worker restarts.
- **Recommendation:** validate before flushing (a `UniqueEntity`-style check on the slug, or look up an existing member before `addMember()`), or catch the exception and call `ManagerRegistry::resetManager()`. Never run this bundle under B without the Doctrine resetter.
- **Status:** Resolved — `src/Service/TaskBoardCreator.php` appends `-2`, `-3`, … when `findBySlug()` finds the slug; `src/Service/TaskMemberAssigner.php` returns the existing member for the same user and role. As a safety net for races and any other failure, new `src/Doctrine/RecoveringFlusher.php` wraps `flush()` in the flushing repositories (`DoctrineOrmTaskBoardRepository`, `DoctrineOrmBoardColumnRepository`, `DoctrineOrmTaskRepository`, plus the unregistered `DoctrineOrmTaskChangeHistoryRepository` / `DoctrineOrmTeamRepository`) and `TaskImportOrchestrator`: on failure it resets the closed manager through `ManagerRegistry::resetManager()` and rethrows. `TaskBoardExtension::registerRepositories()` injects `doctrine` (null on invalid) as `$managerRegistry`. Tests: `tests/Unit/Doctrine/RecoveringFlusherTest.php` (incl. two consecutive saves on the same repository instance), `TaskManagerCrudTest::testTaskBoardCreatorSuffixesTakenSlugInsteadOfHittingTheUniqueIndex`, `::testMemberAssignerReturnsExistingMemberForSameUserAndRole`, `TaskImportOrchestratorTest::testFailedFlushResetsClosedEntityManager`, `TaskBoardExtensionTest`.

### W-02 — Doctrine identity map is never cleared by the bundle (Medium)

- **Where:** `src/Repository/DoctrineOrmTaskRepository.php:28` and `src/Repository/DoctrineOrmTaskBoardRepository.php:34` use `EntityManager::find()`, which returns the already managed instance without querying when the entity is in the identity map. DQL queries (`findByBoard()`, `findByUserId()`, `findTrackableForUser()`) hydrate into existing managed objects without refreshing them. Imports persist every row in one request (`src/Import/TaskImportOrchestrator.php:112-162`).
- **Worker impact:** under **A**, the Doctrine resetter clears the manager between requests, so there is no problem. Under **B**, entities loaded in one request survive into the next one: a task or board edited by another worker (or by another PHP process) is served with stale columns, members and positions, and `TaskAccessGuard::canTrack()` (`src/Service/TaskAccessGuard.php:20-41`) can decide on stale assignee and team data. The identity map also grows with every board viewed and every import, with no upper bound.
- **Recommendation:** rely on DoctrineBundle's `kernel.reset` (scenario A). If B cannot be avoided, the host must call `EntityManager::clear()` at the end of every request (for example on `kernel.terminate`) and cap the number of requests per worker.
- **Status:** Resolved for security-relevant and mutation paths, Accepted for rendered pages — `src/Service/TaskAccessGuard.php` refreshes the task (and board) before `canTrack()`; `src/EventListener/TimeSpentAggregatorListener.php` refreshes a managed task before `addTimeSeconds()` so concurrent totals are not overwritten. Team context queries memberships via DQL. The bundle deliberately does not call `EntityManager::clear()` on the application's manager: freshness of rendered pages and identity-map memory under B remain the host's responsibility (clear on `kernel.terminate` or keep `services_resetter`). Tests: `TaskAccessGuardTest::testSecondRequestWithoutResetSeesAssigneeRemovedByAnotherWorker`, `::testRefreshesTaskAndBoardBeforeDeciding`, `::testSkipsRefreshForUnmanagedTaskOrBoardOrClosedManager`, `TimeSpentAggregatorListenerTest::testRefreshesManagedTaskBeforeAddingDuration`.

### W-03 — Imports run synchronously inside the HTTP request (Low)

- **Where:** `src/Controller/TaskBoardManageController.php:201-255` reads the whole upload with `file_get_contents()` (line 222). `src/Form/TaskImportFormType.php:39` allows files up to 20 MB. `src/Import/TaskImportOrchestrator.php:120` calls `nextPosition()` for every row, and that method runs a full `findByBoard()` query each time (lines 302-312).
- **Worker impact:** a large export keeps one worker thread busy for a long time (the per-row query makes it grow roughly with rows × tasks) and raises peak memory for that request. It does not leak state, but it reduces the capacity of the limited worker pool.
- **Recommendation:** send large imports to the `nowo:task-board:import` command or to a Messenger handler, compute positions once per column instead of once per row, and cap waiting requests with FrankenPHP `max_wait_time`.
- **Status:** Partially resolved, rest Accepted — `src/Import/TaskImportOrchestrator.php` loads the board's tasks once per import and tracks the highest position per column in a local array (this also fixes imported rows in the same column getting identical positions, since unflushed tasks were invisible to the per-row query). Moving HTTP imports to async processing is a product decision; the CLI command remains the recommended path for large files. Tests: `TaskImportOrchestratorTest::testComputesPositionsOncePerImportAndIncrementsPerColumn`, `::testPositionsWithoutAnyColumnUseTheWholeBoard`.

### W-04 — Route loader throws if it runs twice in the same process (Low)

- **Where:** `src/Routing/TaskBoardRouteLoader.php:18` and `:31-35` (`$loaded` flag, `RuntimeException('TaskBoard routes already loaded.')`). The flag is never reset.
- **Worker impact:** in production the route cache is built once, so this does not matter. With `kernel.debug` enabled, the router rebuilds its cache in the same process when a routing resource changes, and the shared loader then throws. The demo uses `watch`, which restarts workers on file changes, so this is unlikely to show up there.
- **Recommendation:** remove the `$loaded` guard (it is not needed for a custom loader type), or restart workers after route changes in development.
- **Status:** Resolved — guard removed from `src/Routing/TaskBoardRouteLoader.php`; `load()` builds a fresh collection each call. Test: `TaskBoardRouteLoaderTest::testCanLoadRoutesAgainInTheSameProcess`.

### W-05 — `strtok()` keeps global state (Low)

- **Where:** `src/Import/Support/DelimitedTableParser.php:77` uses `strtok($content, "\r\n")` to read the first line.
- **Worker impact:** `strtok()` keeps its input string in PHP's global state until the next `strtok()` call or the end of the PHP request. In a long-lived worker, the last CSV upload (up to 20 MB) may therefore stay in memory. The amount is bounded to one string, and I have not measured whether FrankenPHP releases it between worker requests.
- **Recommendation:** read the first line with `strcspn()` / `substr()` instead of `strtok()`.
- **Status:** Resolved — `src/Import/Support/DelimitedTableParser.php` uses `strspn()` / `strcspn()` / `substr()` (leading blank lines are still skipped, as `strtok()` did). Covered by the new import tests (leading `\r\n`, semicolon delimiter).

No other findings. The bundle holds no per-user state in services, so there is no cross-user leak under A.

## Usage recommendations in worker mode

- Under scenario B the bundle recovers a closed EntityManager by itself, but the host must still clear the identity map between requests (e.g. `EntityManager::clear()` on `kernel.terminate`) to avoid stale pages and memory growth; keeping DoctrineBundle's `kernel.reset` hook (scenario A) does this.
- Custom `access_checker`, `team_membership_resolver` or `TaskImportUserResolverInterface` implementations must stay stateless or implement `ResetInterface`. Do not cache the current user, roles or memberships in a property.
- Prefer the CLI command or an async handler for big imports; if imports stay in HTTP, set `max_wait_time` and consider `max_requests` / `FRANKENPHP_LOOP_MAX` to limit memory growth.
- Do not extend the bundle services with memoized lookups (for example a board-by-id cache) without a reset hook.

## Re-audit triggers

Re-run this audit when a change adds: mutable properties to any service or repository, a cache of boards, tasks or memberships, an event listener or subscriber that buffers data, a new unique constraint without a matching validation, a new HTTP client or external call in importers, or any use of `$_SERVER` / `$_ENV` at runtime.
