<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntryBillableInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\BoardScopedTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;

use function get_debug_type;
use function sprintf;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): picks the interface the bundle asks a host service through, per object:
 *
 * - the service implements the deprecated interface: it is returned as is and asked exactly as in 2.14 (also when it
 *   implements the 2.15 twin too, until 3.0; a decorator decides by its own class);
 * - it implements only the 2.15 twin: it is wrapped in the twin's gate (stateless);
 * - neither: a `LogicException` (fail closed). The build already fails for a class known before compilation
 *   ({@see TaskBoardPortsPass}); these factories are what the container
 *   calls for a service whose class it cannot know (a factory service).
 *
 * Stateless.
 *
 * @internal
 */
final class Ports
{
    public static function assigneeProvider(object $service): TaskAssigneeProviderInterface
    {
        return match (true) {
            $service instanceof TaskAssigneeProviderInterface => $service,
            $service instanceof TaskAssigneePolicyInterface   => new TaskAssigneePolicyGate($service),
            default                                           => throw self::neither($service, TaskAssigneePolicyInterface::class),
        };
    }

    public static function commentCauseLabeler(object $service): TaskCommentCauseLabelerInterface
    {
        return match (true) {
            $service instanceof TaskCommentCauseLabelerInterface => $service,
            $service instanceof TaskCommentCauseTextInterface    => new TaskCommentCauseTextGate($service),
            default                                              => throw self::neither($service, TaskCommentCauseTextInterface::class),
        };
    }

    public static function mentionCandidates(object $service): TaskMentionCandidatesResolverInterface
    {
        return match (true) {
            $service instanceof TaskMentionCandidatesResolverInterface => $service,
            $service instanceof TaskMentionCandidatesProviderInterface => new TaskMentionCandidatesGate($service),
            default                                                    => throw self::neither($service, TaskMentionCandidatesProviderInterface::class),
        };
    }

    public static function changeCauseLabeler(object $service): TaskChangeCauseLabelerInterface
    {
        return match (true) {
            $service instanceof TaskChangeCauseLabelerInterface => $service,
            $service instanceof TaskChangeCauseTextInterface    => new TaskChangeCauseTextGate($service),
            default                                             => throw self::neither($service, TaskChangeCauseTextInterface::class),
        };
    }

    public static function importLinkLookup(object $service): TaskImportLinkLookupInterface
    {
        return match (true) {
            $service instanceof TaskImportLinkLookupInterface => $service,
            $service instanceof TaskImportLinkFinderInterface => new TaskImportLinkFinderGate($service),
            default                                           => throw self::neither($service, TaskImportLinkFinderInterface::class),
        };
    }

    public static function boardTargets(object $service, TaskBoardRepositoryInterface $boards): TaskBoardTargetsInterface
    {
        return match (true) {
            $service instanceof TaskBoardTargetsInterface   => $service,
            $service instanceof TaskBoardTargetIdsInterface => new TaskBoardTargetIdsGate($service, $boards),
            default                                         => throw self::neither($service, TaskBoardTargetIdsInterface::class),
        };
    }

    public static function defaultViewResolver(object $service): TaskBoardDefaultViewResolverInterface
    {
        return match (true) {
            $service instanceof TaskBoardDefaultViewResolverInterface => $service,
            $service instanceof TaskBoardDefaultViewPolicyInterface   => new TaskBoardDefaultViewGate($service),
            default                                                   => throw self::neither($service, TaskBoardDefaultViewPolicyInterface::class),
        };
    }

    public static function firstBoardProvisioner(object $service, TaskBoardRepositoryInterface $boards): FirstBoardProvisionerInterface
    {
        return match (true) {
            $service instanceof FirstBoardProvisionerInterface  => $service,
            $service instanceof FirstBoardProvisioningInterface => new FirstBoardProvisioningGate($service, $boards),
            default                                             => throw self::neither($service, FirstBoardProvisioningInterface::class),
        };
    }

    public static function apiAuthenticator(object $service): TaskApiAuthenticatorInterface
    {
        return match (true) {
            $service instanceof TaskApiAuthenticatorInterface        => $service,
            $service instanceof TaskApiRequestAuthenticatorInterface => new TaskApiRequestAuthenticatorGate($service),
            default                                                  => throw self::neither($service, TaskApiRequestAuthenticatorInterface::class),
        };
    }

    public static function apiAttachments(object $service): TaskApiAttachmentsInterface
    {
        return match (true) {
            $service instanceof TaskApiAttachmentsInterface     => $service,
            $service instanceof TaskApiAttachmentStoreInterface => new TaskApiAttachmentStoreGate($service),
            default                                             => throw self::neither($service, TaskApiAttachmentStoreInterface::class),
        };
    }

