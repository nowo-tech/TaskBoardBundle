<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search;

use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Search\DoctrineTaskTextSearch;
use Nowo\TaskBoardBundle\Search\TaskTextSearchInterface;
use Nowo\TaskBoardBundle\Search\TaskTextSearchMetadataListener;
use Nowo\TaskBoardBundle\Transition\AllowAllColumnTransitionPolicy;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Spec 234 (TaskBoard 2.16.0): `search.*` and `column_transition_policy` in the container.
 */
final class TextSearchExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);
        self::assertSame(DoctrineTaskTextSearch::class, (string) $container->getAlias(TaskTextSearchInterface::class));
        self::assertSame(AllowAllColumnTransitionPolicy::class, (string) $container->getAlias(ColumnTransitionPolicyInterface::class));
        self::assertFalse($container->getDefinition(TaskTextSearchMetadataListener::class)->hasTag('doctrine.event_listener'));
        self::assertSame(500, $container->getParameter('nowo_task_board.search.max_matches'));
        self::assertFalse($container->getParameter('nowo_task_board.board.column_icon_form')['humans_only_field']);
        self::assertFalse($container->getDefinition(HumansOnlyColumnTransitionPolicy::class)->getArgument('$allowChecklist'));
        self::assertTrue($this->load(['humans_only_policy' => ['allow_checklist' => true]])->getDefinition(HumansOnlyColumnTransitionPolicy::class)->getArgument('$allowChecklist'));
    }

    public function testFulltextMapsTheIndexesAndAHostPolicyShowsTheToggle(): void
    {
        $container = $this->load(['search' => ['fulltext' => true, 'comments' => true, 'provider' => 'app.search', 'min_token_length' => 4], 'column_transition_policy' => 'app.policy']);
        $listener  = $container->getDefinition(TaskTextSearchMetadataListener::class);
        self::assertTrue($listener->hasTag('doctrine.event_listener'));
        self::assertTrue($listener->getArgument('$comments'));
        self::assertSame('app.search', (string) $container->getAlias(TaskTextSearchInterface::class));
        self::assertSame(4, $container->getDefinition(DoctrineTaskTextSearch::class)->getArgument('$minTokenLength'));
        self::assertSame('app.policy', (string) $container->getAlias(ColumnTransitionPolicyInterface::class));
        self::assertTrue($container->getParameter('nowo_task_board.board.column_icon_form')['humans_only_field']);
        self::assertFalse($this->load(['column_transition_policy' => 'app.policy', 'board' => ['column_humans_only_field' => false]])->getParameter('nowo_task_board.board.column_icon_form')['humans_only_field']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.bundles', ['SecurityBundle' => stdClass::class]);
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        (new TaskBoardExtension())->load([['user_class' => stdClass::class] + $config], $container);

        return $container;
    }
}
