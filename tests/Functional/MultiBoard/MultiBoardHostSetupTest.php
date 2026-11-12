<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use Nowo\TaskBoardBundle\Controller\TaskBoardMultiBoardController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Form\MultiBoardTaskFilterType;
use Nowo\TaskBoardBundle\MultiBoard\DoctrineMultiBoardTaskQuery;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskList;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskListInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskPage;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkload;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkloadRow;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedMultiBoardScope;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Since 2.13.0: a host's setup of the multi-board list: off by default, no provider, a path with its own variable,
 * tenancy links, the board cap and the label scan cap.
 */
#[CoversClass(TaskBoardMultiBoardController::class)]
#[CoversClass(MultiBoardTaskList::class)]
#[CoversClass(DoctrineMultiBoardTaskQuery::class)]
#[CoversClass(MultiBoardTaskFilterType::class)]
#[CoversClass(MultiBoardBoard::class)]
#[CoversClass(MultiBoardPerson::class)]
#[CoversClass(MultiBoardTaskPage::class)]
#[CoversClass(MultiBoardWorkload::class)]
#[CoversClass(MultiBoardWorkloadRow::class)]
#[CoversClass(MultiBoardExportTable::class)]
#[CoversClass(XlsxStreamWriter::class)]
#[CoversClass(TaskBoardExtension::class)]
#[CoversClass(TaskBoardRouteLoader::class)]
final class MultiBoardHostSetupTest extends FunctionalTestCase
{
    public function testOffByDefault(): void
    {
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/tasks');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/tools/task-board/tasks/export.csv');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::getContainer()->has(MultiBoardTaskListInterface::class));
        self::assertFalse(self::getContainer()->has(TaskBoardMultiBoardController::class));
        self::assertNull(self::getContainer()->get('router')->getRouteCollection()->get('nowo_task_board_tasks'));
    }

    public function testWithoutAProviderNobodyReadsAnyBoard(): void
    {
        self::$taskBoardConfig = ['multi_board' => ['enabled' => true]];
        self::ensureKernelShutdown();
        $this->setUp();
        $admin = $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->task($this->board('Everyone', creator: $admin), 'Secret');
        $this->login($admin);

        $this->client->request('GET', '/tools/task-board/tasks');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No boards to show.');
        self::assertSelectorTextNotContains('body', 'Secret');
        $this->client->request('GET', '/tools/task-board/tasks/export.xlsx');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAHostPathWithItsOwnVariableTenantLinksAndCaps(): void
    {
        self::$taskBoardConfig = [
            'multi_board'  => ['enabled' => true, 'scope_provider' => 'app.multi_scope', 'path' => '/orgs/{organisation}/tasks/', 'max_boards' => 4, 'scan_max_rows' => 100, 'per_page' => 10],
            'tenancy'      => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
            'route_prefix' => '/app',
        ];
        self::ensureKernelShutdown();
        $this->setUp();
        $one      = $this->board('One', tenantKey: 'p1');
        $two      = $this->board('Two', tenantKey: 'p2');
        $three    = $this->board('Three', tenantKey: 'p3');
        $noTenant = $this->board('No tenant');
        $this->task($noTenant, 'In no tenant');
        $wrongSlug = $this->board('Wrong slug', tenantKey: 'p4');
        $this->task($wrongSlug, 'In wrong slug');
        $this->task($one, 'In one');
        $this->task($two, 'In two');
        $this->task($three, 'In three');
        $scope = self::getContainer()->get('app.multi_scope');
        self::assertInstanceOf(ScriptedMultiBoardScope::class, $scope);
        $scope->entries[(string) $this->owner->getId()] = [new MultiBoardScopeEntry($one->getId(), 'Project One', 'p1'), new MultiBoardScopeEntry($two->getId(), null, 'p2'), new MultiBoardScopeEntry($three->getId(), 'Project Three', 'p3')];
        // Audit NI-3: under tenancy, a board without a tenant, or listed with another tenant's slug, is dropped.
        array_unshift($scope->entries[(string) $this->owner->getId()], new MultiBoardScopeEntry($noTenant->getId()), new MultiBoardScopeEntry($wrongSlug->getId(), null, 'p1'));
        $this->login($this->owner);

        $this->client->request('GET', '/app/orgs/acme/tasks');

        self::assertResponseIsSuccessful();
        self::assertSame(['organisation' => 'acme'], $scope->asked[0]->context);
        self::assertSelectorExists('a[href="/t/p1/task/' . $this->taskId('In one') . '"]');
        self::assertSelectorExists('a[href="/t/p2/board/' . $two->getId() . '/list"]');
        self::assertSelectorTextContains('body', 'Project One');
        self::assertSelectorTextNotContains('body', 'In no tenant');
        self::assertSelectorTextNotContains('body', 'In wrong slug');
        self::assertSelectorTextNotContains('[data-testid="nowo-task-multi-index"], table', 'In three');
        self::assertSelectorExists('a[data-testid="nowo-task-multi-export-csv"][href^="/app/orgs/acme/tasks/export.csv"]');
        $warnings = array_values(array_filter($this->logger()->records, static fn (array $record): bool => $record['message'] === 'TaskBoard multi-board scope capped'));
        self::assertSame(['bundle' => 'nowo_task_board', 'boards' => 5, 'max_boards' => 4], $warnings[0]['context'] ?? null);

        $this->client->request('GET', '/app/orgs/acme/tasks/export.csv');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('In two', $this->client->getInternalResponse()->getContent());
        $exports = array_values(array_filter($this->logger()->records, static fn (array $record): bool => $record['message'] === 'TaskBoard export: {format}'));
        self::assertSame(['bundle' => 'nowo_task_board', 'format' => 'csv', 'rows' => 2, 'truncated' => false, 'partial' => false], $exports[0]['context'] ?? null);
    }

    public function testTheLabelScanStopsAtItsCapAndSaysSo(): void
    {
        self::$taskBoardConfig = ['multi_board' => ['enabled' => true, 'scope_provider' => 'app.multi_scope', 'scan_max_rows' => 500]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board('Big');
        // One more than the scan cap, which is one full batch of 500.
        for ($i = 0; $i < 501; ++$i) {
            $task = $this->task($board, 'Task ' . $i, position: $i);
            $task->setTags(['bulk']);
        }
        $this->em->flush();
        $scope = self::getContainer()->get('app.multi_scope');
        self::assertInstanceOf(ScriptedMultiBoardScope::class, $scope);
        $scope->entries[(string) $this->owner->getId()] = [new MultiBoardScopeEntry($board->getId())];
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/tasks?label=bulk');
        self::assertSelectorExists('[data-testid="nowo-task-multi-partial"]');
        $this->client->request('GET', '/tools/task-board/tasks?label=bulk&view=people');
        self::assertSelectorExists('[data-testid="nowo-task-multi-partial"]');
        self::assertSelectorTextContains('[data-testid="nowo-task-multi-row"]', '500');
        $this->client->request('GET', '/tools/task-board/tasks/export.csv?label=bulk');
        self::assertResponseIsSuccessful();
        self::assertCount(501, explode("\n", trim((string) $this->client->getInternalResponse()->getContent())), 'the header and the 500 scanned matches');
        self::assertResponseHeaderSame('X-Task-Board-Export-Partial', '1');
        self::assertNull($this->client->getInternalResponse()->getHeader('X-Task-Board-Export-Truncated'));
        $this->client->request('GET', '/tools/task-board/tasks/export.csv');
        self::assertNull($this->client->getInternalResponse()->getHeader('X-Task-Board-Export-Partial'), 'no label filter: complete');
        $this->client->request('GET', '/tools/task-board/tasks?label=other');
        self::assertSelectorExists('[data-testid="nowo-task-multi-partial"]');
    }

    private function taskId(string $title): string
    {
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => $title]);
        self::assertNotNull($task);

        return $task->getId();
    }
}
