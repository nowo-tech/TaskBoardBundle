<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface;
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
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): wires the framework-free twins of the host ports and refuses a port service
 * that implements neither interface.
 *
 * Every host port the bundle asks is reached through one service id: the deprecated interface (an alias the extension
 * points at the configured service, or the bundle's default), or `nowo_task_board.port.<name>` for the ports the bundle
 * injects by reference. For each id that exists:
 *
 * - the service's class implements the deprecated interface: nothing changes (asked exactly as in 2.14, also when it
 *   implements the twin too);
 * - it implements only the 2.15 twin: the id becomes the twin's internal gate over it ({@see Ports});
 * - the class is not known before compilation (a factory service): the id becomes the {@see Ports} factory, which
 *   decides per object and fails closed (`LogicException`) when the object implements neither;
 * - it implements neither: the build fails, naming the configuration key.
 *
 * Deprecations of the old interfaces reach the application through their `@deprecated` tag (Symfony's DebugClassLoader,
 * in debug mode), not from this pass.
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
     * @var array<string, array{id: string, legacy: class-string, twin: class-string, factory: string, option: string, boards?: true}>
     */
    public const PORTS = [
        'assignee_provider'       => ['id' => TaskAssigneeProviderInterface::class, 'legacy' => TaskAssigneeProviderInterface::class, 'twin' => TaskAssigneePolicyInterface::class, 'factory' => 'assigneeProvider', 'option' => 'assignee_provider'],
        'mention_resolver'        => ['id' => TaskMentionCandidatesResolverInterface::class, 'legacy' => TaskMentionCandidatesResolverInterface::class, 'twin' => TaskMentionCandidatesProviderInterface::class, 'factory' => 'mentionCandidates', 'option' => 'comments.mention_resolver'],
        'comment_cause_labeler'   => ['id' => self::PORT_PREFIX . 'comment_cause_labeler', 'legacy' => TaskCommentCauseLabelerInterface::class, 'twin' => TaskCommentCauseTextInterface::class, 'factory' => 'commentCauseLabeler', 'option' => 'comments.cause_labeler'],
        'change_cause_labeler'    => ['id' => self::PORT_PREFIX . 'change_cause_labeler', 'legacy' => TaskChangeCauseLabelerInterface::class, 'twin' => TaskChangeCauseTextInterface::class, 'factory' => 'changeCauseLabeler', 'option' => 'history.cause_labeler'],
        'import_link_lookup'      => ['id' => TaskImportLinkLookupInterface::class, 'legacy' => TaskImportLinkLookupInterface::class, 'twin' => TaskImportLinkFinderInterface::class, 'factory' => 'importLinkLookup', 'option' => 'the TaskImportLinkLookupInterface alias'],
        'board_targets'           => ['id' => TaskBoardTargetsInterface::class, 'legacy' => TaskBoardTargetsInterface::class, 'twin' => TaskBoardTargetIdsInterface::class, 'factory' => 'boardTargets', 'option' => 'move.targets', 'boards' => true],
        'default_view_resolver'   => ['id' => TaskBoardDefaultViewResolverInterface::class, 'legacy' => TaskBoardDefaultViewResolverInterface::class, 'twin' => TaskBoardDefaultViewPolicyInterface::class, 'factory' => 'defaultViewResolver', 'option' => 'board.default_view_resolver'],
        'first_board_provisioner' => ['id' => self::PORT_PREFIX . 'first_board_provisioner', 'legacy' => FirstBoardProvisionerInterface::class, 'twin' => FirstBoardProvisioningInterface::class, 'factory' => 'firstBoardProvisioner', 'option' => 'tenancy.first_board_provisioner', 'boards' => true],
        'api_authenticator'       => ['id' => TaskApiAuthenticatorInterface::class, 'legacy' => TaskApiAuthenticatorInterface::class, 'twin' => TaskApiRequestAuthenticatorInterface::class, 'factory' => 'apiAuthenticator', 'option' => 'api.hooks.authenticator'],
        'api_attachments'         => ['id' => TaskApiAttachmentsInterface::class, 'legacy' => TaskApiAttachmentsInterface::class, 'twin' => TaskApiAttachmentStoreInterface::class, 'factory' => 'apiAttachments', 'option' => 'api.hooks.attachments'],
        'api_time_entries'        => ['id' => TaskApiTimeEntriesInterface::class, 'legacy' => TaskApiTimeEntriesInterface::class, 'twin' => TaskApiTimeStoreInterface::class, 'factory' => 'apiTimeEntries', 'option' => 'api.hooks.time_entries'],
        'credential_resolver'     => ['id' => ProviderCredentialResolverInterface::class, 'legacy' => ProviderCredentialResolverInterface::class, 'twin' => ProviderCredentialSourceInterface::class, 'factory' => 'credentialResolver', 'option' => 'sync.credential_resolver'],
        'actor_resolver'          => ['id' => SyncActorResolverInterface::class, 'legacy' => SyncActorResolverInterface::class, 'twin' => SyncActorProviderInterface::class, 'factory' => 'actorResolver', 'option' => 'sync.actor_resolver'],
        'limit_checker'           => ['id' => SyncLimitCheckerInterface::class, 'legacy' => SyncLimitCheckerInterface::class, 'twin' => SyncQuotaPolicyInterface::class, 'factory' => 'limitChecker', 'option' => 'sync.limit_checker'],
        'inline_image_rewriter'   => ['id' => InlineImageRewriterInterface::class, 'legacy' => InlineImageRewriterInterface::class, 'twin' => SyncedImageRewriterInterface::class, 'factory' => 'inlineImageRewriter', 'option' => 'sync.inline_image_rewriter'],
        'attachment_storage'      => ['id' => TaskAttachmentStorageInterface::class, 'legacy' => TaskAttachmentStorageInterface::class, 'twin' => SyncedFileStorageInterface::class, 'factory' => 'attachmentStorage', 'option' => 'sync.attachment_storage'],
        'person_resolver'         => ['id' => SyncPersonResolverInterface::class, 'legacy' => SyncPersonResolverInterface::class, 'twin' => SyncPersonDirectoryInterface::class, 'factory' => 'personResolver', 'option' => 'sync.people.resolver'],
        'people_inviter'          => ['id' => self::PORT_PREFIX . 'people_inviter', 'legacy' => PeopleInviterInterface::class, 'twin' => PeopleInvitationInterface::class, 'factory' => 'peopleInviter', 'option' => 'sync.people.inviter'],
        'time_entry_sink'         => ['id' => self::PORT_PREFIX . 'time_entry_sink', 'legacy' => ImportedTimeEntrySinkInterface::class, 'twin' => ImportedTimeEntryReceiverInterface::class, 'factory' => 'timeEntrySink', 'option' => 'sync.time_entries.sink'],
    ];

    /**
     * @throws LogicException when a port service implements neither interface
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::PORTS as $port) {
            $id = $port['id'];
            if (!$container->hasAlias($id) && !$container->hasDefinition($id)) {
                continue;
            }

            $target = $this->resolve($container, $id);
            $class  = $this->classOf($container, $target);
            if ($class !== null && is_a($class, $port['legacy'], true)) {
                continue; // Asked through the deprecated interface, exactly as in 2.14.
            }
            if ($class !== null && !is_a($class, $port['twin'], true)) {
                throw new LogicException(sprintf('nowo_task_board: the service "%s" (%s) of %s must implement "%s" (or the deprecated "%s").', $target, $class, $port['option'], $port['twin'], $port['legacy']));
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
            $container->setDefinition($id, (new Definition($port['legacy']))
                ->setFactory([Ports::class, $port['factory']])
                ->setArguments($arguments)
                ->setPublic($public));
        }

        $this->pointActorProviderAlias($container);
    }

    /**
     * Since 2.15.1 (audit OM TB-I1): the public {@see SyncActorProviderInterface} alias the extension points at the
     * bundle's default resolver follows `sync.actor_resolver` when that service implements the twin, so a host that
     * configured a stricter resolver autowires its own and never the laxer default. A configured service that
     * implements only the deprecated interface (or whose class is not known before compilation) leaves the alias on the
     * default: the bundle asks it through {@see SyncActorResolverInterface} anyway.
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

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
