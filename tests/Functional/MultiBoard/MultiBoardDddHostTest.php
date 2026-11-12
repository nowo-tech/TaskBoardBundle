<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use Nowo\TaskBoardBundle\Controller\TaskBoardMultiBoardController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Form\MultiBoardTaskFilterType;
use Nowo\TaskBoardBundle\MultiBoard\DoctrineMultiBoardTaskQuery;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskFilter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskList;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskListInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskPage;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskRow;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkload;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkloadRow;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Tests\Functional\App\DomainProjectBoardScope;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function iterator_to_array;

/**
 * Since 2.13.0 (REQ-ARCH-001): a DDD / hexagonal host. Its adapter (`DomainProjectBoardScope`) knows projects and
 * members by plain ids and implements the port with the bundle's value objects only; its application layer calls
 * {@see MultiBoardTaskListInterface} with a viewer built from an id, outside any HTTP request and without a security
 * token, and gets plain read models back (no entity).
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
final class MultiBoardDddHostTest extends MultiBoardTestCase
{
    protected const CONFIG = ['multi_board' => ['enabled' => true, 'scope_provider' => 'app.domain_scope']];

    public function testTheHostReadsTheTasksOfItsProjectsByIds(): void
    {
        $alice = $this->user('alice@example.com');
        $bob   = $this->user('bob@example.com');
        $web   = $this->board('Website', creator: $alice);
        $app   = $this->board('App', creator: $alice);
        $hr    = $this->board('HR', creator: $bob);
        $this->make($web, 'Homepage', ['assignees' => [$alice], 'priority' => TaskPriority::High, 'due' => '2020-01-01', 'tags' => ['web']]);
        $this->make($app, 'Login', ['assignees' => [$bob]]);
        $this->make($hr, 'Payroll');
        $domain = self::getContainer()->get('app.domain_scope');
        self::assertInstanceOf(DomainProjectBoardScope::class, $domain);
        $domain->projects = [
            'p-web' => ['name' => 'Web project', 'boardId' => $web->getId(), 'members' => [(string) $alice->getId(), '999999', 'not-a-number'], 'organisation' => 'acme'],
            'p-app' => ['name' => 'App project', 'boardId' => $app->getId(), 'members' => [(string) $alice->getId()], 'organisation' => 'other'],
            // Listed for alice by a buggy adapter: the bundle's own check (bob's board, no team) still refuses it.
            'p-hr' => ['name' => 'HR project', 'boardId' => $hr->getId(), 'members' => [(string) $alice->getId()], 'organisation' => 'acme'],
        ];
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        self::assertNull($tokens->getToken(), 'no HTTP request, no token');
        $tasks = self::getContainer()->get(MultiBoardTaskListInterface::class);
        self::assertInstanceOf(MultiBoardTaskListInterface::class, $tasks);
        $viewer = new MultiBoardViewer((string) $alice->getId(), ['organisation' => 'acme']);

        $boards = $tasks->boards($viewer);
        self::assertSame(['Web project'], array_map(static fn (MultiBoardBoard $board): string => $board->label, $boards));
        self::assertSame('p-web', $boards[0]->tenantSlug);

        $page = $tasks->page($viewer, new MultiBoardTaskFilter(), 1, 10);
        self::assertSame(1, $page->total);
        $row = $page->rows[0];
        self::assertInstanceOf(MultiBoardTaskRow::class, $row);
        self::assertSame(['Homepage', 'Web project', TaskPriority::High, ['web'], true], [$row->title, $row->board->label, $row->priority, $row->labels, $row->overdue]);
        self::assertSame([(string) $alice->getId()], array_map(static fn (MultiBoardPerson $person): string => $person->id, $row->assignees));

        $workload = $tasks->workload($viewer, new MultiBoardTaskFilter());
        self::assertSame([1, 1], [$workload->rows[0]->open, $workload->rows[0]->overdue]);

        $export = $tasks->export($viewer, new MultiBoardTaskFilter(), 10);
        self::assertNotNull($export);
        self::assertSame(['Homepage'], array_map(static fn (MultiBoardTaskRow $row): string => $row->title, iterator_to_array($export->rows(), false)));

        // The organisation from the context: the other project only.
        self::assertSame(['App project'], array_map(static fn (MultiBoardBoard $board): string => $board->label, $tasks->boards(new MultiBoardViewer((string) $alice->getId(), ['organisation' => 'other']))));
        // Unknown people read nothing, even when the adapter lists boards for them: an id of nobody, an id that cannot be
        // an integer id, and a non-member.
        self::assertSame([], $tasks->boards(new MultiBoardViewer('999999')));
        self::assertSame([], $tasks->boards(new MultiBoardViewer('not-a-number')));
        self::assertSame([], $tasks->boards(new MultiBoardViewer((string) $bob->getId())));
        self::assertNull($tasks->export(new MultiBoardViewer('999999'), new MultiBoardTaskFilter()));
        self::assertSame([], $tasks->workload(new MultiBoardViewer('999999'), new MultiBoardTaskFilter())->rows);
        self::assertTrue($tasks->page(new MultiBoardViewer('999999'), new MultiBoardTaskFilter(), 1, 10)->isEmptyScope());
    }
}
