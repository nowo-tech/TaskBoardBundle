<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiCommitWatermark;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Port\Internal\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Port\Internal\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Port\Internal\InlineImageRewriterInterface;
use Nowo\TaskBoardBundle\Port\Internal\PeopleInviterInterface;
use Nowo\TaskBoardBundle\Port\Internal\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Port\Internal\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskBoardDefaultViewResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskChangeCauseLabelerInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Port\Internal\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedFileStorageInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonDirectoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function is_a;
use function is_string;
use function sprintf;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): wires the framework-free host ports and refuses a port service that does not
 * implement its interface.
 *
 * Every host port the bundle asks is reached through one service id: the bundle's internal seam (an alias the extension
 * points at the configured service, or the bundle's default), or `nowo_task_board.port.<name>` for the ports the bundle
 * injects by reference. For each id that exists:
 *
 * - the service is one of the bundle's own defaults (a class of the bundle that implements the internal seam): nothing
 *   changes;
 * - it implements the framework-free interface: the id becomes that interface's internal gate over it ({@see Ports});
 * - the class is not known before compilation (a factory service): the id becomes the {@see Ports} factory, which
 *   decides per object and fails closed (`LogicException`) when the object implements neither;
 * - anything else fails the build, naming the configuration key and the interface to implement.
 *
 * Since 3.0.0 the entity-typed host interfaces of 2.14 are gone (deprecated since 2.15.0): {@see RemovedPortsPass}
 * refuses a service that still uses one before this pass runs.
 *
 * @internal
 */
final class TaskBoardPortsPass implements CompilerPassInterface
{
    /** Service id prefix of the ports the bundle injects by reference. */
    public const PORT_PREFIX = 'nowo_task_board.port.';

    /** Container parameter holding the configured `sync.actor_resolver` service id ('' when none). */
    public const ACTOR_RESOLVER_PARAMETER = 'nowo_task_board.sync.actor_resolver';

