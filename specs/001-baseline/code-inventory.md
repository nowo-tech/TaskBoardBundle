# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/task-board-bundle`  
**Last audited**: 2026-11-03 (1.22 development: every production file of `src/`, releases 1.10–1.22)

Production scope excludes Vitest sources (`*.test.ts`).

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Assignee/TaskAssigneeProviderInterface.php` | Assignable users per board (since 1.10) | FR-ASSIGNEE-001 |
| `Bridge/TimeTrack/TaskBoardTaskProvider.php` | TimeTrack bridge | FR-BRIDGE-001 |
| `Bridge/TimeTrack/TaskBoardTeamContextProvider.php` | TimeTrack bridge | FR-BRIDGE-001 |
| `Command/TaskBoardImportCommand.php` | Import CLI (`--force` deprecated since 1.22) | FR-CLI-001, FR-IMPORT-013 |
| `Comment/DefaultTaskMentionCandidatesResolver.php` | Comment @mention candidates (since 1.16) | FR-COMMENT-002 |
| `Comment/MentionCandidate.php` | Comment @mention candidates (since 1.16) | FR-COMMENT-002 |
| `Comment/TaskMentionCandidatesResolverInterface.php` | Comment @mention candidates (since 1.16) | FR-COMMENT-002 |
| `Controller/TaskBoardManageController.php` | Manage UI controller (bounded pages, logged writes since 1.21) | FR-CTRL-001, FR-PERF-001, FR-OBS-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace | FR-TWIG-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/TaskBoardExtension.php` | DI extension | FR-CFG-002 |
| `Doctrine/RecoveringFlusher.php` | FrankenPHP worker flush recovery | FR-DOCTRINE-002 |
| `Doctrine/TaskBoardMetadataListener.php` | Table prefix | FR-DOCTRINE-001 |
| `Dto/BoardColumnFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/ColumnSetApplyFormData.php` | Column set form DTO (since 1.11) | FR-DTO-001, FR-COLSET-001 |
| `Dto/ColumnSetFormData.php` | Column set form DTO (since 1.11) | FR-DTO-001, FR-COLSET-001 |
| `Dto/TaskBoardFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskColumnNavigation.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskCommentFormData.php` | Comment form DTO (since 1.16) | FR-DTO-001, FR-COMMENT-001 |
| `Dto/TaskDependencyFormData.php` | Dependency form DTO (since 1.15) | FR-DTO-001, FR-DEP-001 |
| `Dto/TaskFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskGanttItem.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskGanttLink.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskGanttSlot.php` | Gantt timeline column (since 1.15) | FR-DTO-001, FR-GANTT-001 |
| `Dto/TaskGanttTimeline.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskImportFormData.php` | Form/API DTO | FR-DTO-001, FR-IMPORT-013 |
| `Dto/TaskLinkFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskMemberFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskNavigation.php` | Previous / next task (since 1.20) | FR-DTO-001, FR-NAV-001 |
| `Dto/TaskPosition.php` | Previous / next task (since 1.20) | FR-DTO-001, FR-NAV-001 |
| `Dto/TaskTimeEntryFormData.php` | Time entry form DTO (since 1.15) | FR-DTO-001, FR-TIME-001 |
| `Dto/TaskWindow.php` | Shown / total tasks of a capped page (since 1.21) | FR-DTO-001, FR-PERF-001 |
| `Entity/BoardColumn.php` | Domain entity | FR-ENTITY-001 |
| `Entity/ColumnSet.php` | Domain entity: column set (since 1.11) | FR-ENTITY-012, FR-COLSET-001 |
| `Entity/Task.php` | Domain entity (`setParent` since 1.22) | FR-ENTITY-002, FR-IMPORT-008 |
| `Entity/TaskBoard.php` | Domain entity | FR-ENTITY-003 |
| `Entity/TaskChangeHistory.php` | Domain entity | FR-ENTITY-004 |
| `Entity/TaskComment.php` | Domain entity: task comment (since 1.16) | FR-ENTITY-013, FR-COMMENT-001 |
| `Entity/TaskDependency.php` | Domain entity | FR-ENTITY-005 |
| `Entity/TaskDocument.php` | Domain entity | FR-ENTITY-006 |
| `Entity/TaskLink.php` | Domain entity (`import_source` since 1.22) | FR-ENTITY-007, FR-IMPORT-008 |
| `Entity/TaskMember.php` | Domain entity | FR-ENTITY-008 |
| `Entity/TaskTimeEntry.php` | Domain entity | FR-ENTITY-009 |
| `Entity/Team.php` | Domain entity | FR-ENTITY-010 |
| `Entity/TeamMember.php` | Domain entity | FR-ENTITY-011 |
| `Enum/GanttScale.php` | Gantt scales and week start (since 1.15) | FR-ENUM-001, FR-GANTT-001 |
| `Enum/TaskChangeType.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskDependencyType.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskLinkType.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskMemberRole.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskPriority.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskStatus.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TeamRole.php` | Task/board enums | FR-ENUM-001 |
| `Enum/WeekStart.php` | Gantt scales and week start (since 1.15) | FR-ENUM-001, FR-GANTT-001 |
| `Event/AbstractTaskEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `Event/BoardAccessCheckEvent.php` | Board access decision event (default decision overridable by listeners) | FR-EVT-002, FR-SEC-005 |
| `Event/BoardListQueryEvent.php` | Domain event | FR-EVT-002 |
| `Event/ColumnSetAccessCheckEvent.php` | Column set query / access events (since 1.11) | FR-EVT-002, FR-COLSET-001 |
| `Event/ColumnSetQueryEvent.php` | Column set query / access events (since 1.11) | FR-EVT-002, FR-COLSET-001 |
| `Event/TaskAccessCheckEvent.php` | Domain event | FR-EVT-002 |
| `Event/TaskBoardEvents.php` | Event names | FR-EVT-001 |
| `Event/TaskCommentEvent.php` | Comment and mention events (since 1.16) | FR-EVT-003, FR-COMMENT-001 |
| `Event/TaskCompletedEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `Event/TaskCreatedEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `Event/TaskMemberAddedEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `Event/TaskMentionEvent.php` | Comment and mention events (since 1.16) | FR-EVT-003, FR-COMMENT-001 |
| `Event/TaskMovedEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `Event/TaskReopenedEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `Event/TaskUpdatedEvent.php` | Events after writes (since 1.10) | FR-EVT-003 |
| `EventListener/TimeSpentAggregatorListener.php` | TimeTrack aggregation | FR-BRIDGE-002 |
| `Exception/ColumnNotFoundException.php` | Thrown by `TaskManager` when a column is not on the task's board | FR-SVC-007 |
| `Exception/InvalidTaskDependencyException.php` | Refused dependency (since 1.15) | FR-DEP-001 |
| `Exception/UnassignableUserException.php` | Refused assignee (since 1.10) | FR-ASSIGNEE-001 |
| `Form/BoardColumnFormType.php` | Form type | FR-FORM-001 |
| `Form/ColumnSetApplyFormType.php` | Column set form types (since 1.11) | FR-FORM-001, FR-COLSET-001 |
| `Form/ColumnSetFormType.php` | Column set form types (since 1.11) | FR-FORM-001, FR-COLSET-001 |
| `Form/CsrfActionType.php` | CSRF-only action form (REQ-TWIG-005) | FR-FORM-002 |
| `Form/DataTransformer/MinutesToDurationTransformer.php` | Duration estimate "1d 2h 30m" <-> minutes (since 1.20) | FR-FORM-001, FR-DUR-001 |
| `Form/DataTransformer/TagsToStringTransformer.php` | Tags list ↔ comma-separated string (plain-text fallback without TagInputBundle) | FR-FORM-001 |
| `Form/TaskBoardFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskCommentFormType.php` | Comment form type (since 1.16) | FR-FORM-001, FR-COMMENT-001 |
| `Form/TaskDependencyFormType.php` | Dependency form type (since 1.15) | FR-FORM-001, FR-DEP-001 |
| `Form/TaskFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskImportFormType.php` | Form type | FR-FORM-001, FR-IMPORT-013 |
| `Form/TaskLinkFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskListFilterType.php` | List view filters form (since 1.13) | FR-FORM-001, FR-UI-004 |
| `Form/TaskMemberFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskPriorityFormType.php` | Priority form type | FR-FORM-001 |
| `Form/TaskTimeEntryFormType.php` | Time entry form type (since 1.15) | FR-FORM-001, FR-TIME-001 |
| `Gantt/ConfiguredWeekStartResolver.php` | Gantt week start resolver (since 1.15) | FR-GANTT-001 |
| `Gantt/WeekStartResolverInterface.php` | Gantt week start resolver (since 1.15) | FR-GANTT-001 |
| `Import/ClickUp/ClickUpCsvImporter.php` | External importers (Gantt fields, names since 1.22) | FR-IMPORT-005, FR-IMPORT-012 |
| `Import/ClickUp/ClickUpJsonImporter.php` | External importers (Gantt fields, names since 1.22) | FR-IMPORT-005, FR-IMPORT-012 |
| `Import/Dto/ImportedTaskDto.php` | Import DTOs | FR-IMPORT-006, FR-IMPORT-012 |
| `Import/Dto/TaskImportOptions.php` | Import DTOs (`skipExisting` deprecated since 1.22) | FR-IMPORT-006, FR-IMPORT-013 |
| `Import/Dto/TaskImportResult.php` | Import DTOs (updated / unchanged since 1.22) | FR-IMPORT-006, FR-IMPORT-008 |
| `Import/Jira/JiraCsvImporter.php` | External importers | FR-IMPORT-005, FR-IMPORT-012 |
| `Import/DoctrineTaskImportLinkLookup.php` | Cross-board lookup of imported ids (since 1.22) | FR-IMPORT-010, FR-IMPORT-011 |
| `Import/ImportAssigneeResolver.php` | Assignees by e-mail or display name (since 1.22) | FR-IMPORT-012 |
| `Import/ImportedTaskUpdater.php` | Re-import precedence (since 1.22) | FR-IMPORT-008 |
| `Import/NullTaskImportUserResolver.php` | Null user resolver | FR-IMPORT-003 |
| `Import/Support/DelimitedTableParser.php` | Import parsers | FR-IMPORT-004, FR-IMPORT-012 |
| `Import/Support/ImportFieldMapper.php` | Import parsers | FR-IMPORT-004, FR-IMPORT-012 |
| `Import/TaskBoard/TaskBoardCsvImporter.php` | Native TaskBoard CSV / JSON format and subtask import (since 1.18) | FR-IMPORT-005, FR-IMPORT-007 |
| `Import/TaskBoard/TaskBoardJsonImporter.php` | Native TaskBoard CSV / JSON format and subtask import (since 1.18) | FR-IMPORT-005, FR-IMPORT-007 |
| `Import/TaskBoard/TaskBoardRowMapper.php` | Native TaskBoard CSV / JSON format and subtask import (since 1.18) | FR-IMPORT-005, FR-IMPORT-007, FR-IMPORT-012 |
| `Import/TaskImportOrchestrator.php` | Import orchestrator (logged since 1.21, idempotent upsert since 1.22) | FR-IMPORT-001, FR-OBS-001, FR-IMPORT-008, FR-IMPORT-009, FR-IMPORT-010, FR-IMPORT-011 |
| `Import/TaskImportLinkLookupInterface.php` | Import contracts (since 1.22) | FR-IMPORT-010 |
| `Import/TaskImportSource.php` | Import contracts (provider / scope since 1.22) | FR-IMPORT-002, FR-IMPORT-008, FR-IMPORT-011 |
| `Import/TaskImportUserResolverInterface.php` | Import contracts | FR-IMPORT-002 |
| `Import/TaskImporterInterface.php` | Import contracts | FR-IMPORT-002 |
| `Import/Trello/TrelloJsonImporter.php` | External importers | FR-IMPORT-005, FR-IMPORT-012 |
| `Link/TaskObjectBadge.php` | Links to host objects (since 1.10) | FR-LINK-001 |
| `Link/TaskObjectLinkResolver.php` | Links to host objects (since 1.10) | FR-LINK-001 |
| `Link/TaskObjectLinkResolverInterface.php` | Links to host objects (since 1.10) | FR-LINK-001 |
| `Repository/BoardColumnRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/BoundedTaskRepositoryInterface.php` | Bounded board queries and fetch-joined task detail (since 1.21) | FR-REPO-001, FR-PERF-001 |
| `Repository/ColumnSetRepositoryInterface.php` | Column set repository (since 1.11) | FR-REPO-001, FR-REPO-002, FR-COLSET-001 |
| `Repository/DoctrineOrmBoardColumnRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmColumnSetRepository.php` | Column set repository (since 1.11) | FR-REPO-001, FR-REPO-002, FR-COLSET-001 |
| `Repository/DoctrineOrmTaskBoardRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskChangeHistoryRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskCommentRepository.php` | Comment repository (since 1.16) | FR-REPO-001, FR-REPO-002, FR-COMMENT-001 |
| `Repository/DoctrineOrmTaskDependencyRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskDocumentRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskLinkRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskMemberRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskRepository.php` | Doctrine repository (bounded board queries since 1.21) | FR-REPO-002, FR-PERF-001 |
| `Repository/DoctrineOrmTaskTimeEntryRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTeamMemberRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTeamRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/TaskBoardRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskChangeHistoryRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskCommentRepositoryInterface.php` | Comment repository (since 1.16) | FR-REPO-001, FR-REPO-002, FR-COMMENT-001 |
| `Repository/TaskDependencyRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskDocumentRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskLinkRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskMemberRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskTimeEntryRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TeamMemberRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Routing/TaskBoardRouteLoader.php` | Route loader (config-driven names / paths, REQ-SF-004 exemption) | FR-ROUTE-001 |
| `Routing/TenantPathUrlGenerator.php` | Tenant route copies and URL generation (since 1.10) | FR-ROUTE-001, FR-TENANT-001 |
| `Routing/TenantPaths.php` | Tenant route copies and URL generation (since 1.10) | FR-ROUTE-001, FR-TENANT-001 |
| `Security/AllowAllTaskBoardAccessChecker.php` | Demo-only checker when `security.allow_unauthenticated` is true | FR-SEC-002 |
| `Security/ConfigurableTaskBoardAccessChecker.php` | Access control | FR-SEC-002 |
| `Security/NullTaskBoardTeamMembershipResolver.php` | Access control | FR-SEC-004 |
| `Security/TaskBoardAccessCheckerInterface.php` | Access control | FR-SEC-001 |
| `Security/TaskBoardResourceAccessChecker.php` | Per-board / per-task authorization (creator, team member, manage role; event-overridable) | FR-SEC-005 |
| `Security/TaskBoardTeamMembershipResolverInterface.php` | Access control | FR-SEC-003 |
| `Service/BoardColumnManager.php` | Domain service | FR-SVC-001 |
| `Service/ColumnSetManager.php` | Column sets (since 1.11) | FR-COLSET-001 |
| `Service/TaskAccessGuard.php` | Domain service | FR-SVC-002 |
| `Service/TaskBoardCreator.php` | Domain service | FR-SVC-003 |
| `Service/TaskChangeRecorder.php` | Domain service | FR-SVC-004 |
| `Service/TaskCommentManager.php` | Comments and mentions (since 1.16) | FR-COMMENT-001 |
| `Service/TaskDependencyManager.php` | Task dependencies (since 1.15) | FR-DEP-001 |
| `Service/TaskGanttBuilder.php` | Gantt timeline (clock-injected since 1.21) | FR-SVC-005, FR-GANTT-001, FR-CLOCK-001 |
| `Service/TaskLinkAttacher.php` | Domain service | FR-SVC-006 |
| `Service/TaskManager.php` | Domain service | FR-SVC-007 |
| `Service/TaskMemberAssigner.php` | Domain service | FR-SVC-008 |
| `Service/TaskTimeLogger.php` | Manual time entries (since 1.15) | FR-TIME-001 |
| `Support/GitLabMergeRequestLinkParser.php` | Support utilities | FR-SUP-001 |
| `Support/SlugGenerator.php` | Support utilities | FR-SUP-001 |
| `Support/SubtaskProgress.php` | Subtask tree and progress (since 1.10) | FR-TREE-001 |
| `Support/TaskDeviation.php` | Estimate vs spent deviation (since 1.15) | FR-TIME-002 |
| `Support/TaskNavigator.php` | Previous / next task (since 1.20) | FR-NAV-001 |
| `Support/TaskTree.php` | Subtask tree and progress (since 1.10) | FR-TREE-001 |
| `Support/Text.php` | Support utilities | FR-SUP-001 |
| `Support/UserIdResolver.php` | Support utilities | FR-SUP-001 |
| `Support/UserLabel.php` | Support utilities | FR-SUP-001 |
| `TaskBoardBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `Tenancy/SlugTaskBoardTenantResolver.php` | Tenancy (since 1.10) | FR-TENANT-001 |
| `Tenancy/TaskBoardTenant.php` | Tenancy (since 1.10) | FR-TENANT-001 |
| `Tenancy/TaskBoardTenantContext.php` | Tenancy (since 1.10) | FR-TENANT-001 |
| `Tenancy/TaskBoardTenantResolverInterface.php` | Tenancy (since 1.10) | FR-TENANT-001 |
| `Text/PlainTaskTextRenderer.php` | Host text renderer for comments and plain descriptions (since 1.19) | FR-TEXT-001 |
| `Text/TaskTextRendererInterface.php` | Host text renderer for comments and plain descriptions (since 1.19) | FR-TEXT-001 |
| `Twig/TaskBoardTwigExtension.php` | Twig globals for the manage Web UI (REQ-UI-001) | FR-TPL-001 |
| `ValueObject/Uuid.php` | UUID helper | FR-VO-001 |

## TypeScript & CSS (`src/Resources/assets/src/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/activity-filter-controller.ts` | Comments / all activity toggle (since 1.16) | FR-UI-008, FR-COMMENT-001 |
| `Resources/assets/src/add-column-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/add-task-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/board-controller.ts` | Frontend assets | FR-UI-002 |
| `Resources/assets/src/css-vars.ts` | CSP-safe CSS variables from data attributes | FR-UI-008 |
| `Resources/assets/src/dialog-controller.ts` | Native <dialog> modals (since 1.14) | FR-UI-007 |
| `Resources/assets/src/edit-column-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/filter-collapse-controller.ts` | Foldable filter card (since 1.17) | FR-UI-004 |
| `Resources/assets/src/filter-collapse-utils.ts` | Foldable filter card (since 1.17) | FR-UI-004 |
| `Resources/assets/src/gantt-controller.ts` | Frontend assets | FR-UI-003 |
| `Resources/assets/src/gantt-scale.ts` | Gantt scales (since 1.15) | FR-UI-003, FR-GANTT-001 |
| `Resources/assets/src/list-filter-controller.ts` | Frontend assets | FR-UI-004 |
| `Resources/assets/src/list-filter-utils.ts` | Frontend assets | FR-UI-004 |
| `Resources/assets/src/list-sort-utils.ts` | List view sorting | FR-UI-004 |
| `Resources/assets/src/mention-controller.ts` | @mention picker (since 1.16) | FR-UI-008, FR-COMMENT-002 |
| `Resources/assets/src/mention-utils.ts` | @mention picker (since 1.16) | FR-UI-008, FR-COMMENT-002 |
| `Resources/assets/src/modal-toggle.ts` | Native <dialog> modals (since 1.14) | FR-UI-007 |
| `Resources/assets/src/option-filter-controller.ts` | Filterable option lists in dialogs | FR-UI-008 |
| `Resources/assets/src/option-filter-utils.ts` | Filterable option lists in dialogs | FR-UI-008 |
| `Resources/assets/src/picker-controller.ts` | Colour / icon picker (since 1.11) | FR-UI-008, FR-COLSET-001 |
| `Resources/assets/src/storage.ts` | Safe localStorage access | FR-UI-008 |
| `Resources/assets/src/subtask-tree-controller.ts` | Frontend assets | FR-UI-005 |
| `Resources/assets/src/task-board.css` | Frontend assets | FR-UI-010 |
| `Resources/assets/src/task-board.ts` | Frontend assets | FR-UI-001 |
| `Resources/assets/src/task-links-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/task-members-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/task-nav-controller.ts` | Previous / next task keys (since 1.20) | FR-UI-008, FR-NAV-001 |
| `Resources/assets/src/task-nav-utils.ts` | Previous / next task keys (since 1.20) | FR-UI-008, FR-NAV-001 |
| `Resources/assets/src/task-priority-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/task-status-controller.ts` | Frontend assets | FR-UI-006 |
| `Resources/assets/src/time-utils.ts` | Durations and time entries (since 1.15) | FR-UI-008, FR-TIME-001 |

## Legacy assets (`src/Resources/public/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/public/css/task-board.css` | Legacy built assets | FR-LEGACY-001 |
| `Resources/public/js/task-board.js` | Legacy built assets | FR-LEGACY-001 |

## Twig views (`src/Resources/views/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/layout.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/layout_integrate_host.html.twig` | Bridge template for host layout integration (REQ-UI-001) | FR-TPL-001 |
| `Resources/views/manage/_add_column_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_add_task_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_board_column.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_colour_picker.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_column_fields.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_column_icon.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_column_sets_dialogs.html.twig` | Column set dialogs (since 1.11) | FR-TPL-001, FR-COLSET-001 |
| `Resources/views/manage/_disclosure.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_edit_column_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_flashes.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_icon.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_import_help.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_inline_task_add.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_page_header.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_subtask_tree.html.twig` | Subtask tree (since 1.10) | FR-TPL-001, FR-TREE-001 |
| `Resources/views/manage/_task_blocks.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_card.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_column_navigation.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_comment.html.twig` | Comments (since 1.16) | FR-TPL-001, FR-COMMENT-001 |
| `Resources/views/manage/_task_comment_box.html.twig` | Comments (since 1.16) | FR-TPL-001, FR-COMMENT-001 |
| `Resources/views/manage/_task_dependencies_section.html.twig` | Dependencies section (since 1.15) | FR-TPL-001, FR-DEP-001 |
| `Resources/views/manage/_task_history_section.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_links_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_links_section.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_member_label.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_members_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_navigation.html.twig` | Previous / next task (since 1.20) | FR-TPL-001, FR-NAV-001 |
| `Resources/views/manage/_task_priority_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_priority_tag.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_status_field.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_subtask_import.html.twig` | Subtask import (since 1.18) | FR-TPL-001, FR-IMPORT-007 |
| `Resources/views/manage/_task_tags.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_time_section.html.twig` | Time section (since 1.15) | FR-TPL-001, FR-TIME-001 |
| `Resources/views/manage/_task_window_notice.html.twig` | "Showing N of M tasks" (since 1.21) | FR-TPL-001, FR-PERF-001 |
| `Resources/views/manage/board.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/gantt.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/import.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/index.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/list.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/task.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/task_panel.html.twig` | Task panel fragment for side drawers (since 1.11) | FR-TPL-001, FR-PANEL-001 |
| `Resources/views/task_board/index.html.twig` | Twig manage UI | FR-TPL-001 |

## Config & translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |
| `Resources/config/services_timetrack.yaml` | Service wiring | FR-DI-002 |
| `Resources/translations/NowoTaskBoardBundle.de.yaml` | Translations | FR-I18N-001 |
| `Resources/translations/NowoTaskBoardBundle.en.yaml` | Translations | FR-I18N-001 |
| `Resources/translations/NowoTaskBoardBundle.es.yaml` | Translations | FR-I18N-001 |
| `Resources/translations/NowoTaskBoardBundle.fr.yaml` | Translations | FR-I18N-001 |
| `Resources/translations/NowoTaskBoardBundle.it.yaml` | Translations | FR-I18N-001 |
| `Resources/translations/NowoTaskBoardBundle.nl.yaml` | Translations | FR-I18N-001 |
| `Resources/translations/NowoTaskBoardBundle.pt.yaml` | Translations | FR-I18N-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP | 176 | 176 |
| TypeScript & CSS | 31 | 31 |
| Legacy assets | 2 | 2 |
| Twig | 44 | 44 |
| Config & i18n | 9 | 9 |
| **Total production sources** | **262** | **262** |

PHP + TypeScript sources: **206** (all mapped).

Excluded (11 Vitest files): `Resources/assets/src/css-vars.test.ts`, `Resources/assets/src/filter-collapse-utils.test.ts`, `Resources/assets/src/gantt-scale.test.ts`, `Resources/assets/src/list-filter-utils.test.ts`, `Resources/assets/src/list-sort-utils.test.ts`, `Resources/assets/src/mention-utils.test.ts`, `Resources/assets/src/option-filter-utils.test.ts`, `Resources/assets/src/storage.test.ts`, `Resources/assets/src/task-board-css.test.ts`, `Resources/assets/src/task-nav-utils.test.ts`, `Resources/assets/src/time-utils.test.ts`.

Audit command (production count): `git ls-files src | grep -v '\.test\.ts$' | wc -l` → **262** (2026-11-01).
