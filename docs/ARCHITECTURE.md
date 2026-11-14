# Architecture: standard Symfony and DDD hosts

TaskBoard works out of the box in a **standard Symfony application** (attribute-mapped entities, the bundle's
controllers and services on your entity manager, Twig overrides) and can be used from a **strict DDD / hexagonal host**
(plain-PHP domain, framework-free ports, the bundle as a separate bounded context). The standard path is the zero-config
default; the DDD path is opt-in and never forced (REQ-ARCH-001). Both work in FrankenPHP worker mode with the kernel
kept alive and not reset (REQ-RUNTIME-002, see [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md)).

## Table of contents

- [Host ports](#host-ports)
- [Events](#events)
- [Persistence](#persistence)
- [Mapping and user references](#mapping-and-user-references)
- [Both host kinds, tested](#both-host-kinds-tested)

## Host ports

A host extends TaskBoard through ports it implements (service ids in the configuration). Since 2.15 every port that took
a bundle entity, the HTTP `Request` or an `UploadedFile` has a **twin** that takes and returns only bundle value objects,
scalars, enums and your own user object:

| Configuration key | Deprecated interface (removed in 3.0) | 2.15 twin |
|---|---|---|
| `api.hooks.authenticator` | `Api\Hook\TaskApiAuthenticatorInterface` | `Api\Hook\TaskApiRequestAuthenticatorInterface` |
| `api.hooks.attachments` | `Api\Hook\TaskApiAttachmentsInterface` | `Api\Hook\TaskApiAttachmentStoreInterface` |
| `api.hooks.time_entries` | `Api\Hook\TaskApiTimeEntriesInterface` | `Api\Hook\TaskApiTimeStoreInterface` |
| `assignee_provider` | `Assignee\TaskAssigneeProviderInterface` | `Assignee\TaskAssigneePolicyInterface` |
| `comments.cause_labeler` | `Comment\TaskCommentCauseLabelerInterface` | `Comment\TaskCommentCauseTextInterface` |
| `comments.mention_resolver` | `Comment\TaskMentionCandidatesResolverInterface` | `Comment\TaskMentionCandidatesProviderInterface` |
| `history.cause_labeler` | `History\TaskChangeCauseLabelerInterface` | `History\TaskChangeCauseTextInterface` |
| `TaskImportUserResolverInterface` alias | `Import\BoardAwareTaskImportUserResolverInterface` | `Import\BoardScopedTaskImportUserResolverInterface` |
| `TaskImportLinkLookupInterface` alias | `Import\TaskImportLinkLookupInterface` | `Import\TaskImportLinkFinderInterface` |
| `move.targets` | `Move\TaskBoardTargetsInterface` | `Move\TaskBoardTargetIdsInterface` |
| `board.default_view_resolver` | `View\TaskBoardDefaultViewResolverInterface` | `View\TaskBoardDefaultViewPolicyInterface` |
| `tenancy.first_board_provisioner` | `Tenancy\FirstBoardProvisionerInterface` | `Tenancy\FirstBoardProvisioningInterface` |
| `sync.people.resolver` (optional extra) | `Sync\Hook\AttributionOnlyResolverInterface` | `Sync\Hook\AttributionOnlyPolicyInterface` |
| `sync.time_entries.sink` | `Sync\Hook\ImportedTimeEntrySinkInterface`, `OwnerlessTimeEntrySinkInterface` | `Sync\Hook\ImportedTimeEntryReceiverInterface`, `OwnerlessTimeEntryReceiverInterface` |
| `sync.inline_image_rewriter` | `Sync\Hook\InlineImageRewriterInterface` | `Sync\Hook\SyncedImageRewriterInterface` |
| `sync.people.inviter` | `Sync\Hook\PeopleInviterInterface` | `Sync\Hook\PeopleInvitationInterface` |
| `sync.credential_resolver` | `Sync\Hook\ProviderCredentialResolverInterface` | `Sync\Hook\ProviderCredentialSourceInterface` |
| `sync.actor_resolver` | `Sync\Hook\SyncActorResolverInterface` | `Sync\Hook\SyncActorProviderInterface` |
| `sync.limit_checker` | `Sync\Hook\SyncLimitCheckerInterface` | `Sync\Hook\SyncQuotaPolicyInterface` |
| `sync.people.resolver` | `Sync\Hook\SyncPersonResolverInterface` | `Sync\Hook\SyncPersonDirectoryInterface` |
| `sync.attachment_storage` | `Sync\Hook\TaskAttachmentStorageInterface` | `Sync\Hook\SyncedFileStorageInterface` |

Value objects of the twins: `ValueObject\TaskBoardSnapshot`, `TaskSnapshot`, `TaskCommentSnapshot`,
`TaskChangeSnapshot`, `TaskSyncDirectorySnapshot` (copied when the port is called: ids, names, dates and the creator's
id, never an entity, a collection or a user object), `Api\Value\TaskApiRequestContext` (method, path, route, client IP,
the `Authorization` credential, headers; never a cookie) and `Api\Value\TaskApiUpload`.

**Selection rule.** The same configuration key takes either interface. A service that implements the deprecated one is
asked exactly as in 2.14, also when it implements the twin too; one that implements only the twin is asked through an
internal gate (`Port\*Gate`, built by `Port\Ports`). `TaskBoardPortsPass` stops the container build when a configured
service implements neither (a factory service, whose class the build cannot know, fails closed when it is built).

The other ports (`TaskBoardAccessCheckerInterface`, `TaskBoardTenantResolverInterface`, `TaskObjectLinkResolverInterface`,
`MultiBoardScopeProviderInterface`, `CalendarDayAnnotationProviderInterface`, `Template\TaskTemplatePermissionInterface` (2.16: `TaskTemplateScope`, `TaskBoardSnapshot`, `TaskTemplateSnapshot`), the API users / workspace / plan gate /
rate limiter / idempotency store, the providers, ...) already take values only.

**Since 2.16 (Beacon spec 234), new ports are framework-free from the start** (no deprecated twin):

| Configuration key | Interface | Value objects |
|---|---|---|
| `column_transition_policy` | `Transition\ColumnTransitionPolicyInterface` (default `AllowAllColumnTransitionPolicy`; opt-in `HumansOnlyColumnTransitionPolicy`) | `ValueObject\ColumnTransition` (board, task and column snapshots, tags, actor, `ColumnTransitionVia`), `ColumnTransitionDecision` |
| `api.hooks.members` | `Api\Hook\TaskApiMembersInterface` (default `EmptyTaskApiMembers`) | `Api\Value\TaskApiMember` |
| `api.hooks.comment_edit_policy` | `Api\Hook\TaskApiCommentEditPolicyInterface` (default `AuthorOnlyTaskApiCommentEditPolicy`) | `ValueObject\TaskCommentSnapshot` |
| `search.provider` | `Search\TaskTextSearchInterface` (default `DoctrineTaskTextSearch`) | `ValueObject\TaskTextQuery` |

The policy is a domain rule: `TaskManager` asks it before every move, whatever the channel, and the Task API passes its
principal's channel through `ColumnTransitionVia`, so a host writes its rules once for the board UI, the API and MCP.
`TaskBoardEvents::TASK_STATUS_CHANGED` (`TaskStatusChangedEvent`, snapshots only) follows every column change.

**Since 2.17.0 (Beacon spec 234).** New ports are framework-free from the start (no deprecated twin):
`restructure.policy` → `Folder\TaskBoardRestructurePolicyInterface` (`mayManageFolders($user, ?$tenantKey)`,
`mayRestructureBoard($user, TaskBoardSnapshot)`); the public service `Folder\TaskBoardRestructureInterface` takes ids
and returns `Folder\TaskBoardFolderSnapshot` / `TaskBoardMoveResult`. Every restructure write runs in one transaction it
opens and closes; on a failure after in-memory changes the entity manager is closed and reset through the registry, so
a worker with kernel reset off never keeps a closed manager; `TASK_BOARD_CHANGED` goes out after the commit.

## Events

Every event that carries a bundle entity also offers ids and snapshots (`getBoardId()`, `getTaskId()`,
`getTenantKey()`, `getActorId()` / `getSubjectId()`, `getBoardSnapshot()`, `getTaskSnapshot()`, `getCommentSnapshot()`,
...). The entity members are deprecated and removed in 3.0. Write events are dispatched after the write is saved,
through `TaskEventDeferral` (after the commit of a deferred write, dropped on a rollback).

## Persistence

**Standard host (default).** TaskBoard reads and writes through `database.entity_manager` (`default`), exactly as up to
2.14: a TaskBoard save flushes that manager, including what your application left pending in it.

**Private entity manager (opt-in, `database.private_entity_manager: true`; the only mode in 3.0).** Every bundle
service reads and writes through `Doctrine\TaskBoardEntityManager`, a private manager created on the connection,
mapping, caches and event manager of `database.entity_manager` (REQ-ARCH-001 rule 6):

- it never flushes, clears or closes your manager; a failed TaskBoard write closes only the private one, which is
  created again on the next call (also when its connection changed, or when a rollback undid rows it wrote: DBAL 4
  middleware on the source connection only);
- it is dropped at every outermost unit-of-work boundary (main request, Messenger message, console command) and at
  `kernel.reset`: dropped first, then its pending changes are logged (classes and counts only) and cleared;
- the source manager's SQL filters are mirrored on every call;
- repositories: service repositories come from your configuration's factory, every other one is built and kept per
  manager (no growth in a kept-alive worker);
- your security user and other host objects on TaskBoard rows are adopted as the private manager's references before
  each flush; users read back from TaskBoard rows are the private manager's instances, so hooks and listeners compare
  users **by id**, never with `===` (audit OC-L1);
- TaskBoard bulk / DBAL statements inside your open transaction run in a savepoint; a failure rolls back to it and the
  original exception is rethrown.

`PrivateEntityManagerReferencesPass` moves every service of the bundle onto the private manager and its registry at
build time; your services keep your manager.

## Mapping and user references

| | Standard host (default) | DDD host (opt-in) |
|---|---|---|
| Mapping of the 29 bundle entities (2.16: `TaskTemplate`, group `template`) | ORM attributes | `database.mapping_type: xml` (`Resources/config/doctrine-xml`, generated from the attributes, identical metadata) |
| Host users on TaskBoard rows | `ManyToOne` to `user_class` (a Doctrine entity in the same manager) | `database.user_reference: id`: the id in a string column (`TaskBoardUserRef`), found again through `user_directory` |
| Bundle base classes / traits for host entities | none (the bundle owns its entities; the host references them by id) | none |

The bundle's entities are bundle-owned: a host never extends them and references them by id (`TaskSnapshot::$id`,
`TaskBoardSnapshot::$id`, the id getters of the events). With the id mode the bundle never queries the host's user table:
DQL compares the id column, lookups go through the directory (re-checked byte for byte), and the column compares in
binary on MySQL / MariaDB.

## Both host kinds, tested

- **Standard host**: the whole unit and functional suite (`FunctionalKernel`: attribute mapping, `AppUser` Doctrine
  entity, the deprecated ports' stubs), plus its replays: every host port through its twin only
  (`TASK_BOARD_TEST_PORTS=twin`) and the private entity manager on (`TASK_BOARD_TEST_PRIVATE_EM=1`), each a CI job.
- **DDD host**: `tests/Functional/DddHost` (`DddHostKernel`: plain PHP users with no mapping, XML mapping, private entity
  manager, user references by id, twin ports only), and `XmlMappingParityTest`.