    /**
     * `seam`: the bundle's internal entity-typed interface (the 2.14 host interface until 3.0); `twin`: the framework-free
     * interface a host implements.
     *
     * @var array<string, array{id: string, seam: class-string, twin: class-string, factory: string, option: string, boards?: true}>
     */
    public const PORTS = [
        'assignee_provider'       => ['id' => TaskAssigneeProviderInterface::class, 'seam' => TaskAssigneeProviderInterface::class, 'twin' => TaskAssigneePolicyInterface::class, 'factory' => 'assigneeProvider', 'option' => 'assignee_provider'],
        'mention_resolver'        => ['id' => TaskMentionCandidatesResolverInterface::class, 'seam' => TaskMentionCandidatesResolverInterface::class, 'twin' => TaskMentionCandidatesProviderInterface::class, 'factory' => 'mentionCandidates', 'option' => 'comments.mention_resolver'],
        'comment_cause_labeler'   => ['id' => self::PORT_PREFIX . 'comment_cause_labeler', 'seam' => TaskCommentCauseLabelerInterface::class, 'twin' => TaskCommentCauseTextInterface::class, 'factory' => 'commentCauseLabeler', 'option' => 'comments.cause_labeler'],
        'change_cause_labeler'    => ['id' => self::PORT_PREFIX . 'change_cause_labeler', 'seam' => TaskChangeCauseLabelerInterface::class, 'twin' => TaskChangeCauseTextInterface::class, 'factory' => 'changeCauseLabeler', 'option' => 'history.cause_labeler'],
        'import_link_lookup'      => ['id' => TaskImportLinkLookupInterface::class, 'seam' => TaskImportLinkLookupInterface::class, 'twin' => TaskImportLinkFinderInterface::class, 'factory' => 'importLinkLookup', 'option' => 'the TaskImportLinkFinderInterface alias'],
        'board_targets'           => ['id' => TaskBoardTargetsInterface::class, 'seam' => TaskBoardTargetsInterface::class, 'twin' => TaskBoardTargetIdsInterface::class, 'factory' => 'boardTargets', 'option' => 'move.targets', 'boards' => true],
        'default_view_resolver'   => ['id' => TaskBoardDefaultViewResolverInterface::class, 'seam' => TaskBoardDefaultViewResolverInterface::class, 'twin' => TaskBoardDefaultViewPolicyInterface::class, 'factory' => 'defaultViewResolver', 'option' => 'board.default_view_resolver'],
        'first_board_provisioner' => ['id' => self::PORT_PREFIX . 'first_board_provisioner', 'seam' => FirstBoardProvisionerInterface::class, 'twin' => FirstBoardProvisioningInterface::class, 'factory' => 'firstBoardProvisioner', 'option' => 'tenancy.first_board_provisioner', 'boards' => true],
        'api_authenticator'       => ['id' => TaskApiAuthenticatorInterface::class, 'seam' => TaskApiAuthenticatorInterface::class, 'twin' => TaskApiRequestAuthenticatorInterface::class, 'factory' => 'apiAuthenticator', 'option' => 'api.hooks.authenticator'],
        'api_attachments'         => ['id' => TaskApiAttachmentsInterface::class, 'seam' => TaskApiAttachmentsInterface::class, 'twin' => TaskApiAttachmentStoreInterface::class, 'factory' => 'apiAttachments', 'option' => 'api.hooks.attachments'],
        'api_time_entries'        => ['id' => TaskApiTimeEntriesInterface::class, 'seam' => TaskApiTimeEntriesInterface::class, 'twin' => TaskApiTimeStoreInterface::class, 'factory' => 'apiTimeEntries', 'option' => 'api.hooks.time_entries'],
        'credential_resolver'     => ['id' => ProviderCredentialResolverInterface::class, 'seam' => ProviderCredentialResolverInterface::class, 'twin' => ProviderCredentialSourceInterface::class, 'factory' => 'credentialResolver', 'option' => 'sync.credential_resolver'],
        'actor_resolver'          => ['id' => SyncActorResolverInterface::class, 'seam' => SyncActorResolverInterface::class, 'twin' => SyncActorProviderInterface::class, 'factory' => 'actorResolver', 'option' => 'sync.actor_resolver'],
        'limit_checker'           => ['id' => SyncLimitCheckerInterface::class, 'seam' => SyncLimitCheckerInterface::class, 'twin' => SyncQuotaPolicyInterface::class, 'factory' => 'limitChecker', 'option' => 'sync.limit_checker'],
        'inline_image_rewriter'   => ['id' => InlineImageRewriterInterface::class, 'seam' => InlineImageRewriterInterface::class, 'twin' => SyncedImageRewriterInterface::class, 'factory' => 'inlineImageRewriter', 'option' => 'sync.inline_image_rewriter'],
        'attachment_storage'      => ['id' => TaskAttachmentStorageInterface::class, 'seam' => TaskAttachmentStorageInterface::class, 'twin' => SyncedFileStorageInterface::class, 'factory' => 'attachmentStorage', 'option' => 'sync.attachment_storage'],
        'person_resolver'         => ['id' => SyncPersonResolverInterface::class, 'seam' => SyncPersonResolverInterface::class, 'twin' => SyncPersonDirectoryInterface::class, 'factory' => 'personResolver', 'option' => 'sync.people.resolver'],
        'people_inviter'          => ['id' => self::PORT_PREFIX . 'people_inviter', 'seam' => PeopleInviterInterface::class, 'twin' => PeopleInvitationInterface::class, 'factory' => 'peopleInviter', 'option' => 'sync.people.inviter'],
        'time_entry_sink'         => ['id' => self::PORT_PREFIX . 'time_entry_sink', 'seam' => ImportedTimeEntrySinkInterface::class, 'twin' => ImportedTimeEntryReceiverInterface::class, 'factory' => 'timeEntrySink', 'option' => 'sync.time_entries.sink'],
    ];

    /**
     * @throws LogicException when a port service implements neither interface
     */
    /** Since 2.22.0 (audit SU-L1): transaction-doctrine's own commit ledger, when its bundle detects commits. */
    public const TRANSACTION_DOCTRINE_LEDGER = 'nowo_transaction_doctrine.commit_ledger';

