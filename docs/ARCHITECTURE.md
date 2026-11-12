# Architecture: standard Symfony and DDD hosts

TaskBoard works out of the box in a **standard Symfony application** (attribute-mapped entities, the bundle's
controllers and services on your entity manager, Twig overrides) and can be used from a **strict DDD / hexagonal host**
(plain-PHP domain, framework-free ports, the bundle as a separate bounded context). The standard path is the zero-config
default; the DDD path is opt-in and never forced (REQ-ARCH-001). Both work in FrankenPHP worker mode with the kernel
kept alive and not reset (REQ-RUNTIME-002, see [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md)).

## Table of contents

- [Host ports](#host-ports)
- [Events](#events)

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
`MultiBoardScopeProviderInterface`, `CalendarDayAnnotationProviderInterface`, the API users / workspace / plan gate /
rate limiter / idempotency store, the providers, ...) already take values only.

## Events

Every event that carries a bundle entity also offers ids and snapshots (`getBoardId()`, `getTaskId()`,
`getTenantKey()`, `getActorId()` / `getSubjectId()`, `getBoardSnapshot()`, `getTaskSnapshot()`, `getCommentSnapshot()`,
...). The entity members are deprecated and removed in 3.0. Write events are dispatched after the write is saved,
through `TaskEventDeferral` (after the commit of a deferred write, dropped on a rollback).
