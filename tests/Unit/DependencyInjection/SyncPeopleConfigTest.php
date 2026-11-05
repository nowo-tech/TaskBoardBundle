<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Sync\Hook\DefaultSyncPersonResolver;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncCommentLegacyAuthorMigrator;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleProjector;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\SyncTaskWriter;
use Nowo\TaskBoardBundle\Sync\TaskSyncSourceScanner;
use Nowo\TaskBoardBundle\Sync\Time\TimeEntryPhase;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.25: `sync.people`, `sync.watchers`, `sync.checklists`, `sync.time_entries`, `sync.list_as_tag` and
 * `sync.lookup` — defaults (verified e-mails only, no sink, no cache) and the host's services when configured.
 */
final class SyncPeopleConfigTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $sync = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]])['sync'];

        self::assertSame(['resolver' => null, 'require_verified_email' => true, 'reassign_batch_size' => 500, 'scan' => ['max_task_pages' => 1, 'max_comment_tasks' => 10, 'time_window' => true]], $sync['people']);
        self::assertSame(['enabled' => true, 'max_detail_calls_per_run' => 500], $sync['watchers']);
        self::assertSame(['enabled' => true, 'max_items_per_run' => 2000], $sync['checklists']);
        self::assertSame(['sink' => null, 'window_days' => 30, 'max_assignees_per_call' => 25, 'max_entries_per_window' => 10_000, 'max_windows_per_run' => 200, 'fallback_max_tasks_per_run' => 500, 'max_calls_per_run' => 500], $sync['time_entries']);
        self::assertFalse($sync['list_as_tag']);
        self::assertSame(['cache_pool' => null], $sync['lookup']);
    }

    public function testTheBoundsAreChecked(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'sync' => ['time_entries' => ['window_days' => 31]]]]);
    }

    public function testWithoutHostServicesTheDefaultsAreWired(): void
    {
        $container = $this->load([]);

        self::assertSame(DefaultSyncPersonResolver::class, (string) $container->getAlias(SyncPersonResolverInterface::class));
        self::assertTrue($container->getDefinition(DefaultSyncPersonResolver::class)->getArgument('$requireVerifiedEmail'));
        self::assertNull($container->getDefinition(TimeEntryPhase::class)->getArgument('$sink'));
        self::assertNull($container->getDefinition(ClickUpTaskProvider::class)->getArgument('$lookupCache'));
        self::assertSame(500, $container->getDefinition(TaskSyncPeopleReassigner::class)->getArgument('$defaultBatchSize'));
        $writer = $container->getDefinition(SyncTaskWriter::class);
        self::assertFalse($writer->getArgument('$listAsTag'));
        self::assertTrue($writer->getArgument('$checklistsEnabled'));
        self::assertTrue($writer->getArgument('$watchersEnabled'));
        self::assertSame(500, $writer->getArgument('$maxDetailCalls'));
        $views = $container->getDefinition(TaskBoardTwigExtension::class)->getArgument('$syncPeople');
        self::assertInstanceOf(Reference::class, $views);
        self::assertSame(SyncPeopleViewProvider::class, (string) $views);
        foreach ([TaskSyncPersonRepository::class, TaskSyncPeopleDirectoryService::class, TaskSyncPeopleReassigner::class, TaskSyncPeopleProjector::class, TaskSyncPeopleScanner::class, TaskSyncSourceScanner::class, TaskSyncCommentLegacyAuthorMigrator::class] as $public) {
            self::assertTrue($container->getDefinition($public)->isPublic(), $public);
        }
    }

    public function testTheHostServicesAndLimitsAreWired(): void
    {
        $container = $this->load([
            'list_as_tag'  => true,
            'lookup'       => ['cache_pool' => 'cache.app'],
            'people'       => ['resolver' => 'app.people', 'require_verified_email' => false, 'reassign_batch_size' => 100, 'scan' => ['max_task_pages' => 2, 'max_comment_tasks' => 0, 'time_window' => false]],
            'watchers'     => ['enabled' => false, 'max_detail_calls_per_run' => 0],
            'checklists'   => ['enabled' => false],
            'time_entries' => ['sink' => 'app.time_sink', 'window_days' => 7, 'max_assignees_per_call' => 10, 'max_entries_per_window' => 100, 'max_windows_per_run' => 3, 'fallback_max_tasks_per_run' => 9],
        ]);

        self::assertSame('app.people', (string) $container->getAlias(SyncPersonResolverInterface::class));
        self::assertFalse($container->getDefinition(DefaultSyncPersonResolver::class)->getArgument('$requireVerifiedEmail'));
        self::assertSame(100, $container->getDefinition(TaskSyncPeopleReassigner::class)->getArgument('$defaultBatchSize'));
        $scanner = $container->getDefinition(TaskSyncPeopleScanner::class);
        self::assertSame([2, 0, false, 10], [$scanner->getArgument('$maxTaskPages'), $scanner->getArgument('$maxCommentTasks'), $scanner->getArgument('$timeWindow'), $scanner->getArgument('$maxAssigneesPerCall')]);
        $phase = $container->getDefinition(TimeEntryPhase::class);
        $sink  = $phase->getArgument('$sink');
        self::assertInstanceOf(Reference::class, $sink);
        self::assertSame('app.time_sink', (string) $sink);
        self::assertSame([7, 10, 100, 3, 9], [$phase->getArgument('$windowDays'), $phase->getArgument('$maxAssigneesPerCall'), $phase->getArgument('$maxEntriesPerWindow'), $phase->getArgument('$maxWindowsPerRun'), $phase->getArgument('$fallbackMaxTasks')]);
        $cache = $container->getDefinition(ClickUpTaskProvider::class)->getArgument('$lookupCache');
        self::assertInstanceOf(Reference::class, $cache);
        self::assertSame('cache.app', (string) $cache);
        $writer = $container->getDefinition(SyncTaskWriter::class);
        self::assertSame([true, false, false, 0], [$writer->getArgument('$listAsTag'), $writer->getArgument('$checklistsEnabled'), $writer->getArgument('$watchersEnabled'), $writer->getArgument('$maxDetailCalls')]);
    }

    public function testNothingOfItWithoutSync(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        self::assertFalse($container->hasDefinition(TaskSyncPeopleDirectoryService::class));
        self::assertFalse($container->hasAlias(SyncPersonResolverInterface::class));
        self::assertArrayNotHasKey('$syncPeople', $container->getDefinition(TaskBoardTwigExtension::class)->getArguments());
    }

    /**
     * @param array<string, mixed> $sync
     */
    private function load(array $sync): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'sync' => ['enabled' => true, 'credential_resolver' => 'app.credentials'] + $sync]], $container);

        return $container;
    }
}