    public function process(ContainerBuilder $container): void
    {
        // Since 2.22.0 (audit SU-L1): with transaction-doctrine's commit detection on, the API's watermark reads that bundle's
        // ledger (its write units count with it too); the bundle's own middleware keeps counting, unread.
        if ($container->hasDefinition(TaskApiCommitWatermark::class) && $container->has(self::TRANSACTION_DOCTRINE_LEDGER)) {
            $container->getDefinition(TaskApiCommitWatermark::class)->setArgument(0, new Reference(self::TRANSACTION_DOCTRINE_LEDGER));
        }

        // Since 3.0.0: the import link lookup had no configuration key (a host aliased the removed 2.14 interface); a host
        // now aliases (or defines) the framework-free TaskImportLinkFinderInterface, which then answers the bundle's seam.
        if ($container->hasAlias(TaskImportLinkFinderInterface::class) || $container->hasDefinition(TaskImportLinkFinderInterface::class)) {
            $container->setAlias(TaskImportLinkLookupInterface::class, TaskImportLinkFinderInterface::class);
        }

        foreach (self::PORTS as $port) {
            $id = $port['id'];
            if (!$container->hasAlias($id) && !$container->hasDefinition($id)) {
                continue;
            }

            $target = $this->resolve($container, $id);
            $class  = $this->classOf($container, $target);
            if ($class !== null && is_a($class, $port['seam'], true) && Ports::isOwn($class)) {
                continue; // One of the bundle's own defaults.
            }
            if ($class !== null && !is_a($class, $port['twin'], true)) {
                throw new LogicException(sprintf('nowo_task_board: the service "%s" (%s) of %s must implement "%s" (see UPGRADING.md "To 3.0.0").', $target, $class, $port['option'], $port['twin']));
            }

            $public = $container->hasAlias($id) ? $container->getAlias($id)->isPublic() : $container->getDefinition($id)->isPublic();
            if ($target === $id) {
                // The service is defined under the port id itself: keep it under an inner id.
                $container->setDefinition($id . '.inner', $container->getDefinition($id));
                $container->removeDefinition($id);
                $target = $id . '.inner';
            }
            if ($container->hasAlias($id)) {
                $container->removeAlias($id);
            }

            $arguments = [new Reference($target)];
            if (isset($port['boards'])) {
                $arguments[] = new Reference(TaskBoardRepositoryInterface::class);
            }
            $container->setDefinition($id, (new Definition($port['seam']))
                ->setFactory([Ports::class, $port['factory']])
                ->setArguments($arguments)
                ->setPublic($public));
        }

        $this->pointActorProviderAlias($container);
    }

    /**
     * Since 2.15.1 (audit OM TB-I1): the public {@see SyncActorProviderInterface} alias the extension points at the
     * bundle's default resolver follows `sync.actor_resolver` when that service implements the twin, so a host that
     * configured a stricter resolver autowires its own and never the laxer default. A configured service whose class is
     * not known before compilation leaves the alias on the default.
     */
    private function pointActorProviderAlias(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ACTOR_RESOLVER_PARAMETER) || !$container->hasAlias(SyncActorProviderInterface::class)) {
            return;
        }
        $configured = $container->getParameter(self::ACTOR_RESOLVER_PARAMETER);
        if (!is_string($configured) || $configured === '' || (!$container->hasAlias($configured) && !$container->hasDefinition($configured))) {
            return;
        }
        $target = $this->resolve($container, $configured);
        $class  = $this->classOf($container, $target);
        if ($class === null || !is_a($class, SyncActorProviderInterface::class, true)) {
            return;
        }
        $container->setAlias(SyncActorProviderInterface::class, $target)->setPublic(true);
    }

    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * @return class-string|null null when the class cannot be known before the container is compiled
     */
    private function classOf(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        // Since 3.0.0: a class that cannot be loaded (it implements a removed interface) is not loaded here.
        return is_string($class) ? $container->getReflectionClass($class, false)?->getName() : null;
    }
}
