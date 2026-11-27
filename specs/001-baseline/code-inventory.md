# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/task-board-bundle`  
**Last audited**: 2026-11-26 (3.4.0: client API v1, personal-data export, Forget this person, token page way back; FR-CLIENT-001, FR-PD-001, FR-SYNC-016, FR-SYNC-017); 2026-11-26 (3.3.0: native custom fields from the import and the Custom fields page, FR-FIELD-002); 2026-11-25 (3.2.0: ClickUp import plans, FR-SYNC-015); 2026-11-25 (3.1.0: ClickUp OAuth, FR-SYNC-014); 2026-11-24 (3.0.0: every tracked file of `src/`, releases 1.10–3.0; 2026-11-22: v2.30.0)

Production scope excludes Vitest sources (`*.test.ts`).

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Port/Internal/TaskAssigneeProviderInterface.php` | Assignable users per board (since 1.10) | FR-ASSIGNEE-001 |
| `Command/TaskBoardImportCommand.php` | Import CLI (`--force` deprecated since 1.22) | FR-CLI-001, FR-IMPORT-013 |
| `Comment/DefaultTaskMentionCandidatesResolver.php` | Comment @mention candidates (since 1.16) | FR-COMMENT-002 |
| `Comment/MentionCandidate.php` | Comment @mention candidates (since 1.16) | FR-COMMENT-002 |
| `Port/Internal/TaskMentionCandidatesResolverInterface.php` | Comment @mention candidates (since 1.16) | FR-COMMENT-002 |
| `Controller/TaskBoardManageController.php` | Manage UI controller (bounded pages, logged writes since 1.21) | FR-CTRL-001, FR-PERF-001, FR-OBS-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace | FR-TWIG-001 |
| `DependencyInjection/Configuration.php` | Config tree (`import.*` since 1.22) | FR-CFG-001, FR-IMPORT-015 |
| `DependencyInjection/TaskBoardExtension.php` | DI extension | FR-CFG-002, FR-IMPORT-015 |
| `Doctrine/RecoveringFlusher.php` | FrankenPHP worker flush recovery | FR-DOCTRINE-002 |
| `Doctrine/BundleEntityDetacher.php` | Detach only TaskBoard rows from a shared manager (REQ-RUNTIME-003 rule 2, 2.27.0) | FR-DOCTRINE-002 |
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
| `Import/ImportAssigneeResolver.php` | Assignees by e-mail or display name (since 1.22) | FR-IMPORT-012, FR-IMPORT-014 |
| `Import/ImportedTaskUpdater.php` | Re-import precedence (since 1.22) | FR-IMPORT-008 |
| `Import/NullTaskImportUserResolver.php` | Null user resolver | FR-IMPORT-003 |
| `Import/Support/DelimitedTableParser.php` | Import parsers (row / cell limits since 1.22) | FR-IMPORT-004, FR-IMPORT-012, FR-IMPORT-015 |
| `Import/Support/ImportFieldMapper.php` | Import parsers | FR-IMPORT-004, FR-IMPORT-012 |
| `Import/TaskBoard/TaskBoardCsvImporter.php` | Native TaskBoard CSV / JSON format and subtask import (since 1.18) | FR-IMPORT-005, FR-IMPORT-007 |
| `Import/TaskBoard/TaskBoardJsonImporter.php` | Native TaskBoard CSV / JSON format and subtask import (since 1.18) | FR-IMPORT-005, FR-IMPORT-007 |
| `Import/TaskBoard/TaskBoardRowMapper.php` | Native TaskBoard CSV / JSON format and subtask import (since 1.18) | FR-IMPORT-005, FR-IMPORT-007, FR-IMPORT-012 |
| `Import/TaskImportOrchestrator.php` | Import orchestrator (logged since 1.21, idempotent upsert since 1.22) | FR-IMPORT-001, FR-OBS-001, FR-IMPORT-008, FR-IMPORT-009, FR-IMPORT-010, FR-IMPORT-011, FR-IMPORT-014, FR-IMPORT-015 |
| `Port/Internal/TaskImportLinkLookupInterface.php` | Import contracts (since 1.22) | FR-IMPORT-010 |
| `Import/TaskImportSource.php` | Import contracts (provider / scope since 1.22) | FR-IMPORT-002, FR-IMPORT-008, FR-IMPORT-014 |
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
| `Routing/ControllerAttributeRouteLoader.php` | Internal reader of the controllers' `#[Route]` attributes (since 2.21.1, REQ-SF-004) | FR-ROUTE-001 |
| `Routing/TaskBoardRouteLoader.php` | Route loader: `#[Route]` attributes (since 2.21.1) with config-driven names / paths, feature switches and tenant copies (REQ-SF-004) | FR-ROUTE-001 |
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
| `Service/TaskLinkAttacher.php` | Domain service (import links keep their key since 1.22) | FR-SVC-006, FR-IMPORT-011 |
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

## Provider sync additions in 1.27.0 (`src/`)