    /**
     * A twin that also implements {@see TaskApiTimeEntryBillableInterface} keeps `PUT .../time_entries/{timer_id}`.
     */
    public static function apiTimeEntries(object $service): TaskApiTimeEntriesInterface
    {
        return match (true) {
            $service instanceof TaskApiTimeEntriesInterface                                                        => $service,
            $service instanceof TaskApiTimeStoreInterface && $service instanceof TaskApiTimeEntryBillableInterface => new TaskApiBillableTimeStoreGate($service),
            $service instanceof TaskApiTimeStoreInterface                                                          => new TaskApiTimeStoreGate($service),
            default                                                                                                => throw self::neither($service, TaskApiTimeStoreInterface::class),
        };
    }

    /**
     * The optional attribution-only answer of a person resolver: the deprecated interface first (as in 2.14), then the
     * twin; false when the service implements neither.
     */
    public static function isAttributionOnly(object $service, TaskBoard $board, object $user): bool
    {
        return match (true) {
            $service instanceof AttributionOnlyResolverInterface => $service->isAttributionOnly($board, $user),
            $service instanceof AttributionOnlyPolicyInterface   => $service->isAttributionOnly(Snapshots::board($board), $user),
            default                                              => false,
        };
    }

    /**
     * Who an e-mail is, told the board where the service wants it: the deprecated board-aware interface first (as in
     * 2.14), then the twin, else `resolveByEmail()`.
     */
    public static function importUser(TaskImportUserResolverInterface $resolver, string $email, TaskBoard $board): ?object
    {
        return match (true) {
            $resolver instanceof BoardAwareTaskImportUserResolverInterface  => $resolver->resolveByEmailForBoard($email, $board),
            $resolver instanceof BoardScopedTaskImportUserResolverInterface => $resolver->resolveByEmailOnBoard($email, Snapshots::board($board)),
            default                                                         => $resolver->resolveByEmail($email),
        };
    }

    /**
     * An ownerless receiver keeps the hours of people with no host user.
     */
    public static function timeEntrySink(object $service): ImportedTimeEntrySinkInterface
    {
        return match (true) {
            $service instanceof ImportedTimeEntrySinkInterface      => $service,
            $service instanceof OwnerlessTimeEntryReceiverInterface => new OwnerlessTimeEntryReceiverGate($service),
            $service instanceof ImportedTimeEntryReceiverInterface  => new ImportedTimeEntryReceiverGate($service),
            default                                                 => throw self::neither($service, ImportedTimeEntryReceiverInterface::class),
        };
    }

    public static function inlineImageRewriter(object $service): InlineImageRewriterInterface
    {
        return match (true) {
            $service instanceof InlineImageRewriterInterface => $service,
            $service instanceof SyncedImageRewriterInterface => new SyncedImageRewriterGate($service),
            default                                          => throw self::neither($service, SyncedImageRewriterInterface::class),
        };
    }

    public static function peopleInviter(object $service): PeopleInviterInterface
    {
        return match (true) {
            $service instanceof PeopleInviterInterface    => $service,
            $service instanceof PeopleInvitationInterface => new PeopleInvitationGate($service),
            default                                       => throw self::neither($service, PeopleInvitationInterface::class),
        };
    }

    public static function credentialResolver(object $service): ProviderCredentialResolverInterface
    {
        return match (true) {
            $service instanceof ProviderCredentialResolverInterface => $service,
            $service instanceof ProviderCredentialSourceInterface   => new ProviderCredentialSourceGate($service),
            default                                                 => throw self::neither($service, ProviderCredentialSourceInterface::class),
        };
    }

    public static function actorResolver(object $service): SyncActorResolverInterface
    {
        return match (true) {
            $service instanceof SyncActorResolverInterface => $service,
            $service instanceof SyncActorProviderInterface => new SyncActorProviderGate($service),
            default                                        => throw self::neither($service, SyncActorProviderInterface::class),
        };
    }

    public static function limitChecker(object $service): SyncLimitCheckerInterface
    {
        return match (true) {
            $service instanceof SyncLimitCheckerInterface => $service,
            $service instanceof SyncQuotaPolicyInterface  => new SyncQuotaPolicyGate($service),
            default                                       => throw self::neither($service, SyncQuotaPolicyInterface::class),
        };
    }

    public static function personResolver(object $service): SyncPersonResolverInterface
    {
        return match (true) {
            $service instanceof SyncPersonResolverInterface  => $service,
            $service instanceof SyncPersonDirectoryInterface => new SyncPersonDirectoryGate($service),
            default                                          => throw self::neither($service, SyncPersonDirectoryInterface::class),
        };
    }

    public static function attachmentStorage(object $service): TaskAttachmentStorageInterface
    {
        return match (true) {
            $service instanceof TaskAttachmentStorageInterface => $service,
            $service instanceof SyncedFileStorageInterface     => new SyncedFileStorageGate($service),
            default                                            => throw self::neither($service, SyncedFileStorageInterface::class),
        };
    }

    private static function neither(object $service, string $interface): LogicException
    {
        return new LogicException(sprintf('nowo_task_board: the host service "%s" must implement "%s" (or the interface it replaces).', get_debug_type($service), $interface));
    }
}
