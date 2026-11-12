<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Controller\TaskBoardMultiBoardController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Form\MultiBoardTaskFilterType;
use Nowo\TaskBoardBundle\MultiBoard\DoctrineMultiBoardTaskQuery;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskList;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskPage;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkload;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkloadRow;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_slice;
use function sprintf;

/**
 * Since 2.13.0: the list of the tasks of several boards: the Board / Project column, every filter, the sort, the
 * server-side pagination and the workload per person.
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
final class MultiBoardListTest extends MultiBoardTestCase
{
    private AppUser $alice;

    private AppUser $bob;

    private TaskBoard $web;

    private TaskBoard $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->user('alice@example.com');
        $this->alice->setDisplayName('Alice Doe');
        $this->bob = $this->user('bob@example.com');
        $this->bob->setDisplayName('Bob Roe');
        $this->web = $this->board('Website', ['To do', 'Doing', 'Done'], $this->alice);
        $this->app = $this->board('Mobile app', ['Backlog', 'Doing', 'Done'], $this->alice);
        $this->em->flush();
        $this->allow($this->alice, new MultiBoardScopeEntry($this->web->getId(), 'Project Web'), $this->app);
        $this->login($this->alice);
    }

    public function testTheListShowsTheTasksOfEveryBoardWithTheBoardColumn(): void
    {
        $this->make($this->web, 'Homepage', ['assignees' => [$this->alice], 'due' => '2030-01-02', 'priority' => TaskPriority::High, 'tags' => ['design']]);
        $this->make($this->app, 'Login screen', ['assignees' => [$this->alice, $this->bob], 'due' => '2030-01-01', 'column' => 1]);
        $this->make($this->app, 'Done thing', ['completed' => true]);

        $this->get();

        self::assertResponseIsSuccessful();
        self::assertSame(['Login screen', 'Homepage'], $this->titles());
        $boards = $this->client->getCrawler()->filter('[data-testid="nowo-task-multi-board"]')->each(static fn ($node): string => $node->text());
        self::assertSame(['Mobile app', 'Project Web'], $boards);
        self::assertStringContainsString('Doing', $this->client->getCrawler()->filter('[data-testid="nowo-task-multi-row"]')->first()->text());
        self::assertSelectorTextContains('[data-testid="nowo-task-multi-row"]', 'Doing');
        self::assertSelectorExists('a[href="/tools/task-board/board/' . $this->web->getId() . '/list"]');
        self::assertSelectorExists('[data-testid="nowo-task-multi-export-csv"]');
        self::assertSelectorExists('[data-testid="nowo-task-multi-export-xlsx"]');
        self::assertSelectorTextContains('.nowo-task-tag', 'design');
        self::assertSame(['Alice Doe', 'Bob Roe'], array_slice($this->client->getCrawler()->filter('select[name="assignee"] option')->each(static fn ($node): string => $node->text()), 2));
        self::assertSame(['Any column', 'Backlog', 'Doing', 'Done', 'To do'], $this->client->getCrawler()->filter('select[name="column"] option')->each(static fn ($node): string => $node->text()));
    }

    public function testEveryFilter(): void
    {
        $this->make($this->web, 'Late urgent', ['assignees' => [$this->bob], 'due' => '2020-05-01', 'priority' => TaskPriority::Urgent, 'tags' => ['Bug']]);
        $this->make($this->web, 'Future low', ['due' => '2031-06-15', 'priority' => TaskPriority::Low, 'column' => 1]);
        $this->make($this->app, 'Nobody owns 50% of it', ['assignees' => []]);
        $finished = $this->make($this->app, 'Finished', ['completed' => true, 'assignees' => [$this->alice], 'due' => '2020-01-01']);

        $cases = [
            'board=' . $this->web->getId()                 => ['Late urgent', 'Future low'],
            'assignee=' . $this->bob->getId()              => ['Late urgent'],
            'assignee=none'                                => ['Future low', 'Nobody owns 50% of it'],
            'column=doing'                                 => ['Future low'],
            'status=done'                                  => ['Finished'],
            'status=all&assignee=' . $this->alice->getId() => ['Finished'],
            'priority=urgent'                              => ['Late urgent'],
            'priority=nonsense'                            => ['Late urgent', 'Future low', 'Nobody owns 50% of it'],
            'due_from=2031-06-15&due_to=2031-06-15'        => ['Future low'],
            'due_to=2020-12-31&status=all'                 => ['Finished', 'Late urgent'],
            'due_from=2031-02-30'                          => ['Late urgent', 'Future low', 'Nobody owns 50% of it'],
            'overdue=1'                                    => ['Late urgent'],
            'label=bug'                                    => ['Late urgent'],
            'label=none-such'                              => [],
            'q=URGENT'                                     => ['Late urgent'],
            'q=50%25'                                      => ['Nobody owns 50% of it'],
            'q=%25'                                        => ['Nobody owns 50% of it'],
            // Numbers are per board here (no tenant key): #2 of each board.
            'q=%23' . $finished->getNumber() . '&status=all' => ['Finished', 'Future low'],
        ];
        foreach ($cases as $query => $titles) {
            $this->get($query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($titles, $this->titles(), $query);
        }

        $this->get('label=none-such');
        self::assertSelectorTextContains('body', 'Change or clear the filters.');
        $this->get('board=' . $this->web->getId());
        self::assertSelectorExists('select[name="board"] option[selected][value="' . $this->web->getId() . '"]');
    }

    public function testTheSort(): void
    {
        $this->make($this->web, 'Bravo', ['due' => '2030-01-03', 'priority' => TaskPriority::Low]);
        $this->make($this->app, 'Alpha', ['priority' => TaskPriority::Urgent]);
        $this->make($this->web, 'Charlie', ['due' => '2030-01-01', 'priority' => TaskPriority::High, 'column' => 2]);

        $cases = [
            ''                       => ['Charlie', 'Bravo', 'Alpha'],
            'sort=due&dir=desc'      => ['Bravo', 'Charlie', 'Alpha'],
            'sort=title'             => ['Alpha', 'Bravo', 'Charlie'],
            'sort=title&dir=desc'    => ['Charlie', 'Bravo', 'Alpha'],
            'sort=priority'          => ['Alpha', 'Charlie', 'Bravo'],
            'sort=priority&dir=asc'  => ['Bravo', 'Charlie', 'Alpha'],
            'sort=column&dir=desc'   => ['Bravo', 'Charlie', 'Alpha'],
            'sort=nonsense&dir=desc' => ['Bravo', 'Charlie', 'Alpha'],
        ];
        foreach ($cases as $query => $titles) {
            $this->get($query);
            self::assertSame($titles, $this->titles(), $query);
        }
        // Ties (one board, one number per board, one second) are broken by id: only the distinct part is fixed.
        $this->get('sort=board');
        self::assertSame('Alpha', $this->titles()[0], 'by board name');
        $this->get('sort=number&dir=desc');
        self::assertSame('Charlie', $this->titles()[0]);
        foreach (['sort=created', 'sort=updated&dir=asc'] as $query) {
            $this->get($query);
            self::assertCount(3, $this->titles(), $query);
        }
    }

    public function testServerSidePagination(): void
    {
        for ($i = 1; $i <= 23; ++$i) {
            $this->make($i % 2 === 0 ? $this->web : $this->app, sprintf('Task %02d', $i), ['due' => sprintf('2030-01-%02d', $i), 'tags' => $i % 3 === 0 ? ['third'] : []]);
        }
        $this->make($this->web, 'Task 99', ['tags' => ['third'], 'due' => '2030-02-01']);
        for ($i = 0; $i < 4; ++$i) {
            $this->make($this->web, 'Extra ' . $i, ['tags' => ['Third'], 'due' => '2030-03-0' . ($i + 1)]);
        }
        $last = ['Task 21', 'Task 22', 'Task 23', 'Task 99', 'Extra 0', 'Extra 1', 'Extra 2', 'Extra 3'];

        $this->get('per_page=10');
        self::assertCount(10, $this->titles());
        self::assertSame('Task 01', $this->titles()[0]);

        $this->get('per_page=10&page=3');
        self::assertSame($last, $this->titles());
        self::assertSelectorExists('a[href*="page=2"]');

        $this->get('per_page=10&page=99');
        self::assertSame($last, $this->titles(), 'a page past the end shows the last page');

        $this->get('per_page=7');
        self::assertCount(25, $this->titles(), 'a per-page value not offered falls back to multi_board.per_page (25)');

        // The label filter pages in PHP: the requested page, and the last one for a page past the end.
        $third = ['Task 03', 'Task 06', 'Task 09', 'Task 12', 'Task 15', 'Task 18', 'Task 21', 'Task 99', 'Extra 0', 'Extra 1'];
        $this->get('label=third&per_page=10');
        self::assertSame($third, $this->titles());
        $this->get('label=third&per_page=10&page=2');
        self::assertSame(['Extra 2', 'Extra 3'], $this->titles());
        $this->get('label=third&per_page=10&page=9');
        self::assertSame(['Extra 2', 'Extra 3'], $this->titles());
        $this->get('label=third&per_page=50&page=3');
        self::assertSame([...$third, 'Extra 2', 'Extra 3'], $this->titles());
        self::assertSelectorNotExists('[data-testid="nowo-task-multi-partial"]');
    }

    public function testTheWorkloadPerPerson(): void
    {
        $this->make($this->web, 'A1', ['assignees' => [$this->alice], 'estimate' => 30, 'logged' => 10, 'due' => '2020-01-01']);
        $this->make($this->web, 'A2', ['assignees' => [$this->alice, $this->bob], 'estimate' => 60, 'tags' => ['x']]);
        $this->make($this->app, 'A3', ['assignees' => [$this->alice], 'completed' => true, 'logged' => 120, 'estimate' => 90]);
        $this->make($this->app, 'Nobody', ['estimate' => 15]);

        foreach (['view=people', 'view=people&label=x', 'view=people&status=done'] as $query) {
            $this->get($query);
            self::assertResponseIsSuccessful();
            $rows = $this->client->getCrawler()->filter('[data-testid="nowo-task-multi-row"]')->each(static fn ($row): array => $row->filter('td')->each(static fn ($cell): string => trim($cell->text())));
            if ($query === 'view=people&label=x') {
                self::assertSame([['Alice Doe', '1', '0', '0', '1 h', '0 min'], ['Bob Roe', '1', '0', '0', '1 h', '0 min']], $rows, $query);
                continue;
            }
            self::assertSame([
                ['Alice Doe', '2', '1', '1', '1 h 30 min', '2 h 10 min'],
                ['Bob Roe', '1', '0', '0', '1 h', '0 min'],
                ['Unassigned', '1', '0', '0', '15 min', '0 min'],
            ], $rows, $query);
        }
        self::assertSelectorExists('[data-testid="nowo-task-multi-people"]');
        self::assertSelectorExists('input[name="view"][value="people"]');
    }

    public function testEveryValueIsEscaped(): void
    {
        $this->allow($this->alice, new MultiBoardScopeEntry($this->web->getId(), '<b>Project</b>'));
        $this->make($this->web, '<script>alert(1)</script>', ['tags' => ['<i>tag</i>']]);
        $this->alice->setDisplayName('<img src=x onerror=alert(2)>');
        $this->em->flush();
        $this->make($this->web, 'Assigned', ['assignees' => [$this->alice]]);

        $content = $this->get();

        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
        self::assertStringNotContainsString('<b>Project</b>', $content);
        self::assertStringNotContainsString('<i>tag</i>', $content);
        self::assertStringNotContainsString('<img src=x', $content);
    }

    public function testTwoPeopleWithOneNameStayApartInTheFilter(): void
    {
        $other = $this->user('alice2@example.com');
        $other->setDisplayName('Alice Doe');
        $this->make($this->web, 'Mine', ['assignees' => [$this->alice]]);
        $this->make($this->web, 'Theirs', ['assignees' => [$other]]);

        $this->get();

        $options = $this->client->getCrawler()->filter('select[name="assignee"] option')->each(static fn ($node): array => [$node->text(), (string) $node->attr('value')]);
        self::assertContains(['Alice Doe', (string) $this->alice->getId()], $options);
        self::assertContains(['Alice Doe (' . $other->getId() . ')', (string) $other->getId()], $options);
        $this->get('assignee=' . $other->getId());
        self::assertSame(['Theirs'], $this->titles());
    }

    public function testTheProviderSeesTheViewer(): void
    {
        $this->get();

        $viewer = $this->scope()->asked[0];
        self::assertSame((string) $this->alice->getId(), $viewer->userId);
        self::assertSame([], $viewer->context);
    }

    public function testATaskWithAnEmptyTagListAndNoColumn(): void
    {
        $task = $this->make($this->web, 'Loose');
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET column_id = NULL WHERE id = ?', [$task->getId()]);

        $this->get();

        self::assertSame(['Loose'], $this->titles());
        self::assertSelectorTextContains('[data-testid="nowo-task-multi-row"]', '—');
    }

    public function testAnArchivedTaskIsNeverListed(): void
    {
        $this->make($this->web, 'Visible');
        $this->make($this->web, 'Archived')->archive();
        $this->em->flush();

        $this->get('status=all');

        self::assertSame(['Visible'], $this->titles());
    }

    public function testDueDatesNowAreNotOverdueAndCompletedNeverAre(): void
    {
        $this->make($this->web, 'Tomorrow', ['due' => (new DateTimeImmutable('+1 day'))->format('Y-m-d')]);

        $this->get('overdue=1');

        self::assertSame([], $this->titles());
    }
}
