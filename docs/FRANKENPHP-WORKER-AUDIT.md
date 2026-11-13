# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/task-board-bundle` (`symfony-bundle`) |
| Audited revision | `v1.5.5` / `22a87cf` |
| Audit date | 2026-09-23 |
| Method | Manual review of every PHP file under `src/` (controller, services, importers, repositories, Doctrine and event listeners, Twig extension, form types, route loader, command, DI extension, compiler pass, `services*.yaml`) |
| Re-audit (2026-11-12, REQ-RUNTIME-002) | 2.11.1: the request memos added since 1.23 (avatars, repeat icons, synced people, change cause, held task events) are cleared at the request, Messenger and console boundaries by `TaskBoardRequestBoundarySubscriber`, not only by `kernel.reset`; one-kernel worker test without the resetter (see [Request memos and boundaries](#request-memos-and-boundaries-2111)) |
| Re-audit (2026-11-12, 2.13.0) | The multi-board list (`multi_board.enabled`) adds no state: `MultiBoardTaskList`, `DoctrineMultiBoardTaskQuery`, `MultiBoardExportTable`, `NullMultiBoardScopeProvider` and the controller are `final readonly` (or have only constructor dependencies); the viewer, the checked boards and every row live in the call, an export's batches in its generator; `XlsxStreamWriter` is built per export. Worker test `tests/Functional/MultiBoard/MultiBoardWorkerTest.php` (see [Multi-board list (2.13.0)](#multi-board-list-2130)) |
| Re-audit (2026-11-12, 2.14.0) | The calendar day annotations add no state: `Calendar\CalendarDayAnnotator` is `final readonly` (provider and entity manager only), the viewer, range, assignees and warnings live in the call; the host's provider must resolve per call. Worker test `tests/Functional/Calendar/CalendarDayAnnotationsWorkerTest.php`: one kernel without the resetter, alice and bob alternate over 300 iterations of the month calendar (600 requests), each page shows only its viewer's labels and warnings, memory < 4 MiB after a 30-iteration warm-up |
| Re-audit (2026-11-12, 2.14.1, audit NM / NL2-v) | The console depth counter of `TaskBoardRequestBoundarySubscriber` could stay above zero after a throwing or propagation-stopping console listener, after which the request memos were never cleared between HTTP requests again. Units of work are now tracked at `PHP_INT_MAX` by `EventListener\UnitOfWorkFrames` (one frame per console run keyed by command and input, saving and restoring the message / request state; failing runs without terminate dropped at the next unit; main requests counted; no request data). Test: `tests/Functional/ConsoleBoundaryCounterTest.php` |
| Re-audit (2026-11-12, 2.15.0, framework-free ports) | The port twins add no state: the gates (`Port\*Gate`) are `final readonly` over the host service (and the boards repository), `Port\Ports`, `Port\Snapshots` and `Port\TaskApiRequestContexts` are static and stateless, and every snapshot, request context and upload value is built per call and never kept. `TaskBoardPortsPass` runs at build time only. The whole functional suite (worker tests included) also runs through the twins (`TASK_BOARD_TEST_PORTS=twin`) |
| Re-audit (2026-11-12, 2.15.0, private entity manager) | With `database.private_entity_manager: true` the bundle's unit of work is `Doctrine\TaskBoardEntityManager`: dropped at every outermost boundary by `TaskBoardRequestBoundarySubscriber` (after the request memos) and at `kernel.reset`, dropped first and then inspected / cleared under guards; recreated when closed, on another connection or after a rollback of rows it wrote; repositories kept per manager (audit NZ factory), so memory stays flat. Its bookkeeping (`current`, `stale`, `uncommitted`, repositories) holds no request or user data. Worker test `tests/Functional/PrivateEntityManager/PrivateEntityManagerWorkerTest.php`: one kernel through `http_kernel->handle()` / `terminate()`, two users, 300 iterations of a write and a read (1,200 requests), no cross-user data, the manager dropped after every request, memory < 4 MiB after a 30-iteration warm-up. The functional suite also runs with the option on (`phpunit.private-em.xml.dist`) |
| Re-audit (2026-11-12, 2.15.0, user references and XML mapping) | `User\TaskBoardUsers` keeps the host users found for references (`database.user_reference: id`) for the current unit of work only: it implements `ResetInterface` (reset tested in `TaskBoardUsersTest`) and is cleared at every request, Messenger and console boundary by `TaskBoardRequestBoundarySubscriber` (registered in its state list, before the private entity manager). The column type, the reference value object and the XML mapping hold no state. `DddHostKernelTest` runs the DDD host (plain users, XML mapping, private manager, references by id) through the pages |
| Remediation (2026-09-25) | W-01, W-03 (positions), W-04, W-05 resolved; W-02 resolved for access decisions and timer aggregation (refresh) with identity-map clearing for rendered pages left to the host; closed EntityManagers are reset by the bundle after failed flushes (`Doctrine\RecoveringFlusher`); regression tests simulate consecutive requests without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — bundle services hold no per-request state, a failed flush no longer leaves the worker with a closed EntityManager, time-tracking access and timer aggregation reload managed entities before deciding/mutating. Clearing Doctrine's identity map between requests (freshness of rendered HTML, memory) remains the application's responsibility |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Request memos and boundaries (2.11.1)](#request-memos-and-boundaries-2111)
- [Multi-board list (2.13.0)](#multi-board-list-2130)
- [Findings](#findings)
  - [W-01 — Unique-constraint violations close the EntityManager (Medium)](#w-01--unique-constraint-violations-close-the-entitymanager-medium)
  - [W-02 — Doctrine identity map is never cleared by the bundle (Medium)](#w-02--doctrine-identity-map-is-never-cleared-by-the-bundle-medium)
  - [W-03 — Imports run synchronously inside the HTTP request (Low)](#w-03--imports-run-synchronously-inside-the-http-request-low)
  - [W-04 — Route loader throws if it runs twice in the same process (Low)](#w-04--route-loader-throws-if-it-runs-twice-in-the-same-process-low)
  - [W-05 — `strtok()` keeps global state (Low)](#w-05--strtok-keeps-global-state-low)
  - [Igor findings (2026-11-11)](#igor-findings-2026-11-11)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Five request memos (see [Request memos and boundaries](#request-memos-and-boundaries-2111)), cleared at every boundary by the bundle since 2.11.1; every other service, repository and listener is `final readonly` or has no properties |
| Static properties / `static` locals | ✅ | Only pure static helpers (`SlugGenerator`, `UserIdResolver`, `Uuid`); no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ | The five memos implement `ResetInterface` (each `reset()` is tested) and are also cleared by the boundary subscriber, so they do not rely on `kernel.reset`; closed-manager recovery no longer depends on DoctrineBundle's reset (W-01) |
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

## Request memos and boundaries (2.11.1)

Scenario B (no services resetter: a host that removes, replaces or bypasses `services_resetter` or drives `http_kernel`
directly, `messenger:consume --no-reset`, long-running commands) is covered by the bundle itself. With the stock
Symfony kernel the resetter still runs before every request, even with `FRANKENPHP_RESET_KERNEL=false`.

| Service | State | Key | Cleared by |
|---------|-------|-----|------------|
| `Avatar\TaskMemberAvatars` | avatar URLs of the people a page shows | user | boundary subscriber + `reset()` |
| `Recurrence\TaskRecurrenceView` (`recurrence.enabled`) | ids of the recurring tasks per board | board | boundary subscriber + `reset()` |
| `Sync\View\SyncPeopleViewProvider` (`sync.enabled`) | the sync row of each task shown | task | boundary subscriber + `reset()` |
| `Service\TaskChangeContext` | the cause stack of the running write | call (popped in `finally`) | boundary subscriber + `reset()` (safety net) |
| `Service\TaskEventDeferral` | depth and held events of the running write | call (commit / rollBack) | boundary subscriber + `reset()` (safety net) |

`EventListener\TaskBoardRequestBoundarySubscriber` (registered by the bundle) calls their `reset()` on:

- main `kernel.request` (`PHP_INT_MAX`) and `kernel.terminate` (priority -4096), only for the outermost unit: not inside
  a Messenger message, a console command or another main request (a page rendered in-process keeps the outer unit and
  its open `defer()`); sub-requests share the main request's memos;
- Messenger `WorkerMessageReceivedEvent` (`PHP_INT_MAX`) and `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`
  (-4096);
- `console.command` (`PHP_INT_MAX`) and `console.terminate` (-4096) of the outermost run only, outside a message and a
  main request: a command run in-process (`Application::doRun()`) from another command, a message handler or a
  controller does not clear the state of the unit that runs it. Since 2.14.1 (audit NM / NL2-v) the bookkeeping is
  `UnitOfWorkFrames`, changed at `PHP_INT_MAX`: one frame per console run keyed by command and input (removed by its
  own `console.terminate`; marked by `console.error` and dropped at the next unit when no terminate follows), a message
  flag (unset on handled / failed / skipped / worker stopped) and a main-request counter.

It receives the memos as a lazy iterator of `IGNORE_ON_UNINITIALIZED` references: a boundary never builds a service.

**Worker test:** `tests/Functional/WorkerStateTest.php` boots one `FunctionalKernel` (built as production: no query
logging), drives `http_kernel` directly and consumes with a Messenger `Worker` that has no `ResetServicesListener`.
Two users, alice and bob, each with their own board, alternate over **500 iterations**: each board page as its owner
plus the other owner's board (403), i.e. 1,500 requests, and a message of one user that succeeds and one of the other
that fails half-way with an open deferral, i.e. 1,000 messages. The avatar resolver returns a new URL every iteration.
It asserts that every page and message shows its own user's current avatar and nothing of the other user, that a task
event is never held by a deferral an earlier message left open, that every memo is empty after each unit of work, and
that memory after a cycle collection grows less than **4 MiB** after a 50-iteration warm-up. The test host clears its
own entity manager at each boundary (REQ-RUNTIME-002 rule 7). The same class also runs a nested `doRun()` command, an in-process request inside a message with an open `defer()`, the
services resetter and `clear()`; `tests/Unit/EventListener/TaskBoardRequestBoundarySubscriberTest.php` covers each
boundary. Without the subscriber the worker test fails at its first iteration.

**Static checks:** `composer igor` (CI job `igor`) and the PHPStan FrankenPHP worker ruleset pass.

## Multi-board list (2.13.0)

| Service | State | Notes |
|---------|-------|-------|
| `MultiBoard\MultiBoardTaskList` | none (`final readonly`) | Asks the host's provider once per call, loads the boards, re-checks `canViewBoard()`; the user is loaded by id per call |
| `MultiBoard\DoctrineMultiBoardTaskQuery` | none (`final readonly`) | Array hydration only: tasks never enter the identity map; the assignees' users do (one query per page or batch), cleared by the host per rule 7 |
| `MultiBoard\Export\MultiBoardExportTable` | none | Translator per call |
| `MultiBoard\Export\XlsxStreamWriter` | per file | `new` per export inside the response callback, never a service |
| `Controller\TaskBoardMultiBoardController` | none | The viewer is built from `getUser()` and the request per action |

The host's `MultiBoardScopeProviderInterface` must resolve per call too (stated on the interface).

**Worker test:** `tests/Functional/MultiBoard/MultiBoardWorkerTest.php` boots one `FunctionalKernel` built as production
(`TASK_BOARD_TEST_DEBUG=0`), drives `http_kernel` directly without the services resetter, and alternates alice and bob
(two boards each in their own scope) over **300 iterations** of the list, the workload with a label filter and a CSV
export of each (1,800 requests). Each response must hold its own user's tasks and none of the other's tasks, boards or
e-mail; memory after a cycle collection grows less than **4 MiB** after a 30-iteration warm-up. The test host clears its
entity manager at each boundary (REQ-RUNTIME-002 rule 7).

## AI Flow features (2.16.0)

| Service | State | Notes |
|---------|-------|-------|
| `Transition\*ColumnTransitionPolicy`, `ValueObject\ColumnTransition*` | none (`final readonly`) | Snapshots copied per call; a host policy must keep no state between calls (stated on the interface) |
| `Doctrine\TaskRevisionListener` | none | Reads only the flushing unit of work |
| `Api\Operation\TaskApiTransaction` | none | Every refusal of an update with a base revision or of a bulk update is checked before the first write; a failed write rolls back, drops the held events, resets a manager the flush closed (`RecoveringFlusher::resetIfClosed`) and reloads the touched tasks, so the next request never sees a closed manager or a rolled-back value (audit OG-1 lesson) |
| `Search\DoctrineTaskTextSearch`, `Search\TaskTextSearchMetadataListener` | none | One DBAL query per search; scalar ids only, nothing enters the identity map |
| `Support\TextFold` | none (static) | |

**Worker test:** `tests/Functional/Api/ApiAiFlowWorkerTest.php` drives `http_kernel` directly (`handle()` and
`terminate()`), no services resetter, and alternates two tokens of two tenants over 100 iterations of a refused bulk
update (stale revision), an accepted one, a text search and a member lookup: each answer holds its own tenant's data,
the manager stays open, and the revision goes up by one per accepted write.

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

### Igor findings (2026-11-11)

`composer igor` (igor-php 0.10.1) reported ten KO (eleven with 2.12.0). All are false positives, annotated inline with `// @igor-ignore`
(CI runs `composer igor` since 2.11.1):

| Where | Igor says | Why it is safe |
|-------|-----------|----------------|
| `Api/Operation/TaskApiBoardOperations::createColumn()`, `Api/Operation/TaskApiTaskOperations` (comments, dependencies), `Api/Controller/TaskApiWriteController` | mutation of an injected dependency | `BoardColumnManager`, `TaskCommentManager`, `TaskDependencyManager` and `TaskApiTaskOperations` are `final readonly`: the call writes through Doctrine and keeps nothing in the service. |
| `Api/Controller/TaskApiManagementController::deleteBoard()` | mutation of a shared `$response` | The `JsonResponse` is built per call by `answer()`. |
| `Api/Repository/TaskApiQueryRepository::taskPage()` | mutation of a shared `$qb` | `filtered()` creates a new `QueryBuilder` per call. |
| `Copy/TaskCopier::copyChecked()`, `Recurrence/TaskRecurrenceManager` | closure capturing local state injected into a shared service | `wrapInTransaction()` runs the closure at once and does not keep it. |
| `Api/Security/TaskApiRequestListener` | mutation of the injected rate limiter | Counting failed tokens across requests is the purpose of the host rate limiter (its storage bounds it); the default `DenyingTaskApiRateLimiter` is `readonly` and keeps nothing. |
| `Api/TaskApiInput::take()` | mutation of `read` | `TaskApiInput` is a per-request value object built by `fromRequest()`; not a service. |
| `Api/Operation/TaskApiTimeOperations::updateTime()` (2.12.0) | mutation of the injected time store | `setBillable()` writes through `TaskTimeBillableService` (Doctrine); `ManualTaskApiTimeEntries` is `final readonly` and keeps nothing. The new billable service, ports and TimeTrack bridges are `final readonly` (or have no properties) and are asked per call. |
| `Api/Doctrine/JsonTextFunction::parse()` | mutation of `field` | A DQL function node: Doctrine creates one per parsed query; it is not a service. |

## Usage recommendations in worker mode

- Under scenario B the bundle recovers a closed EntityManager and clears its own request memos (2.11.1) by itself, but the host must still clear the identity map of its entity managers at every boundary (main request, Messenger message, console command) (e.g. `EntityManager::clear()` on `kernel.terminate`) to avoid stale pages and memory growth; keeping DoctrineBundle's `kernel.reset` hook (scenario A) does this.
- Custom `access_checker`, `team_membership_resolver` or `TaskImportUserResolverInterface` implementations must stay stateless or implement `ResetInterface`. Do not cache the current user, roles or memberships in a property.
- Prefer the CLI command or an async handler for big imports; if imports stay in HTTP, set `max_wait_time` and consider `max_requests` / `FRANKENPHP_LOOP_MAX` to limit memory growth.
- Do not extend the bundle services with memoized lookups (for example a board-by-id cache) without a reset hook.

## Re-audit triggers

Re-run this audit when a change adds: mutable properties to any service or repository, a cache of boards, tasks or memberships, an event listener or subscriber that buffers data, a new unique constraint without a matching validation, a new HTTP client or external call in importers, or any use of `$_SERVER` / `$_ENV` at runtime.
