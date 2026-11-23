<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Import\BoardScopedTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntryReceiverInterface;
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
use Throwable;

use function array_column;
use function class_exists;
use function interface_exists;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * Since 3.0.0 (REQ-ARCH-001 rule 3, audit lesson XR-WF-M1): fails the container build on every service that still uses
 * one of the entity-typed host interfaces deprecated in 2.15.0 and removed in 3.0, instead of letting Symfony drop it
 * silently. A host decorator that restricted the assignees, the boards a task may move to, the sync quotas or the Task
 * API authentication, or a host port, that disappeared would leave TaskBoard on its default (often wider access) while
 * the build succeeds.
 *
 * It refuses:
 * - a service or alias under a removed id (`Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface: '@App\Limits'`,
 *   `#[AsAlias]` of it);
 * - a decorator of a removed id (`#[AsDecorator]`, `decorates:`), `on_invalid: ignore` included;
 * - a definition whose class cannot be loaded because it implements a removed interface: Symfony records
 *   `Class "<removed interface>" not found while loading "<class>"` on an autodiscovered class and would otherwise remove
 *   it unused. Only the exact name of a removed interface matches; an unrelated missing class keeps Symfony's own lazy
 *   error;
 * - the service behind a port's configuration key whose class cannot be loaded for the same reason.
 *
 * Runs before optimization at a high priority (1000), while the loader errors are still on the definitions and before
 * any pass removes a definition, and again at priority 99, after the passes of other bundles that may add decorators.
 *
 * @internal
 */
final class RemovedPortsPass implements CompilerPassInterface
{
    /**
     * Removed interface => the interface to implement instead (its 2.15 framework-free twin).
     *
     * @var array<string, class-string>
     */
    public const REMOVED = [
        'Nowo\\TaskBoardBundle\\Assignee\\TaskAssigneeProviderInterface'           => TaskAssigneePolicyInterface::class,
        'Nowo\\TaskBoardBundle\\Comment\\TaskMentionCandidatesResolverInterface'   => TaskMentionCandidatesProviderInterface::class,
        'Nowo\\TaskBoardBundle\\Comment\\TaskCommentCauseLabelerInterface'         => TaskCommentCauseTextInterface::class,
        'Nowo\\TaskBoardBundle\\History\\TaskChangeCauseLabelerInterface'          => TaskChangeCauseTextInterface::class,
        'Nowo\\TaskBoardBundle\\Import\\TaskImportLinkLookupInterface'             => TaskImportLinkFinderInterface::class,
        'Nowo\\TaskBoardBundle\\Move\\TaskBoardTargetsInterface'                   => TaskBoardTargetIdsInterface::class,
        'Nowo\\TaskBoardBundle\\View\\TaskBoardDefaultViewResolverInterface'       => TaskBoardDefaultViewPolicyInterface::class,
        'Nowo\\TaskBoardBundle\\Tenancy\\FirstBoardProvisionerInterface'           => FirstBoardProvisioningInterface::class,
        'Nowo\\TaskBoardBundle\\Api\\Hook\\TaskApiAuthenticatorInterface'          => TaskApiRequestAuthenticatorInterface::class,
        'Nowo\\TaskBoardBundle\\Api\\Hook\\TaskApiAttachmentsInterface'            => TaskApiAttachmentStoreInterface::class,
        'Nowo\\TaskBoardBundle\\Api\\Hook\\TaskApiTimeEntriesInterface'            => TaskApiTimeStoreInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\ProviderCredentialResolverInterface'   => ProviderCredentialSourceInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\SyncActorResolverInterface'            => SyncActorProviderInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\SyncLimitCheckerInterface'             => SyncQuotaPolicyInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\InlineImageRewriterInterface'          => SyncedImageRewriterInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\TaskAttachmentStorageInterface'        => SyncedFileStorageInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\SyncPersonResolverInterface'           => SyncPersonDirectoryInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\PeopleInviterInterface'                => PeopleInvitationInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\ImportedTimeEntrySinkInterface'        => ImportedTimeEntryReceiverInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\OwnerlessTimeEntrySinkInterface'       => OwnerlessTimeEntryReceiverInterface::class,
        'Nowo\\TaskBoardBundle\\Sync\\Hook\\AttributionOnlyResolverInterface'      => AttributionOnlyPolicyInterface::class,
        'Nowo\\TaskBoardBundle\\Import\\BoardAwareTaskImportUserResolverInterface' => BoardScopedTaskImportUserResolverInterface::class,
    ];

    /**
     * The build error for a service on a removed port interface.
     *
     * @param string $service The service id (or class)
     * @param string $removed The removed interface
     */
    public static function message(string $service, string $removed): string
    {
        return sprintf('nowo_task_board: the service "%1$s" uses %2$s, which was deprecated in 2.15.0 and removed in 3.0. Implement %3$s instead and keep the configuration key that names your service (a decorator decorates your own service, not the removed id); see UPGRADING.md "To 3.0.0", "Host port interfaces".', $service, $removed, self::REMOVED[$removed]);
    }

    /**
     * The removed interface an error message names exactly (quoted, as Symfony and PHP quote class names), or null.
     */
    public static function named(string $error): ?string
    {
        foreach (self::REMOVED as $removed => $twin) {
            if (str_contains($error, '"' . $removed . '"')) {
                return $removed;
            }
        }

        return null;
    }

    /**
     * @throws LogicException When a service still uses a removed port interface
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED as $removed => $twin) {
            if ($container->hasAlias($removed) || $container->hasDefinition($removed)) {
                throw new LogicException(self::message($container->hasAlias($removed) ? (string) $container->getAlias($removed) : $removed, $removed));
            }
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            $decorated = $definition->getDecoratedService();
            if ($decorated !== null && isset(self::REMOVED[$decorated[0]])) {
                throw new LogicException(self::message($id, $decorated[0]));
            }
            foreach ($definition->getErrors() as $error) {
                if (($removed = self::named($error)) !== null) {
                    throw new LogicException(self::message($id, $removed));
                }
            }
        }
        foreach ([...array_column(TaskBoardPortsPass::PORTS, 'id'), TaskImportUserResolverInterface::class] as $portId) {
            $class = $this->classBehind($container, $portId);
            if ($class !== null && ($error = $this->loadError($class)) !== null && ($named = self::named($error)) !== null) {
                throw new LogicException(self::message($class, $named));
            }
        }
    }

    /**
     * Why the class cannot be loaded, or null when it can (or is unknown to the autoloader).
     */
    private function loadError(string $class): ?string
    {
        try {
            if (!class_exists($class)) {
                interface_exists($class);
            }
        } catch (Throwable $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    /**
     * The class of the service a port's id points at, when the definition names one.
     */
    private function classBehind(ContainerBuilder $container, string $id): ?string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);

        return is_string($class) ? $class : null;
    }
}
