# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/task-board-bundle`  
**Last audited**: 2026-07-07

Production scope excludes Vitest sources (`*.test.ts`).

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Bridge/TimeTrack/TaskBoardTaskProvider.php` | TimeTrack bridge | FR-BRIDGE-001 |
| `Bridge/TimeTrack/TaskBoardTeamContextProvider.php` | TimeTrack bridge | FR-BRIDGE-001 |
| `Command/TaskBoardImportCommand.php` | Import CLI | FR-CLI-001 |
| `Controller/TaskBoardManageController.php` | Manage UI controller | FR-CTRL-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace | FR-TWIG-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/TaskBoardExtension.php` | DI extension | FR-CFG-002 |
| `Doctrine/TaskBoardMetadataListener.php` | Table prefix | FR-DOCTRINE-001 |
| `Dto/BoardColumnFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskBoardFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskColumnNavigation.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskGanttItem.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskGanttLink.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskGanttTimeline.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskImportFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskLinkFormData.php` | Form/API DTO | FR-DTO-001 |
| `Dto/TaskMemberFormData.php` | Form/API DTO | FR-DTO-001 |
| `Entity/BoardColumn.php` | Domain entity | FR-ENTITY-001 |
| `Entity/Task.php` | Domain entity | FR-ENTITY-002 |
| `Entity/TaskBoard.php` | Domain entity | FR-ENTITY-003 |
| `Entity/TaskChangeHistory.php` | Domain entity | FR-ENTITY-004 |
| `Entity/TaskDependency.php` | Domain entity | FR-ENTITY-005 |
| `Entity/TaskDocument.php` | Domain entity | FR-ENTITY-006 |
| `Entity/TaskLink.php` | Domain entity | FR-ENTITY-007 |
| `Entity/TaskMember.php` | Domain entity | FR-ENTITY-008 |
| `Entity/TaskTimeEntry.php` | Domain entity | FR-ENTITY-009 |
| `Entity/Team.php` | Domain entity | FR-ENTITY-010 |
| `Entity/TeamMember.php` | Domain entity | FR-ENTITY-011 |
| `Enum/TaskChangeType.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskDependencyType.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskLinkType.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskMemberRole.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskPriority.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TaskStatus.php` | Task/board enums | FR-ENUM-001 |
| `Enum/TeamRole.php` | Task/board enums | FR-ENUM-001 |
| `Event/BoardListQueryEvent.php` | Domain event | FR-EVT-002 |
| `Event/TaskAccessCheckEvent.php` | Domain event | FR-EVT-002 |
| `Event/TaskBoardEvents.php` | Event names | FR-EVT-001 |
| `EventListener/TimeSpentAggregatorListener.php` | TimeTrack aggregation | FR-BRIDGE-002 |
| `Form/BoardColumnFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskBoardFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskImportFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskLinkFormType.php` | Form type | FR-FORM-001 |
| `Form/TaskMemberFormType.php` | Form type | FR-FORM-001 |
| `Import/ClickUp/ClickUpCsvImporter.php` | External importers | FR-IMPORT-005 |
| `Import/ClickUp/ClickUpJsonImporter.php` | External importers | FR-IMPORT-005 |
| `Import/Dto/ImportedTaskDto.php` | Import DTOs | FR-IMPORT-006 |
| `Import/Dto/TaskImportOptions.php` | Import DTOs | FR-IMPORT-006 |
| `Import/Dto/TaskImportResult.php` | Import DTOs | FR-IMPORT-006 |
| `Import/Jira/JiraCsvImporter.php` | External importers | FR-IMPORT-005 |
| `Import/NullTaskImportUserResolver.php` | Null user resolver | FR-IMPORT-003 |
| `Import/Support/DelimitedTableParser.php` | Import parsers | FR-IMPORT-004 |
| `Import/Support/ImportFieldMapper.php` | Import parsers | FR-IMPORT-004 |
| `Import/TaskImportOrchestrator.php` | Import orchestrator | FR-IMPORT-001 |
| `Import/TaskImportSource.php` | Import contracts | FR-IMPORT-002 |
| `Import/TaskImportUserResolverInterface.php` | Import contracts | FR-IMPORT-002 |
| `Import/TaskImporterInterface.php` | Import contracts | FR-IMPORT-002 |
| `Import/Trello/TrelloJsonImporter.php` | External importers | FR-IMPORT-005 |
| `Repository/BoardColumnRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/DoctrineOrmBoardColumnRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskBoardRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskChangeHistoryRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskDependencyRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskDocumentRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskLinkRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskMemberRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTaskTimeEntryRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTeamMemberRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/DoctrineOrmTeamRepository.php` | Doctrine repository | FR-REPO-002 |
| `Repository/TaskBoardRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskChangeHistoryRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskDependencyRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskDocumentRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskLinkRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskMemberRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TaskTimeEntryRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Repository/TeamMemberRepositoryInterface.php` | Repository interface | FR-REPO-001 |
| `Routing/TaskBoardRouteLoader.php` | Route loader | FR-ROUTE-001 |
| `Security/ConfigurableTaskBoardAccessChecker.php` | Access control | FR-SEC-002 |
| `Security/NullTaskBoardTeamMembershipResolver.php` | Access control | FR-SEC-004 |
| `Security/TaskBoardAccessCheckerInterface.php` | Access control | FR-SEC-001 |
| `Security/TaskBoardTeamMembershipResolverInterface.php` | Access control | FR-SEC-003 |
| `Service/BoardColumnManager.php` | Domain service | FR-SVC-001 |
| `Service/TaskAccessGuard.php` | Domain service | FR-SVC-002 |
| `Service/TaskBoardCreator.php` | Domain service | FR-SVC-003 |
| `Service/TaskChangeRecorder.php` | Domain service | FR-SVC-004 |
| `Service/TaskGanttBuilder.php` | Domain service | FR-SVC-005 |
| `Service/TaskLinkAttacher.php` | Domain service | FR-SVC-006 |
| `Service/TaskManager.php` | Domain service | FR-SVC-007 |
| `Service/TaskMemberAssigner.php` | Domain service | FR-SVC-008 |
| `Support/GitLabMergeRequestLinkParser.php` | Support utilities | FR-SUP-001 |
| `Support/SlugGenerator.php` | Support utilities | FR-SUP-001 |
| `Support/UserIdResolver.php` | Support utilities | FR-SUP-001 |
| `TaskBoardBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `ValueObject/Uuid.php` | UUID helper | FR-VO-001 |

## TypeScript & CSS (`src/Resources/assets/src/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/add-column-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/add-task-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/board-controller.ts` | Frontend assets | FR-UI-002 |
| `Resources/assets/src/edit-column-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/gantt-controller.ts` | Frontend assets | FR-UI-003 |
| `Resources/assets/src/list-filter-controller.ts` | Frontend assets | FR-UI-004 |
| `Resources/assets/src/list-filter-utils.ts` | Frontend assets | FR-UI-004 |
| `Resources/assets/src/subtask-tree-controller.ts` | Frontend assets | FR-UI-005 |
| `Resources/assets/src/task-board.css` | Frontend assets | FR-UI-010 |
| `Resources/assets/src/task-board.ts` | Frontend assets | FR-UI-001 |
| `Resources/assets/src/task-links-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/task-members-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/task-priority-modal-controller.ts` | Modal Stimulus controllers | FR-UI-007 |
| `Resources/assets/src/task-status-controller.ts` | Frontend assets | FR-UI-006 |

## Legacy assets (`src/Resources/public/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/public/css/task-board.css` | Legacy built assets | FR-LEGACY-001 |
| `Resources/public/js/task-board.js` | Legacy built assets | FR-LEGACY-001 |

## Twig views (`src/Resources/views/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/layout.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_add_column_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_add_task_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_edit_column_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_flashes.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_page_header.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_column_navigation.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_history_section.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_links_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_links_section.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_member_label.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_members_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_priority_modal.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_priority_tag.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_status_field.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/_task_tags.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/board.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/gantt.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/import.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/index.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/list.html.twig` | Twig manage UI | FR-TPL-001 |
| `Resources/views/manage/task.html.twig` | Twig manage UI | FR-TPL-001 |
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
| PHP | 99 | 99 |
| Assets | 14 | 14 |
| Legacy assets | 2 | 2 |
| Twig | 23 | 23 |
| Config & i18n | 9 | 9 |
| **Total production sources** | **147** | **147** |

Excluded: `Resources/assets/src/list-filter-utils.test.ts`.