Files added by the 1.27.0 stack. The coverage summary below is the 2026-11-03 audit; earlier sync code is described in `docs/sync.md`.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ConnectionPeopleFormType.php` | People page | FR-SYNC-PEOPLE-001 |
| `Resources/views/manage/connection_people.html.twig` | People page | FR-SYNC-PEOPLE-001 |
| `Resources/views/manage/_connection_people_summary.html.twig` | People page | FR-SYNC-PEOPLE-001 |
| `Sync/Event/PeopleMappingChangedEvent.php` | People page | FR-SYNC-PEOPLE-001 |
| `Port/Internal/PeopleInviterInterface.php` | People page | FR-SYNC-PEOPLE-001 |
| `Sync/People/PeopleMappingResult.php` | People page | FR-SYNC-PEOPLE-001 |
| `Sync/People/PeopleMappingRow.php` | People page | FR-SYNC-PEOPLE-001 |
| `Sync/People/PeopleMappingService.php` | People page | FR-SYNC-PEOPLE-001 |
| `Sync/Entity/TaskSyncField.php` | Typed custom fields | FR-SYNC-FIELD-001 |
| `Sync/Entity/TaskSyncFieldValue.php` | Typed custom fields | FR-SYNC-FIELD-001 |
| `Sync/Field/SyncCustomFieldWriter.php` | Typed custom fields | FR-SYNC-FIELD-001, FR-SYNC-PRIVACY-001 |
| `Sync/Field/SyncNativeFieldWriter.php` | Native fields from the import (3.3.0) | FR-FIELD-002 |
| `Sync/Field/SyncFieldType.php` | Typed custom fields | FR-SYNC-FIELD-001 |
| `Sync/Provider/ClickUp/ClickUpCustomFieldParser.php` | Typed custom fields | FR-SYNC-FIELD-001 |
| `Sync/Provider/RemoteCustomField.php` | Typed custom fields | FR-SYNC-FIELD-001 |
| `Sync/View/SyncFieldViewProvider.php` | Typed custom fields and search | FR-SYNC-FIELD-001, FR-SYNC-DETAIL-001, FR-SYNC-VIEW-001 |
| `Resources/views/manage/_task_sync_fields.html.twig` | Typed custom fields | FR-SYNC-FIELD-001 |
| `Sync/People/PersonToken.php` | People in read-only data | FR-SYNC-PRIVACY-001 |
| `Sync/Field/SyncFieldValueEraser.php` | Erasure and access of field values | FR-SYNC-PRIVACY-002 |
| `Sync/Field/SyncFieldSuppression.php` | Erasure and access of field values | FR-SYNC-PRIVACY-002 |
| `Sync/Provider/ReplyBudget.php` | Request budgets | FR-SYNC-BUDGET-001 |
| `Sync/Provider/ThreadedCommentProviderInterface.php` | Request budgets | FR-SYNC-BUDGET-001 |
| `Sync/Provider/RemoteStatusTime.php` | Task details | FR-SYNC-DETAIL-001 |
| `Sync/Provider/RemoteTaskDetails.php` | Task details | FR-SYNC-DETAIL-001 |
| `Sync/Provider/StatusHistoryProviderInterface.php` | Task details | FR-SYNC-DETAIL-001 |
| `Sync/SyncTaskDetailsWriter.php` | Task details and report | FR-SYNC-DETAIL-001, FR-SYNC-PRIVACY-001, FR-SYNC-REPORT-001 |
| `Resources/views/manage/_task_sync_details.html.twig` | Task details | FR-SYNC-DETAIL-001 |
| `Sync/Provider/FieldCoverageProviderInterface.php` | Completeness report | FR-SYNC-REPORT-001 |
| `Resources/views/manage/_connection_completeness.html.twig` | Completeness report | FR-SYNC-REPORT-001 |

## TypeScript & CSS (`src/Resources/assets/src/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/activity-filter-controller.ts` | Comments / all activity toggle (since 1.16) | FR-UI-008, FR-COMMENT-001 |
| `Resources/assets/src/add-column-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/add-task-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/board-controller.ts` | Frontend assets | FR-UI-002 |
| `Resources/assets/src/css-vars.ts` | CSP-safe CSS variables from data attributes | FR-UI-008 |
| `Resources/assets/src/edit-column-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/gantt-controller.ts` | Frontend assets | FR-UI-003 |
| `Resources/assets/src/gantt-scale.ts` | Gantt scales (since 1.15) | FR-UI-003, FR-GANTT-001 |
| `Resources/assets/src/list-filter-controller.ts` | Frontend assets | FR-UI-004 |
| `Resources/assets/src/list-filter-utils.ts` | Frontend assets | FR-UI-004 |
| `Resources/assets/src/list-sort-utils.ts` | List view sorting | FR-UI-004 |
| `Resources/assets/src/mention-controller.ts` | @mention picker (since 1.16) | FR-UI-008, FR-COMMENT-002 |
| `Resources/assets/src/mention-utils.ts` | @mention picker (since 1.16) | FR-UI-008, FR-COMMENT-002 |
| `Resources/assets/src/option-filter-controller.ts` | Filterable option lists in dialogs | FR-UI-008 |
| `Resources/assets/src/option-filter-utils.ts` | Filterable option lists in dialogs | FR-UI-008 |
| `Resources/assets/src/picker-controller.ts` | Colour / icon picker (since 1.11) | FR-UI-008, FR-COLSET-001 |
| `Resources/assets/src/storage.ts` | Safe localStorage access | FR-UI-008 |
| `Resources/assets/src/subtask-tree-controller.ts` | Frontend assets | FR-UI-005 |
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
| `Resources/views/manage/_add_column_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_add_task_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_board_column.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_colour_picker.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_column_fields.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_column_icon.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_column_sets_dialogs.html.twig` | Column set dialogs (since 1.11) | FR-TPL-001, FR-COLSET-001 |
| `Resources/views/manage/_disclosure.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_edit_column_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_icon.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_import_help.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_inline_task_add.html.twig` | Twig manage UI | FR-TPL-001 |
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

## Additions 1.23–2.20 (API, sync, ports, numbers, calendar, recurrence, folders, fields, templates)

Grouped by feature; each row names the semantic requirement of [`spec.md`](spec.md#additions-123220).

| Source file | Requirement IDs |
| --- | --- |
| `Api/Command/TaskApiPurgeIdempotencyCommand.php` | FR-API-002 |
| `Api/Controller/TaskApiIdentityController.php` | FR-API-001 |
| `Api/Controller/TaskApiManagementController.php` | FR-API-001 |
| `Api/Controller/TaskApiReadController.php` | FR-API-001 |
| `Api/Controller/TaskApiTemplateController.php` | FR-API-001 |
| `Api/Controller/TaskApiWriteController.php` | FR-API-001 |
| `Api/Doctrine/JsonTextFunction.php` | FR-API-002 |
| `Api/Entity/TaskApiIdempotencyRecord.php` | FR-API-002 |
| `Api/Hook/AuthorOnlyTaskApiCommentEditPolicy.php` | FR-API-003 |
| `Api/Hook/DenyingTaskApiAttachments.php` | FR-API-003 |
| `Api/Hook/DenyingTaskApiPlanGate.php` | FR-API-003 |
| `Api/Hook/DenyingTaskApiRateLimiter.php` | FR-API-003 |
| `Api/Hook/DenyingTaskApiTimeEntries.php` | FR-API-003 |
| `Api/Hook/EmptyTaskApiMembers.php` | FR-API-003 |
| `Api/Hook/EmptyTaskApiUsers.php` | FR-API-003 |
| `Api/Hook/EmptyTaskApiWorkspace.php` | FR-API-003 |
| `Api/Hook/ManualTaskApiTimeEntries.php` | FR-API-003 |
| `Api/Hook/TaskApiAttachmentStoreInterface.php` | FR-API-003 |
| `Port/Internal/TaskApiAttachmentsInterface.php` | FR-API-003 |
| `Port/Internal/TaskApiAuthenticatorInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiCommentEditPolicyInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiIdempotencyStoreInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiMembersInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiPlanGateInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiRateLimiterInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiRequestAuthenticatorInterface.php` | FR-API-003 |
| `Port/Internal/TaskApiTimeEntriesInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiTimeEntryBillableInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiTimeStoreInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiUsersInterface.php` | FR-API-003 |
| `Api/Hook/TaskApiWorkspaceInterface.php` | FR-API-003 |
| `Api/Idempotency/DoctrineTaskApiIdempotencyStore.php` | FR-API-002 |
| `Api/Operation/TaskApiAudit.php` | FR-API-001 |
| `Api/Operation/TaskApiBoardOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiChecklistOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiFolderOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiReadOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiTaskOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiTemplateOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiTimeOperations.php` | FR-API-001 |
| `Api/Operation/TaskApiTransaction.php` | FR-API-001 |
| `Api/Repository/TaskApiQueryRepository.php` | FR-API-001 |
| `Api/Security/TaskApiGuard.php` | FR-API-002 |
| `Api/Security/TaskApiIdempotencyListener.php` | FR-API-002 |
| `Api/Security/TaskApiRequestListener.php` | FR-API-002 |
| `Api/Serializer/TaskApiIdentitySerializer.php` | FR-API-001 |
| `Api/Serializer/TaskApiReadSerializer.php` | FR-API-001 |
| `Api/TaskApiAuthFailure.php` | FR-API-001 |
| `Api/TaskApiCapability.php` | FR-API-001 |
| `Api/TaskApiCredential.php` | FR-API-001 |
| `Api/TaskApiInput.php` | FR-API-001 |
| `Api/TaskApiLevel.php` | FR-API-001 |
| `Api/TaskApiPrincipal.php` | FR-API-001 |
| `Api/TaskApiRefusal.php` | FR-API-001 |
| `Api/TaskApiRevision.php` | FR-API-001 |
| `Api/TaskApiWhere.php` | FR-API-001 |
| `Api/Value/TaskApiAttachment.php` | FR-API-001 |
| `Api/Value/TaskApiBoardList.php` | FR-API-001 |
| `Api/Value/TaskApiCommentPage.php` | FR-API-001 |
| `Api/Value/TaskApiFolderList.php` | FR-API-001 |
| `Api/Value/TaskApiIdempotencyClaim.php` | FR-API-001 |
| `Api/Value/TaskApiIdempotencyOutcome.php` | FR-API-001 |
| `Api/Value/TaskApiMember.php` | FR-API-001 |
| `Api/Value/TaskApiNewTimeEntry.php` | FR-API-001 |
| `Api/Value/TaskApiRateLimitState.php` | FR-API-001 |
| `Api/Value/TaskApiRequestContext.php` | FR-API-001 |
| `Api/Value/TaskApiSpace.php` | FR-API-001 |
| `Api/Value/TaskApiTaskFilter.php` | FR-API-001 |
| `Api/Value/TaskApiTaskPage.php` | FR-API-001 |
| `Api/Value/TaskApiTeam.php` | FR-API-001 |
| `Api/Value/TaskApiTemplates.php` | FR-API-001 |
| `Api/Value/TaskApiTimeEntry.php` | FR-API-001 |
| `Api/Value/TaskApiTimePage.php` | FR-API-001 |
| `Api/Value/TaskApiTimeQuery.php` | FR-API-001 |
| `Api/Value/TaskApiUpload.php` | FR-API-001 |
| `Api/Value/TaskApiUser.php` | FR-API-001 |
| `Assignee/TaskAssigneePolicyInterface.php` | FR-PORT-001 |
| `Avatar/AvatarUrlPolicy.php` | FR-MEMBER-001 |
| `Avatar/NullTaskMemberAvatarResolver.php` | FR-MEMBER-001 |
| `Avatar/TaskMemberAvatarResolverInterface.php` | FR-MEMBER-001 |
| `Avatar/TaskMemberAvatars.php` | FR-MEMBER-001 |
| `Avatar/TaskMemberKey.php` | FR-MEMBER-001 |
| `Board/BoardScope.php` | FR-BOARD-010 |
| `Board/BoardScopeField.php` | FR-BOARD-010 |
| `Board/BoardScopeProviderInterface.php` | FR-BOARD-010 |
| `Board/BoardTenantLabelProviderInterface.php` | FR-BOARD-010 |
| `Bridge/TimeTrack/ImportedTimeAggregatorListener.php` | FR-SVC-010 |
| `Bridge/TimeTrack/TimeTrackTaskTimeBillableDefaultProvider.php` | FR-SVC-010 |
| `Bridge/TimeTrack/TimeTrackTaskTimePeriodLock.php` | FR-SVC-010 |
| `Calendar/CalendarAnnotations.php` | FR-CAL-001 |
| `Calendar/CalendarDateRange.php` | FR-CAL-001 |
| `Calendar/CalendarDayAnnotation.php` | FR-CAL-001 |
| `Calendar/CalendarDayAnnotationProviderInterface.php` | FR-CAL-001 |
| `Calendar/CalendarDayAnnotations.php` | FR-CAL-001 |
| `Calendar/CalendarDayAnnotator.php` | FR-CAL-001 |
| `Calendar/CalendarDayKind.php` | FR-CAL-001 |
| `Calendar/CalendarTaskWarning.php` | FR-CAL-001 |
| `Calendar/CalendarViewer.php` | FR-CAL-001 |
| `Command/TaskBoardApplyDefaultColumnIconsCommand.php` | FR-BOARD-010 |
| `Command/TaskBoardSyncCommand.php` | FR-SYNC-010 |
| `Port/Internal/TaskCommentCauseLabelerInterface.php` | FR-PORT-001 |
| `Comment/TaskCommentCauseTextInterface.php` | FR-PORT-001 |
| `Comment/TaskMentionCandidatesProviderInterface.php` | FR-PORT-001 |
| `Configuration/BoardColumnSpec.php` | FR-FOLDER-001 |
| `Configuration/BoardColumnsApplier.php` | FR-FOLDER-001 |
| `Configuration/BoardColumnsRefusedException.php` | FR-FOLDER-001 |
| `Configuration/BoardConfiguration.php` | FR-FOLDER-001 |
| `Configuration/BoardConfigurationApplier.php` | FR-FOLDER-001 |
| `Configuration/BoardConfigurationExporter.php` | FR-FOLDER-001 |
| `Configuration/BoardFieldSpec.php` | FR-FOLDER-001 |
| `Configuration/BoardTemplatesConfigurationInterface.php` | FR-FOLDER-001 |
| `Configuration/NoBoardTemplates.php` | FR-FOLDER-001 |
| `Controller/TaskBoardConnectionController.php` | FR-SYNC-010 |
| `Controller/TaskBoardMultiBoardController.php` | FR-MULTI-001 |
| `Controller/TaskCopyController.php` | FR-MOVE-001 |
| `Controller/TaskCustomFieldController.php` | FR-FIELD-002 |
| `Controller/TaskRecurrenceController.php` | FR-SVC-010 |
| `Copy/TaskColumnRule.php` | FR-MOVE-001 |
| `Copy/TaskCopier.php` | FR-MOVE-001 |
| `Copy/TaskCopyOptions.php` | FR-MOVE-001 |
| `Copy/TaskCopyPreview.php` | FR-MOVE-001 |
| `Copy/TaskCopyResult.php` | FR-MOVE-001 |
| `DependencyInjection/Compiler/PrivateEntityManagerPass.php` | FR-ORM-010 |
| `DependencyInjection/Compiler/PrivateEntityManagerReferencesPass.php` | FR-ORM-010 |
| `DependencyInjection/Compiler/TaskBoardPortsPass.php` | FR-PORT-001 |
| `Doctrine/KeyCollation.php` | FR-ORM-010 |
| `Doctrine/LockConflict.php` | FR-ORM-010 |
| `Doctrine/ManagerOf.php` | FR-ORM-010 |
| `Doctrine/PrivateEntityManagerConnection.php` | FR-ORM-010 |
| `Doctrine/PrivateEntityManagerDriver.php` | FR-ORM-010 |
| `Doctrine/PrivateEntityManagerMiddleware.php` | FR-ORM-010 |
| `Doctrine/PrivateManagerRepositoryFactory.php` | FR-ORM-010 |
| `Doctrine/TaskBoardEntityManager.php` | FR-ORM-010 |
| `Doctrine/TaskBoardManagerRegistry.php` | FR-ORM-010 |
| `Doctrine/TaskBoardSavepoint.php` | FR-ORM-010 |
| `Doctrine/TaskRevisionIncrement.php` | FR-ORM-010 |
| `Doctrine/TaskRevisionListener.php` | FR-ORM-010 |
| `Doctrine/TransactionRollback.php` | FR-ORM-010 |
| `Doctrine/Type/TaskBoardUserRefType.php` | FR-PORT-001 |
| `Dto/EraseCursor.php` | FR-SVC-010 |
| `Dto/EraseProgress.php` | FR-SVC-010 |
| `Dto/TaskCalendarChip.php` | FR-CAL-001 |
| `Dto/TaskCalendarDay.php` | FR-CAL-001 |
| `Dto/TaskCalendarEntry.php` | FR-CAL-001 |
| `Dto/TaskCalendarFilter.php` | FR-CAL-001 |
| `Dto/TaskCalendarView.php` | FR-CAL-001 |
| `Dto/TaskRecurrenceFormData.php` | FR-SVC-010 |
| `Entity/TaskBoardFolder.php` | FR-FOLDER-001 |
| `Entity/TaskCustomField.php` | FR-FIELD-001 |
| `Entity/TaskCustomFieldTombstone.php` | FR-FIELD-002 |
| `Entity/TaskCustomFieldValue.php` | FR-FIELD-001 |
| `Enum/CalendarMode.php` | FR-CAL-001 |
| `Enum/ColumnType.php` | FR-FOLDER-001 |
| `Enum/CustomFieldOperator.php` | FR-FIELD-001 |
| `Enum/CustomFieldType.php` | FR-SVC-010 |
| `Event/AbstractColumnEvent.php` | FR-EVT-010 |
| `Event/AbstractTaskDependencyEvent.php` | FR-EVT-010 |
| `Event/AbstractTaskLifecycleEvent.php` | FR-EVT-010 |
| `Event/AbstractTaskLinkEvent.php` | FR-EVT-010 |
| `Event/AbstractTaskTimeEntryEvent.php` | FR-EVT-010 |
| `Event/BoardCreatedEvent.php` | FR-EVT-010 |
| `Event/BoardErasedEvent.php` | FR-EVT-010 |
| `Event/BoardUpdatedEvent.php` | FR-EVT-010 |
| `Event/ColumnCreatedEvent.php` | FR-EVT-010 |
| `Event/ColumnDeleteCheckEvent.php` | FR-EVT-010 |
| `Event/ColumnDeletedEvent.php` | FR-EVT-010 |
| `Event/ColumnUpdatedEvent.php` | FR-EVT-010 |
| `Event/ColumnsReorderedEvent.php` | FR-EVT-010 |
| `Event/TaskApiBulkWriteEvent.php` | FR-EVT-010 |
| `Event/TaskApiWriteEvent.php` | FR-EVT-010 |
| `Event/TaskArchivedEvent.php` | FR-EVT-010 |
| `Event/TaskBoardChangedEvent.php` | FR-EVT-010 |
| `Event/TaskBoardQuotaCheckEvent.php` | FR-EVT-010 |
| `Event/TaskCommentDeletedEvent.php` | FR-EVT-010 |
| `Event/TaskDependencyAddedEvent.php` | FR-EVT-010 |
| `Event/TaskDependencyRemovedEvent.php` | FR-EVT-010 |
| `Event/TaskErasedEvent.php` | FR-EVT-010 |
| `Event/TaskLinkAddedEvent.php` | FR-EVT-010 |
| `Event/TaskLinkRemovedEvent.php` | FR-EVT-010 |
| `Event/TaskLinkUpdatedEvent.php` | FR-EVT-010 |
| `Event/TaskMemberRemovedEvent.php` | FR-EVT-010 |
| `Event/TaskRestoredEvent.php` | FR-EVT-010 |
| `Event/TaskStatusChangedEvent.php` | FR-EVT-010 |
| `Event/TaskTimeBillableChangedEvent.php` | FR-EVT-010 |
| `Event/TaskTimeLoggedEvent.php` | FR-EVT-010 |
| `Event/TaskTimeRemovedEvent.php` | FR-EVT-010 |
| `EventListener/TaskBoardRequestBoundarySubscriber.php` | FR-WORKER-002 |
| `EventListener/UnitOfWorkFrames.php` | FR-WORKER-002 |
| `Exception/ColumnTransitionRefusedException.php` | FR-SVC-010 |
| `Exception/ImportLimitExceededException.php` | FR-SVC-010 |
| `Exception/InvalidEraseCursorException.php` | FR-SVC-010 |
| `Exception/InvalidTaskScheduleException.php` | FR-SVC-010 |
| `Exception/TaskCopyRefusedException.php` | FR-SVC-010 |
| `Exception/TaskLinkedToProviderException.php` | FR-SVC-010 |
| `Exception/TaskNumberScopeConflictException.php` | FR-SVC-010 |
| `Exception/TaskNumberScopeInUseException.php` | FR-SVC-010 |
| `Exception/TaskReopenRefusedException.php` | FR-SVC-010 |
| `Exception/TaskTimeBillableRefusedException.php` | FR-SVC-010 |
| `Field/TaskCustomFieldCondition.php` | FR-FIELD-001 |
| `Field/TaskCustomFieldForm.php` | FR-FIELD-001 |
| `Field/TaskCustomFieldListView.php` | FR-FIELD-001 |
| `Field/TaskCustomFieldManager.php` | FR-FIELD-001 |
| `Field/TaskCustomFieldPersonalData.php` | FR-FIELD-002 |
| `Field/TaskCustomFieldRefusedException.php` | FR-FIELD-001 |
| `Folder/FolderStatusSetListener.php` | FR-FOLDER-001 |
| `Folder/FolderStatusSets.php` | FR-FOLDER-001 |
| `Folder/ManageRightRestructurePolicy.php` | FR-FOLDER-001 |
| `Folder/TaskBoardDependencyScope.php` | FR-FOLDER-001 |
| `Folder/TaskBoardFolderManager.php` | FR-FOLDER-001 |
| `Folder/TaskBoardFolderSnapshot.php` | FR-FOLDER-001 |
| `Folder/TaskBoardMoveResult.php` | FR-FOLDER-001 |
| `Folder/TaskBoardRestructureInterface.php` | FR-FOLDER-001 |
| `Folder/TaskBoardRestructurePolicyInterface.php` | FR-FOLDER-001 |
| `Folder/TaskBoardRestructureRefusedException.php` | FR-FOLDER-001 |
| `Folder/TaskBoardRestructureViaInterface.php` | FR-FOLDER-001 |
| `Folder/TaskBoardRestructurer.php` | FR-FOLDER-001 |
| `Form/ConnectionLinkFormType.php` | FR-SYNC-010 |
| `Form/ConnectionPeopleFormerFormType.php` | FR-SYNC-010 |
| `Form/ConnectionRefreshFormType.php` | FR-SYNC-010 |
| `Form/ContainerLevelFormType.php` | FR-SVC-010 |
| `Form/MultiBoardTaskFilterType.php` | FR-SVC-010 |
| `Form/TaskCalendarFilterType.php` | FR-SVC-010 |
| `Form/TaskCopySelectFormType.php` | FR-SVC-010 |
| `Form/TaskCustomFieldDefinitionFormType.php` | FR-FIELD-002 |
| `Form/TaskCustomFieldFilterType.php` | FR-SVC-010 |
| `Form/TaskRecurrenceFormType.php` | FR-SVC-010 |
| `Form/TaskScheduleFormType.php` | FR-SVC-010 |
| `Form/TaskTemplatePickerType.php` | FR-SVC-010 |
| `Port/Internal/TaskChangeCauseLabelerInterface.php` | FR-PORT-001 |
| `History/TaskChangeCauseTextInterface.php` | FR-PORT-001 |
| `Import/BoardScopedTaskImportUserResolverInterface.php` | FR-SVC-010 |
| `Import/ClickUp/ClickUpTaskMapper.php` | FR-SVC-010 |
| `Import/ImportHelpLinkResolver.php` | FR-SVC-010 |
| `Import/ImportHelpLinkUrlResolverInterface.php` | FR-SVC-010 |
| `Import/Support/JsonImportGuard.php` | FR-SVC-010 |
| `Import/TaskImportLinkFinderInterface.php` | FR-SVC-010 |
| `Member/MemberBadge.php` | FR-MEMBER-001 |
| `Member/MemberBadgeProviderInterface.php` | FR-MEMBER-001 |
| `Member/MemberBadgeVariant.php` | FR-MEMBER-001 |
| `Member/MemberBadges.php` | FR-MEMBER-001 |
| `Member/NoMemberBadges.php` | FR-MEMBER-001 |
| `Move/DefaultTaskBoardTargets.php` | FR-MOVE-001 |
| `Move/TaskBoardTargetIdsInterface.php` | FR-MOVE-001 |
| `Port/Internal/TaskBoardTargetsInterface.php` | FR-MOVE-001 |
| `Move/TaskMovePlan.php` | FR-MOVE-001 |
| `Move/TaskMovePlanner.php` | FR-MOVE-001 |
| `Move/TaskMover.php` | FR-MOVE-001 |
| `Move/TaskMoverInterface.php` | FR-MOVE-001 |
| `MultiBoard/DoctrineMultiBoardTaskQuery.php` | FR-MULTI-001 |
| `MultiBoard/Export/MultiBoardExportTable.php` | FR-MULTI-001 |
| `MultiBoard/Export/XlsxStreamWriter.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardBoard.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardExportRows.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardPerson.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardScope.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardScopeEntry.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardScopeProviderInterface.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardTaskFilter.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardTaskList.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardTaskListInterface.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardTaskPage.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardTaskRow.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardViewer.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardWorkload.php` | FR-MULTI-001 |
| `MultiBoard/MultiBoardWorkloadRow.php` | FR-MULTI-001 |
| `MultiBoard/NullMultiBoardScopeProvider.php` | FR-MULTI-001 |
| `Number/Command/TaskBoardNumbersBackfillCommand.php` | FR-NUM-001 |
| `Number/Entity/TaskNumberCounter.php` | FR-NUM-001 |
| `Number/Entity/TaskNumberEntry.php` | FR-NUM-001 |
| `Number/TaskNumberBackfiller.php` | FR-NUM-001 |
| `Number/TaskNumberLedger.php` | FR-NUM-001 |
| `Number/TaskNumberListener.php` | FR-NUM-001 |
| `Number/TaskNumberReserver.php` | FR-NUM-001 |
| `Number/TaskNumberScope.php` | FR-NUM-001 |
| `Port/FirstBoardProvisioningGate.php` | FR-PORT-001 |
| `Port/ImportedTimeEntryReceiverGate.php` | FR-PORT-001 |
| `Port/OwnerlessTimeEntryReceiverGate.php` | FR-PORT-001 |
| `Port/PeopleInvitationGate.php` | FR-PORT-001 |
| `Port/Ports.php` | FR-PORT-001 |
| `Port/ProviderCredentialSourceGate.php` | FR-PORT-001 |
| `Port/Snapshots.php` | FR-PORT-001 |
| `Port/SyncActorProviderGate.php` | FR-PORT-001 |
| `Port/SyncPersonDirectoryGate.php` | FR-PORT-001 |
| `Port/SyncQuotaPolicyGate.php` | FR-PORT-001 |
| `Port/SyncedFileStorageGate.php` | FR-PORT-001 |
| `Port/SyncedImageRewriterGate.php` | FR-PORT-001 |
| `Port/TaskApiAttachmentStoreGate.php` | FR-PORT-001 |
| `Port/TaskApiBillableTimeStoreGate.php` | FR-PORT-001 |
| `Port/TaskApiRequestAuthenticatorGate.php` | FR-PORT-001 |
| `Port/TaskApiRequestContexts.php` | FR-PORT-001 |
| `Port/TaskApiTimeStoreGate.php` | FR-PORT-001 |
| `Port/TaskAssigneePolicyGate.php` | FR-PORT-001 |
| `Port/TaskBoardDefaultViewGate.php` | FR-PORT-001 |
| `Port/TaskBoardTargetIdsGate.php` | FR-PORT-001 |
| `Port/TaskChangeCauseTextGate.php` | FR-PORT-001 |
| `Port/TaskCommentCauseTextGate.php` | FR-PORT-001 |
| `Port/TaskImportLinkFinderGate.php` | FR-PORT-001 |
| `Port/TaskMentionCandidatesGate.php` | FR-PORT-001 |
| `Provenance/NoWriteProvenance.php` | FR-MEMBER-001 |
| `Provenance/WriteChannel.php` | FR-MEMBER-001 |
| `Provenance/WriteProvenance.php` | FR-MEMBER-001 |
| `Provenance/WriteProvenanceProviderInterface.php` | FR-MEMBER-001 |
| `Recurrence/Entity/TaskOccurrence.php` | FR-REC-001 |
| `Recurrence/Entity/TaskRecurrence.php` | FR-REC-001 |
| `Recurrence/TaskRecurrenceBoardListener.php` | FR-REC-001 |
| `Recurrence/TaskRecurrenceManager.php` | FR-REC-001 |
| `Recurrence/TaskRecurrencePlanner.php` | FR-REC-001 |
| `Recurrence/TaskRecurrenceRule.php` | FR-REC-001 |
| `Recurrence/TaskRecurrenceView.php` | FR-REC-001 |
| `Repository/ArchiveAwareTaskRepositoryInterface.php` | FR-ORM-010 |
| `Repository/BoardSlugTakenInterface.php` | FR-ORM-010 |
| `Repository/DoctrineOrmTaskTreeStore.php` | FR-TREE-002 |
| `Repository/TaskCalendarRepositoryInterface.php` | FR-ORM-010 |
| `Repository/TaskDependentsLookupInterface.php` | FR-ORM-010 |
| `Repository/TenantBoardSlugLookupInterface.php` | FR-ORM-010 |
| `Resources/assets/src/anchor-utils.ts` | FR-ASSET-010 |
| `Resources/assets/src/ask-confirm.ts` | FR-ASSET-010 |
| `Resources/assets/src/board-create-steps-controller.ts` | FR-ASSET-010 |
| `Resources/assets/src/board-openers.ts` | FR-ASSET-010 |
| `Resources/assets/src/calendar-controller.ts` | FR-ASSET-010 |
| `Resources/assets/src/calendar-utils.ts` | FR-ASSET-010 |
| `Resources/assets/src/container-browser-controller.ts` | FR-ASSET-010 |
| `Resources/assets/src/dialog-utils.ts` | FR-ASSET-010 |
| `Resources/assets/src/filter-fold-controller.ts` | FR-ASSET-010 |
| `Resources/assets/src/filter-fold-utils.ts` | FR-ASSET-010 |
| `Resources/assets/src/select-anchor-controller.ts` | FR-ASSET-010 |
| `Resources/assets/src/sync-progress-controller.ts` | FR-ASSET-010 |
| `Resources/assets/src/sync-utils.ts` | FR-ASSET-010 |
| `Resources/config/doctrine-xml/api/TaskApiIdempotencyRecord.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/BoardColumn.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/ColumnSet.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/Task.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskBoard.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskBoardFolder.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskChangeHistory.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskComment.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskCustomField.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskCustomFieldTombstone.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskCustomFieldValue.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskDependency.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskDocument.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskLink.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskMember.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TaskTimeEntry.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/Team.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/core/TeamMember.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/number/TaskNumberCounter.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/number/TaskNumberEntry.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/recurrence/TaskOccurrence.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/recurrence/TaskRecurrence.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskBoardConnection.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncAttachment.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncComment.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncDirectory.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncField.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncFieldValue.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncItem.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncMember.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncPerson.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/sync/TaskSyncRun.orm.xml` | FR-ORM-010 |
| `Resources/config/doctrine-xml/template/TaskTemplate.orm.xml` | FR-ORM-010 |
| `Resources/config/services_api.yaml` | FR-DI-010 |
| `Resources/config/services_multi_board.yaml` | FR-DI-010 |
| `Resources/config/services_numbers.yaml` | FR-DI-010 |
| `Resources/config/services_recurrence.yaml` | FR-DI-010 |
| `Resources/config/services_sync.yaml` | FR-DI-010 |
| `Resources/config/services_templates.yaml` | FR-DI-010 |
| `Resources/views/manage/_board_create_actions.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_create_form.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_description.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_item_main.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_item_views.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_list.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_list_item.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_row.html.twig` | FR-UI-020 |
| `Resources/views/manage/_board_title.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_chip.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_day_label.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_grid.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_list.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_off_day.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_reschedule_dialog.html.twig` | FR-UI-020 |
| `Resources/views/manage/_calendar_row.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_badge.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_containers.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_history.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_mass_archive.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_progress.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_provider_picker.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_strip.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_tree_error.html.twig` | FR-UI-020 |
| `Resources/views/manage/_connection_unlink_dialog.html.twig` | FR-UI-020 |
| `Resources/views/manage/_import_help_link.html.twig` | FR-UI-020 |
| `Resources/views/manage/_list_filter_fold.html.twig` | FR-UI-020 |
| `Resources/views/manage/_list_row_cells.html.twig` | FR-UI-020 |
| `Resources/views/manage/_member_label_suffix.html.twig` | FR-UI-020 |
| `Resources/views/manage/_multi_task_row.html.twig` | FR-UI-020 |
| `Resources/views/manage/_multi_workload_row.html.twig` | FR-UI-020 |
| `Resources/views/manage/_subtask_node_leading.html.twig` | FR-UI-020 |
| `Resources/views/manage/_subtask_node_meta.html.twig` | FR-UI-020 |
| `Resources/views/manage/_task_copy_page.html.twig` | FR-UI-020 |
| `Resources/views/manage/_task_member_avatar.html.twig` | FR-UI-020 |
| `Resources/views/manage/_task_repeat_section.html.twig` | FR-UI-020 |
| `Resources/views/manage/_task_repeat_sentence.html.twig` | FR-UI-020 |
| `Resources/views/manage/calendar.html.twig` | FR-UI-020 |
| `Resources/views/manage/connection.html.twig` | FR-UI-020 |
| `Resources/views/manage/task_duplicate.html.twig` | FR-UI-020 |
| `Resources/views/manage/task_move.html.twig` | FR-UI-020 |
| `Resources/views/manage/task_recurrence.html.twig` | FR-UI-020 |
| `Resources/views/manage/tasks.html.twig` | FR-UI-020 |
| `Search/DoctrineTaskTextSearch.php` | FR-SEARCH-001 |
| `Search/TaskTextSearchInterface.php` | FR-SEARCH-001 |
| `Search/TaskTextSearchMetadataListener.php` | FR-SEARCH-001 |
| `Security/EraseCursorSigner.php` | FR-SVC-010 |
| `Security/TenantAwareTaskBoardAccessCheckerInterface.php` | FR-SVC-010 |
| `Service/BoardColumnManagerInterface.php` | FR-SVC-010 |
| `Service/DefaultColumnIconFiller.php` | FR-BOARD-010 |
| `Service/NoTaskTimePeriodLock.php` | FR-SVC-010 |
| `Service/NullTaskTimeBillableDefaultProvider.php` | FR-SVC-010 |
| `Service/TaskBoardCreatorInterface.php` | FR-SVC-010 |
| `Service/TaskBoardEditor.php` | FR-SVC-010 |
| `Service/TaskBoardEditorInterface.php` | FR-SVC-010 |
| `Service/TaskBoardEraser.php` | FR-SVC-010 |
| `Service/TaskBoardEraserInterface.php` | FR-SVC-010 |
| `Service/TaskBoardQuota.php` | FR-SVC-010 |
| `Service/TaskCalendarBuilder.php` | FR-SVC-010 |
| `Service/TaskChangeContext.php` | FR-SVC-010 |
| `Service/TaskColumnFollow.php` | FR-SVC-010 |
| `Service/TaskCommentManagerInterface.php` | FR-SVC-010 |
| `Service/TaskCommentPolicy.php` | FR-SVC-010 |
| `Service/TaskEventDeferral.php` | FR-SVC-010 |
| `Service/TaskManagerInterface.php` | FR-SVC-010 |
| `Service/TaskTimeBillableDefaultProviderInterface.php` | FR-SVC-010 |
| `Service/TaskTimeBillableService.php` | FR-SVC-010 |
| `Service/TaskTimeEntrySnapshotPurger.php` | FR-SVC-010 |
| `Service/TaskTimeLoggerInterface.php` | FR-SVC-010 |
| `Service/TaskTimePeriodLockInterface.php` | FR-SVC-010 |
| `Support/ActorId.php` | FR-BOARD-010 |
| `Support/BoardIcon.php` | FR-BOARD-010 |
| `Support/ColumnIcon.php` | FR-BOARD-010 |
| `Support/ColumnTasks.php` | FR-BOARD-010 |
| `Support/ColumnTypes.php` | FR-BOARD-010 |
| `Support/ExactKey.php` | FR-BOARD-010 |
| `Support/IconNames.php` | FR-BOARD-010 |
| `Support/Like.php` | FR-BOARD-010 |
| `Support/TextFold.php` | FR-BOARD-010 |
| `Sync/Content/RemoteContentSanitizer.php` | FR-SYNC-012 |
| `Sync/Dto/FileCounts.php` | FR-SYNC-010 |
| `Sync/Dto/PeopleCounts.php` | FR-SYNC-010 |
| `Sync/Dto/SkipCounts.php` | FR-SYNC-010 |
| `Sync/Dto/SyncConflict.php` | FR-SYNC-010 |
| `Sync/Dto/SyncCursor.php` | FR-SYNC-010 |
| `Sync/Dto/SyncError.php` | FR-SYNC-010 |
| `Sync/Dto/SyncMode.php` | FR-SYNC-010 |
| `Sync/Dto/SyncOptions.php` | FR-SYNC-010 |
| `Sync/Dto/SyncPhase.php` | FR-SYNC-010 |
| `Sync/Dto/SyncReport.php` | FR-SYNC-010 |
| `Sync/Dto/SyncRequest.php` | FR-SYNC-010 |
| `Sync/Dto/SyncStatus.php` | FR-SYNC-010 |
| `Sync/Dto/SyncTrigger.php` | FR-SYNC-010 |
| `Sync/Dto/SyncValues.php` | FR-SYNC-010 |
| `Sync/Dto/TimeCounts.php` | FR-SYNC-010 |
| `Sync/Entity/TaskBoardConnection.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncAttachment.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncComment.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncDirectory.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncItem.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncMember.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncPerson.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncPersonMapping.php` | FR-SYNC-010 |
| `Sync/Entity/TaskSyncRun.php` | FR-SYNC-010 |
| `Sync/Event/BoardConnectionEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncFailedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncFinishedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncPageDoneEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncPeopleReprojectedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncPeopleReviewedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncPersonAnonymisedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncPersonMappedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncPersonReassignedEvent.php` | FR-SYNC-010 |
| `Sync/Event/SyncStartedEvent.php` | FR-SYNC-010 |
| `Sync/Event/TaskRemoteArchivedEvent.php` | FR-SYNC-010 |
| `Sync/Event/TaskRemoteRestoredEvent.php` | FR-SYNC-010 |
| `Sync/Event/UnmatchedColumnsResolvedEvent.php` | FR-SYNC-010 |
| `Sync/Exception/ProviderErrorCode.php` | FR-SYNC-010 |
| `Sync/Exception/ProviderException.php` | FR-SYNC-010 |
| `Sync/Exception/RateLimitedException.php` | FR-SYNC-010 |
| `Sync/Exception/SyncBusyException.php` | FR-SYNC-010 |
| `Sync/Exception/UnsafeRemoteFileException.php` | FR-SYNC-010 |
| `Sync/File/FetchedFile.php` | FR-SYNC-012 |
| `Sync/File/HostAllowlist.php` | FR-SYNC-012 |
| `Sync/File/NullUrlGuard.php` | FR-SYNC-012 |
| `Sync/File/OutboundUrlGuardAdapter.php` | FR-SYNC-012 |
| `Sync/File/RemoteFileFetcher.php` | FR-SYNC-012 |
| `Sync/File/UrlGuardInterface.php` | FR-SYNC-012 |
| `Sync/Hook/AttributionOnlyPolicyInterface.php` | FR-SYNC-011 |
| `Port/Internal/AttributionOnlyResolverInterface.php` | FR-SYNC-011 |
| `Sync/Hook/ConnectionChoice.php` | FR-SYNC-011 |
| `Sync/Hook/ConnectionCreatorActorResolver.php` | FR-SYNC-011 |
| `Sync/Hook/DefaultSyncPersonResolver.php` | FR-SYNC-011 |
| `Sync/Hook/ImportedTimeEntryReceiverInterface.php` | FR-SYNC-011 |
| `Port/Internal/ImportedTimeEntrySinkInterface.php` | FR-SYNC-011 |
| `Port/Internal/InlineImageRewriterInterface.php` | FR-SYNC-011 |
| `Sync/Hook/InlineSyncRunner.php` | FR-SYNC-011 |
| `Sync/Hook/LimitRefusal.php` | FR-SYNC-011 |
| `Sync/Hook/MapInlineImageRewriter.php` | FR-SYNC-011 |
| `Sync/Hook/NullSyncLimitChecker.php` | FR-SYNC-011 |
| `Sync/Hook/NullTaskAttachmentStorage.php` | FR-SYNC-011 |
| `Sync/Hook/OwnerlessTimeEntryReceiverInterface.php` | FR-SYNC-011 |
| `Port/Internal/OwnerlessTimeEntrySinkInterface.php` | FR-SYNC-011 |
| `Sync/Hook/PeopleInvitationInterface.php` | FR-SYNC-011 |
| `Port/Internal/ProviderCredentialResolverInterface.php` | FR-SYNC-011 |
| `Sync/Hook/ProviderCredentialSourceInterface.php` | FR-SYNC-011 |
| `Sync/Hook/StoredAttachment.php` | FR-SYNC-011 |
| `Sync/Hook/SyncActorProviderInterface.php` | FR-SYNC-011 |
| `Port/Internal/SyncActorResolverInterface.php` | FR-SYNC-011 |
| `Port/Internal/SyncLimitCheckerInterface.php` | FR-SYNC-011 |
| `Sync/Hook/SyncPersonDirectoryInterface.php` | FR-SYNC-011 |
| `Sync/Hook/SyncPersonMatch.php` | FR-SYNC-011 |
| `Port/Internal/SyncPersonResolverInterface.php` | FR-SYNC-011 |
| `Sync/Hook/SyncQuotaPolicyInterface.php` | FR-SYNC-011 |
| `Sync/Hook/SyncRunnerInterface.php` | FR-SYNC-011 |
| `Sync/Hook/SyncedFileStorageInterface.php` | FR-SYNC-011 |
| `Sync/Hook/SyncedImageRewriterInterface.php` | FR-SYNC-011 |
| `Port/Internal/TaskAttachmentStorageInterface.php` | FR-SYNC-011 |
| `Sync/Http/ProviderHttpClient.php` | FR-SYNC-010 |
| `Sync/Http/SleeperInterface.php` | FR-SYNC-010 |
| `Sync/Http/SystemSleeper.php` | FR-SYNC-010 |
| `Sync/People/InvalidMappingException.php` | FR-SYNC-011 |
| `Sync/People/MigrateReport.php` | FR-SYNC-011 |
| `Sync/People/PeopleMappingDeniedException.php` | FR-SYNC-011 |
| `Sync/People/PeopleProjection.php` | FR-SYNC-011 |
| `Sync/People/PeopleScanResult.php` | FR-SYNC-011 |
| `Sync/People/PeopleSession.php` | FR-SYNC-011 |
| `Sync/People/PersonFilter.php` | FR-SYNC-011 |
| `Sync/People/PersonMappingDecision.php` | FR-SYNC-011 |
| `Sync/People/ReassignCursor.php` | FR-SYNC-011 |
| `Sync/People/ReassignInProgressException.php` | FR-SYNC-011 |
| `Sync/People/ReassignReport.php` | FR-SYNC-011 |
| `Sync/People/ScannedPerson.php` | FR-SYNC-011 |
| `Sync/People/SyncPeopleWriter.php` | FR-SYNC-011 |
| `Sync/People/SyncUser.php` | FR-SYNC-011 |
| `Sync/People/TaskSyncCommentLegacyAuthorMigrator.php` | FR-SYNC-011 |
| `Sync/People/TaskSyncPeopleDirectoryService.php` | FR-SYNC-011 |
| `Sync/People/TaskSyncPeopleProjector.php` | FR-SYNC-011 |
| `Sync/People/TaskSyncPeopleReassigner.php` | FR-SYNC-011 |
| `Sync/People/TaskSyncPeopleScanner.php` | FR-SYNC-011 |
| `Sync/People/TaskSyncPersonRepository.php` | FR-SYNC-011 |
| `Sync/Provider/ClickUp/ClickUpAuthorization.php` | FR-SYNC-014 |
| `Sync/Provider/CredentialScheme.php` | FR-SYNC-014 |
| `Sync/OAuth/ClickUpOAuthClient.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthErrorCode.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthException.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthGrant.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthIdentity.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthReturnPath.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthState.php` | FR-SYNC-014 |
| `Sync/OAuth/OAuthWorkspace.php` | FR-SYNC-014 |
| `Sync/Hook/OAuthConnectionStartInterface.php` | FR-SYNC-014 |
| `Port/Internal/OAuthConnectionStarterInterface.php` | FR-SYNC-014, FR-PORT-001 |
| `Sync/Provider/ClickUp/ClickUpCommentRenderer.php` | FR-SYNC-010 |
| `Sync/Provider/ClickUp/ClickUpTaskProvider.php` | FR-SYNC-010 |
| `Sync/Provider/ContainerPage.php` | FR-SYNC-010 |
| `Sync/Provider/ContainerType.php` | FR-SYNC-010 |
| `Sync/Provider/PeopleProviderInterface.php` | FR-SYNC-010 |
| `Sync/Provider/ProviderApiGapsInterface.php` | FR-SYNC-010 |
| `Sync/Provider/ProviderCapabilities.php` | FR-SYNC-010 |
| `Sync/Provider/ProviderCredential.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteAttachment.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteChecklist.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteChecklistItem.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteComment.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteContainer.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteLocation.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteLocationKind.php` | FR-SYNC-010 |
| `Sync/Provider/RemotePerson.php` | FR-SYNC-010 |
| `Sync/Provider/RemotePersonPage.php` | FR-SYNC-010 |
| `Sync/Provider/RemotePersonRef.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteRole.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteSource.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteStatus.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteStatusType.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteTask.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteTaskPage.php` | FR-SYNC-010 |
| `Sync/Provider/RemoteTimeEntry.php` | FR-SYNC-010 |
| `Sync/Provider/SourceProviderInterface.php` | FR-SYNC-010 |
| `Sync/Provider/TaskDetailProviderInterface.php` | FR-SYNC-010 |
| `Sync/Provider/TaskProviderInterface.php` | FR-SYNC-010 |
| `Sync/Provider/TaskProviderRegistry.php` | FR-SYNC-010 |
| `Sync/Provider/TaskTypeProviderInterface.php` | FR-SYNC-010 |
| `Sync/Provider/TimeAccess.php` | FR-SYNC-010 |
| `Sync/Provider/TimeAccessMode.php` | FR-SYNC-010 |
| `Sync/Provider/TimeEntryProviderInterface.php` | FR-SYNC-010 |
| `Sync/Provider/TimeEntryQuery.php` | FR-SYNC-010 |
| `Sync/Provider/WorkspaceScope.php` | FR-SYNC-015 |
| `Sync/Provider/WorkspaceScopeProviderInterface.php` | FR-SYNC-015 |
| `Sync/Repository/SyncStore.php` | FR-SYNC-010 |
| `Sync/SourceScanResult.php` | FR-SYNC-010 |
| `Sync/Status/FirstImportFinisher.php` | FR-SYNC-013 |
| `Sync/Status/StatusColumns.php` | FR-SYNC-013 |
| `Sync/Status/UnmatchedColumnsCleaner.php` | FR-SYNC-013 |
| `Sync/SyncChecklistWriter.php` | FR-SYNC-013 |
| `Sync/SyncRefusedException.php` | FR-SYNC-010 |
| `Sync/SyncRunContext.php` | FR-SYNC-010 |
| `Sync/SyncTaskWriter.php` | FR-SYNC-010 |
| `Sync/TaskBoardSyncService.php` | FR-SYNC-010 |
| `Sync/TaskSyncSourceScanner.php` | FR-SYNC-010 |
| `Sync/Testing/InMemoryTaskProvider.php` | FR-SYNC-010 |
| `Sync/Time/ImportedTimeEntryCandidate.php` | FR-SYNC-013 |
| `Sync/Time/TimeEntryPhase.php` | FR-SYNC-013 |
| `Sync/Time/TimeWindow.php` | FR-SYNC-013 |
| `Sync/View/ConnectionBadgeProvider.php` | FR-SYNC-011 |
| `Sync/View/SyncPeopleViewProvider.php` | FR-SYNC-011 |
| `Sync/View/SyncPersonView.php` | FR-SYNC-011 |
| `Template/DefaultTaskTemplatePermission.php` | FR-TPL-002 |
| `Template/Entity/TaskTemplate.php` | FR-TPL-002 |
| `Template/InvalidTaskTemplateException.php` | FR-TPL-002 |
| `Template/TaskTemplateData.php` | FR-TPL-002 |
| `Template/TaskTemplateManager.php` | FR-TPL-002 |
| `Template/TaskTemplatePermissionInterface.php` | FR-TPL-002 |
| `Template/TaskTemplateScope.php` | FR-TPL-002 |
| `Template/TaskTemplateSnapshot.php` | FR-TPL-002 |
| `Port/Internal/FirstBoardProvisionerInterface.php` | FR-PORT-001 |
| `Tenancy/FirstBoardProvisioningInterface.php` | FR-PORT-001 |
| `Transition/AllowAllColumnTransitionPolicy.php` | FR-TRANS-001 |
| `Transition/ColumnTransitionPolicyInterface.php` | FR-TRANS-001 |
| `Transition/HumansOnlyColumnTransitionPolicy.php` | FR-TRANS-001 |
| `Tree/TaskReparentResult.php` | FR-TREE-002 |
| `Tree/TaskReparenter.php` | FR-TREE-002 |
| `Tree/TaskReparenterInterface.php` | FR-TREE-002 |
| `Tree/TaskTreeLimits.php` | FR-TREE-002 |
| `Tree/TaskTreeNode.php` | FR-TREE-002 |
| `Tree/TaskTreeRefusedException.php` | FR-TREE-002 |
| `Tree/TaskTreeStoreInterface.php` | FR-TREE-002 |
| `Ui/TaskBoardPages.php` | FR-SVC-010 |
| `User/TaskBoardUserDirectoryInterface.php` | FR-PORT-001 |
| `User/TaskBoardUsers.php` | FR-PORT-001 |
| `Validation/TaskConstraints.php` | FR-API-002 |
| `ValueObject/BoardColumnSnapshot.php` | FR-TRANS-001 |
| `ValueObject/ColumnTransition.php` | FR-TRANS-001 |
| `ValueObject/ColumnTransitionDecision.php` | FR-TRANS-001 |
| `ValueObject/ColumnTransitionVia.php` | FR-TRANS-001 |
| `ValueObject/TaskBoardSnapshot.php` (3.0: public `of(TaskBoard)`) | FR-TRANS-001 |
| `ValueObject/TaskBoardUserRef.php` | FR-TRANS-001 |
| `ValueObject/TaskChangeSnapshot.php` | FR-TRANS-001 |
| `ValueObject/TaskCommentSnapshot.php` | FR-TRANS-001 |
| `ValueObject/TaskSnapshot.php` | FR-TRANS-001 |
| `ValueObject/TaskSyncDirectorySnapshot.php` | FR-TRANS-001 |
| `ValueObject/TaskTextQuery.php` | FR-TRANS-001 |
| `View/NullTaskBoardDefaultViewResolver.php` | FR-PORT-001 |
| `View/TaskBoardDefaultViewPolicyInterface.php` | FR-PORT-001 |
| `Port/Internal/TaskBoardDefaultViewResolverInterface.php` | FR-PORT-001 |

## Additions 2.21-2.30 (locks, menus, API conflicts, questions, calendar sources, header search, host ports)

Grouped by feature; each row names the semantic requirement of [`spec.md`](spec.md#additions-221230).

| Source file | Requirement IDs |
| --- | --- |
| `Resources/config/services_questions.yaml` | FR-QUEST-001 |
| `Question/Entity/TaskQuestion.php` | FR-QUEST-001 |
| `Question/Entity/TaskQuestionAnswer.php` | FR-QUEST-001 |
| `Question/Entity/TaskQuestionOption.php` | FR-QUEST-001 |
| `Question/Entity/TaskQuestionRecipient.php` | FR-QUEST-001 |
| `Resources/config/doctrine-xml/question/TaskQuestion.orm.xml` | FR-QUEST-001 |
| `Resources/config/doctrine-xml/question/TaskQuestionAnswer.orm.xml` | FR-QUEST-001 |
| `Resources/config/doctrine-xml/question/TaskQuestionOption.orm.xml` | FR-QUEST-001 |
| `Resources/config/doctrine-xml/question/TaskQuestionRecipient.orm.xml` | FR-QUEST-001 |
| `Question/DefaultTaskQuestionRecipientResolver.php` | FR-QUEST-001 |
| `Question/TaskQuestionAnswerInput.php` | FR-QUEST-001 |
| `Question/TaskQuestionAnswerSnapshot.php` | FR-QUEST-001 |
| `Question/TaskQuestionChoiceMode.php` | FR-QUEST-001 |
| `Question/TaskQuestionConflictException.php` | FR-QUEST-001 |
| `Question/TaskQuestionDecisionPublisherInterface.php` | FR-QUEST-001 |
| `Question/TaskQuestionExternalAnswerInterface.php` | FR-QUEST-001 |
| `Question/TaskQuestionInput.php` | FR-QUEST-001 |
| `Question/TaskQuestionManager.php` | FR-QUEST-001 |
| `Question/TaskQuestionOptionInput.php` | FR-QUEST-001 |
| `Question/TaskQuestionOptionSnapshot.php` | FR-QUEST-001 |
| `Question/TaskQuestionPublication.php` | FR-QUEST-001 |
| `Question/TaskQuestionQuery.php` | FR-QUEST-001 |
| `Question/TaskQuestionRecipientCandidate.php` | FR-QUEST-001 |
| `Question/TaskQuestionRecipientResolverInterface.php` | FR-QUEST-001 |
| `Question/TaskQuestionRecipientSnapshot.php` | FR-QUEST-001 |
| `Question/TaskQuestionRefusedException.php` | FR-QUEST-001 |
| `Question/TaskQuestionSnapshot.php` | FR-QUEST-001 |
| `Question/TaskQuestionState.php` | FR-QUEST-001 |
| `Question/TaskQuestionView.php` | FR-QUEST-001 |
| `Event/AbstractTaskQuestionEvent.php` | FR-QUEST-001 |
| `Event/TaskQuestionAnsweredEvent.php` | FR-QUEST-001 |
| `Event/TaskQuestionAskedEvent.php` | FR-QUEST-001 |
| `Event/TaskQuestionWithdrawnEvent.php` | FR-QUEST-001 |
| `Controller/TaskQuestionController.php` | FR-QUEST-002 |
| `Dto/TaskQuestionAnswerFormData.php` | FR-QUEST-002 |
| `Dto/TaskQuestionFormData.php` | FR-QUEST-002 |
| `Dto/TaskQuestionWithdrawFormData.php` | FR-QUEST-002 |
| `Form/TaskQuestionAnswerFormType.php` | FR-QUEST-002 |
| `Form/TaskQuestionFormType.php` | FR-QUEST-002 |
| `Form/TaskQuestionWithdrawFormType.php` | FR-QUEST-002 |
| `Resources/assets/src/task-questions.ts` | FR-QUEST-002 |
| `Resources/views/manage/_my_question_row.html.twig` | FR-QUEST-002 |
| `Resources/views/manage/_my_questions.html.twig` | FR-QUEST-002 |
| `Resources/views/manage/_task_question_card.html.twig` | FR-QUEST-002 |
| `Resources/views/manage/_task_questions_section.html.twig` | FR-QUEST-002 |
| `Resources/views/manage/my_questions.html.twig` | FR-QUEST-002 |
| `Api/Controller/TaskApiQuestionController.php` | FR-QUEST-002 |
| `Api/Operation/TaskApiQuestionOperations.php` | FR-QUEST-002 |
| `Api/Security/TaskApiActors.php` | FR-API-004 |
| `Api/Security/TaskApiCommitWatermark.php` | FR-API-004 |
| `Api/Security/TaskApiConflictResponseFormatter.php` | FR-API-004 |
| `Doctrine/LockConflictChain.php` | FR-API-004 |
| `Service/OrderedRowLockInterface.php` | FR-LOCK-001 |
| `Repository/DoctrineOrmOrderedRowLock.php` | FR-LOCK-001 |
| `Controller/TaskMenuController.php` | FR-MENU-001 |
| `Menu/TaskMenu.php` | FR-MENU-001 |
| `Menu/TaskMenuBuilder.php` | FR-MENU-001 |
| `Menu/TaskMenuContext.php` | FR-MENU-001 |
| `Menu/TaskMenuOperation.php` | FR-MENU-001 |
| `Menu/TaskTimerStart.php` | FR-MENU-001 |
| `Menu/TaskTimerStarterInterface.php` | FR-MENU-001 |
| `Twig/TaskMenuTwigExtension.php` | FR-MENU-001 |
| `Text/ReservedAttributeStripper.php` | FR-MENU-001 |
| `Resources/assets/src/column-collapse.ts` | FR-MENU-001 |
| `Resources/assets/src/task-menu-shared.ts` | FR-MENU-001 |
| `Resources/views/manage/_column_menu.html.twig` | FR-MENU-001 |
| `Resources/views/manage/_focus_mode_toggle.html.twig` | FR-MENU-001 |
| `Resources/views/manage/_task_menu.html.twig` | FR-MENU-001 |
| `Resources/views/manage/_task_menu_shared.html.twig` | FR-MENU-001 |
| `Bridge/TimeTrack/TimeTrackTaskTimerStarter.php` | FR-MENU-001 |
| `Calendar/CalendarEvent.php` | FR-CAL-002 |
| `Calendar/CalendarEventCollector.php` | FR-CAL-002 |
| `Calendar/CalendarEventRange.php` | FR-CAL-002 |
| `Calendar/CalendarEventReschedule.php` | FR-CAL-002 |
| `Calendar/CalendarEventRescheduleResult.php` | FR-CAL-002 |
| `Calendar/CalendarEventRescheduleStatus.php` | FR-CAL-002 |
| `Calendar/CalendarEventReschedulerInterface.php` | FR-CAL-002 |
| `Calendar/CalendarEventShown.php` | FR-CAL-002 |
| `Calendar/CalendarEventSourceInterface.php` | FR-CAL-002 |
| `Dto/TaskCalendarEventEntry.php` | FR-CAL-002 |
| `Form/CalendarEventMoveFormType.php` | FR-CAL-002 |
| `Resources/views/manage/_calendar_event.html.twig` | FR-CAL-002 |
| `Resources/views/manage/_calendar_event_move_dialog.html.twig` | FR-CAL-002 |
| `Resources/views/manage/_calendar_events.html.twig` | FR-CAL-002 |
| `Bridge/SearchKit/BoardSearchDefinition.php` | FR-SRCH-010 |
| `Bridge/SearchKit/TaskBoardSearchDefinition.php` | FR-SRCH-010 |
| `Bridge/SearchKit/TaskSearchDefinition.php` | FR-SRCH-010 |
| `Event/SearchScopeEvent.php` | FR-SRCH-010 |
| `Security/BoardViewReach.php` | FR-SRCH-010 |
| `Search/Header/BoardHeaderSearch.php` | FR-SRCH-010 |
| `Search/Header/SearchBoardScope.php` | FR-SRCH-010 |
| `Search/Header/SearchHit.php` | FR-SRCH-010 |
| `Search/Header/SearchHitMapper.php` | FR-SRCH-010 |
| `Search/Header/SearchHits.php` | FR-SRCH-010 |
| `Search/Header/SearchTenants.php` | FR-SRCH-010 |
| `Search/Header/SearchText.php` | FR-SRCH-010 |
| `Search/Header/SearchViewer.php` | FR-SRCH-010 |
| `Search/Header/SearchViewers.php` | FR-SRCH-010 |
| `Search/Header/SearchableBoard.php` | FR-SRCH-010 |
| `Search/Header/SearchableBoards.php` | FR-SRCH-010 |
| `Search/Header/TaskHeaderSearch.php` | FR-SRCH-010 |
| `Search/Header/TaskReference.php` | FR-SRCH-010 |
| `Search/Header/TaskSearchExtras.php` | FR-SRCH-010 |
| `Search/Header/TaskTextMatch.php` | FR-SRCH-010 |
| `Search/Header/TaskTextMatchFunction.php` | FR-SRCH-010 |
| `Doctrine/HostCommitEventRelay.php` | FR-ORM-011 |
| `Bridge/TimeTrack/TaskBoardTaskDirectory.php` | FR-TT-001 |
| `Bridge/TimeTrack/TaskBoardTeamDirectory.php` | FR-TT-001 |
| `Bridge/TimeTrack/TimeTrackScopeKeys.php` | FR-TT-001 |
| `Controller/TaskSyncImportPlanController.php` | FR-SYNC-015 |
| `ValueObject/UserRef.php` | FR-ID-001 |
| `Service/TaskCommandsInterface.php` | FR-ID-001 |
| `Service/TaskCommands.php` | FR-ID-001 |
| `Service/TaskBoardAccessInterface.php` | FR-ID-001 |
| `Service/TaskBoardIdAccessChecker.php` | FR-ID-001 |
| `Service/TaskBoardQueryInterface.php` | FR-ID-001 |
| `Service/TaskBoardQuery.php` | FR-ID-001 |
| `Service/TaskFlowStatsInterface.php` | FR-ID-001 |
| `Service/TaskFlowStats.php` | FR-ID-001 |
| `Dto/BoardCriteria.php` | FR-ID-001 |
| `Dto/TaskCriteria.php` | FR-ID-001 |
| `Enum/TaskSort.php` | FR-ID-001 |
| `Exception/TaskBoardAccessDeniedException.php` | FR-ID-001 |
| `Exception/TaskBoardQuotaRefusedException.php` | FR-ID-001 |
| `Exception/TaskChangedException.php` | FR-ID-001 |
| `User/TaskBoardUserLocator.php` | FR-ID-001 |
| `Security/RoleHolderUser.php` | FR-ID-001 |
| `Security/UserRoleCheck.php` | FR-ID-001 |
| `View/BoardPage.php` | FR-ID-001 |
| `View/BoardView.php` | FR-ID-001 |
| `View/TaskColumnEntry.php` | FR-ID-001 |
| `View/TaskCompletionSample.php` | FR-ID-001 |
| `View/TaskFlowDay.php` | FR-ID-001 |
| `View/TaskPage.php` | FR-ID-001 |
| `View/TaskView.php` | FR-ID-001 |
| `View/Views.php` | FR-ID-001 |

## Additions 2.31–3.0 (dialogs on demand, validators domain, edit-form revision lock, script banner, question counts, 3.0 port removal)

Each row names the semantic requirement of [`spec.md`](spec.md#additions-231-30). In 3.0.0 the 22 entity-typed host
interfaces moved to `@internal` seams under `Port/Internal/` (their rows above now name the new path, FR-ARCH-301), and
`Bridge/TimeTrack/TaskBoardTaskProvider.php`, `Bridge/TimeTrack/TaskBoardTeamContextProvider.php`,
`Doctrine/RolledBackHostRows.php`, `Doctrine/TaskBoardHostFlushProbe.php` and
`Import/BoardAwareTaskImportUserResolverInterface.php` and `Resources/views/manage/_legacy_layout.html.twig` were
removed (their rows are gone).

| Source file | Requirement IDs |
| --- | --- |
| `DependencyInjection/Compiler/RemovedPortsPass.php` | FR-ARCH-301 |
| `DependencyInjection/Compiler/TimeTrackScopePortPass.php` | FR-ARCH-302 |
| `Event/TenantAccessCheckEvent.php` | FR-SYNC-015 |
| `Form/ImportPlanStepFormType.php` | FR-SYNC-015 |
| `Port/Internal/TenantCredentialResolverInterface.php` | FR-SYNC-015 |
| `Question/TaskQuestionCountsInterface.php` | FR-QUEST-003 |
| `Resources/assets/src/logger.ts` | FR-ASSET-011 |
| `Resources/config/doctrine-xml/sync/TaskSyncImportPlan.orm.xml` | FR-SYNC-015 |
| `Resources/config/doctrine-xml/sync/TaskSyncImportPlanItem.orm.xml` | FR-SYNC-015 |
| `Resources/translations/validators.de.yaml` | FR-VAL-001 |
| `Resources/translations/validators.en.yaml` | FR-VAL-001 |
| `Resources/translations/validators.es.yaml` | FR-VAL-001 |
| `Resources/translations/validators.fr.yaml` | FR-VAL-001 |
| `Resources/translations/validators.it.yaml` | FR-VAL-001 |
| `Resources/translations/validators.nl.yaml` | FR-VAL-001 |
| `Resources/translations/validators.pt.yaml` | FR-VAL-001 |
| `Resources/views/manage/_import_plan_code.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/_import_plan_form.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/_import_plan_next.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/_import_plan_preview.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/_import_plan_run.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/_import_plan_tree.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/_task_dependency_dialog.html.twig` | FR-UI-021 |
| `Resources/views/manage/_task_question_dialog.html.twig` | FR-UI-021 |
| `Resources/views/manage/_task_time_dialog.html.twig` | FR-UI-021 |
| `Resources/views/manage/dialog/_cancel.html.twig` | FR-UI-021 |
| `Resources/views/manage/dialog/fragment.html.twig` | FR-UI-021 |
| `Resources/views/manage/dialog/page.html.twig` | FR-UI-021 |
| `Resources/views/manage/import_plan.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/import_plans.html.twig` | FR-SYNC-015 |
| `Resources/views/manage/board_fields.html.twig` | FR-FIELD-002 |
| `Resources/views/manage/board_field.html.twig` | FR-FIELD-002 |
| `Service/TaskRevisionLockInterface.php` | FR-LOCK-002 |
| `Support/TaskContentDigest.php` | FR-LOCK-002 |
| `Sync/Entity/TaskSyncImportPlan.php` | FR-SYNC-015 |
| `Sync/Entity/TaskSyncImportPlanItem.php` | FR-SYNC-015 |
| `Sync/Event/ImportPlanEvent.php` | FR-SYNC-015 |
| `Sync/Hook/TenantCredentialSourceInterface.php` | FR-SYNC-015 |
| `Sync/Http/ProviderRateBudget.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanException.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanItemStatus.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanLayout.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanPhase.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanPreviewer.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanRunner.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanRunnerInterface.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanService.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanStatus.php` | FR-SYNC-015 |
| `Sync/Plan/ImportPlanStep.php` | FR-SYNC-015 |
| `Sync/Plan/InlinePlanRunner.php` | FR-SYNC-015 |
| `Sync/Plan/MessengerPlanRunner.php` | FR-SYNC-015 |
| `Sync/Plan/PlanPreview.php` | FR-SYNC-015 |
| `Sync/Plan/PlanRunRequest.php` | FR-SYNC-015 |
| `Sync/Plan/ReimportMode.php` | FR-SYNC-015 |
| `Sync/Plan/RunImportPlanSlice.php` | FR-SYNC-015 |
| `Sync/Plan/RunImportPlanSliceHandler.php` | FR-SYNC-015 |
| `Sync/Provider/CustomFieldListProviderInterface.php` | FR-SYNC-015 |
| `Sync/Repository/ImportPlanStore.php` | FR-SYNC-015 |
| `ValueObject/TenantRef.php` | FR-SYNC-015 |

## Additions 3.4.0 (client API v1, personal-data export, Forget this person, token page way back)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Bridge/UserKit/TaskBoardUserKitExportProvider.php` | Personal-data export (3.4.0, UserKit section task_board) | FR-PD-001 |
| `ClientApi/Contract/TaskClientContextPolicyInterface.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Contract/TaskClientFileResolverInterface.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Contract/TaskClientPrincipalResolverInterface.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Contract/TaskClientReferenceResolverInterface.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Contract/TaskClientSourceLinkInterface.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Controller/TaskClientApiController.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Model/TaskClientContext.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Model/TaskClientPrincipal.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Model/TaskClientReference.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Model/TaskClientRequest.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Model/TaskClientSource.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Model/TaskClientSourceLink.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Security/TaskClientApiListener.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Security/TaskClientAuthenticator.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientApiException.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientCall.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientCursor.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientIdempotency.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientInput.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientQueries.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientReader.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientSerializer.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/TaskClientWriter.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Text/TaskTextMemo.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `ClientApi/Text/TaskTextTokens.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `Event/TaskClientApiDeniedEvent.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `Form/ConnectionPeopleForgetFormType.php` | Forget this person (3.4.0, Beacon spec 208 E) | FR-SYNC-016 |
| `Link/TaskObjectLinkVisibilityInterface.php` | Client API v1 (3.4.0, Beacon Desktop BD-3b) | FR-CLIENT-001 |
| `PersonalData/TaskBoardPersonalDataExporter.php` | Personal-data export (3.4.0, UserKit section task_board) | FR-PD-001 |
| `PersonalData/TaskBoardPersonalDataExporterInterface.php` | Personal-data export (3.4.0, UserKit section task_board) | FR-PD-001 |
| `Port/Internal/TenantCredentialReturnResolverInterface.php` | The token page's way back (3.4.0) | FR-SYNC-017 |
| `Resources/views/manage/_connection_person_forget_dialog.html.twig` | Forget this person (3.4.0, Beacon spec 208 E) | FR-SYNC-016 |
| `Sync/Event/SyncPersonForgetCheckEvent.php` | Forget this person (3.4.0, Beacon spec 208 E) | FR-SYNC-016 |
| `Sync/Hook/TenantCredentialReturnAwareSourceInterface.php` | The token page's way back (3.4.0) | FR-SYNC-017 |

## Coverage summary

| Section | Rows |
| --- | ---: |
| PHP classes (`src/**/*.php`) | 180 |
| Provider sync additions in 1.27.0 (`src/`) | 28 |
| TypeScript & CSS (`src/Resources/assets/src/`) | 26 |
| Legacy assets (`src/Resources/public/`) | 2 |
| Twig views (`src/Resources/views/`) | 39 |
| Config & translations | 9 |
| Additions 1.23–2.20 (API, sync, ports, numbers, calendar, recurrence, folders, fields, templates) | 633 |
| Additions 2.21-2.30 (locks, menus, API conflicts, questions, calendar sources, header search, host ports) | 135 |
| Additions 2.31–3.0 (dialogs on demand, validators domain, edit-form revision lock, script banner, question counts, 3.0 port removal) | 19 |
| Additions 3.4.0 (client API v1, personal-data export, Forget this person, token page way back) | 35 |
| **Rows in this file** | **1106** |

Production files under `src/` (everything tracked except co-located Vitest files): **1071**, all named in this inventory, one row per file (rows = production files; the co-located Vitest files below have no row).

Excluded (25 co-located Vitest files, test artifacts): `Resources/assets/src/anchor-utils.test.ts`, `Resources/assets/src/ask-confirm.test.ts`, `Resources/assets/src/board-create-steps-controller.test.ts`, `Resources/assets/src/board-openers.test.ts`, `Resources/assets/src/calendar-utils.test.ts`, `Resources/assets/src/column-collapse.test.ts`, `Resources/assets/src/css-vars.test.ts`, `Resources/assets/src/edit-column-current-icon.test.ts`, `Resources/assets/src/edit-column-delete-warning.test.ts`, `Resources/assets/src/edit-column-humans-only.test.ts`, `Resources/assets/src/filter-fold.test.ts`, `Resources/assets/src/gantt-scale.test.ts`, `Resources/assets/src/list-filter-utils.test.ts`, `Resources/assets/src/list-sort-utils.test.ts`, `Resources/assets/src/logger.test.ts`, `Resources/assets/src/mention-utils.test.ts`, `Resources/assets/src/option-filter-utils.test.ts`, `Resources/assets/src/storage.test.ts`, `Resources/assets/src/sync-utils.test.ts`, `Resources/assets/src/task-board-css.test.ts`, `Resources/assets/src/task-board.test.ts`, `Resources/assets/src/task-menu-shared.test.ts`, `Resources/assets/src/task-nav-utils.test.ts`, `Resources/assets/src/task-questions.test.ts`, `Resources/assets/src/time-utils.test.ts`.

Audit command: `git ls-files src | wc -l` → **1096** (2026-11-24, 3.0.0); `git ls-files src | grep -vc '\.test\.ts$'` → **1071** production files.
