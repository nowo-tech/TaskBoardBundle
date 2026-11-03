<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Import;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\DoctrineTaskImportLinkLookup;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_map;
use function count;
use function implode;
use function ksort;
use function range;
use function sort;
use function sprintf;
use function str_contains;

/**
 * Since 1.22: imports are idempotent upserts, against a real database (SQLite in memory, or
 * TASK_BOARD_TEST_DATABASE_URL) — every source re-imported, changed rows, fingerprints, the ClickUp Gantt fields, the
 * cross-board rule and pre-1.22 links.
 */
final class IdempotentImportTest extends KernelTestCase
{
    private const CLICKUP_CSV = <<<'CSV'
        Task ID,Task Name,Status,Priority,Assignees,Start Date,Due Date,Date Created,Dependencies,Blocking,Time Spent,Parent ID,Task Content
        86a1,Design,To do,high,[Ada Lovelace],1793534400000,1794139200000,1790000000000,,86a2,3600000,,"Mock-ups
        on two lines"
        86a2,Build,In progress,,,"Monday, November 2nd 2026, 9:00:00 am",11/20/2026,,86a1,,,,
        86a3,Sub build,In progress,low,,,,,"[86a2, 86zz]",,,86a2,
        CSV;

    private const CLICKUP_JSON = <<<'JSON'
        {"tasks": [
          {"id": "9a", "name": "Plan", "status": {"status": "To do"}, "start_date": "1793534400000", "due_date": "1794139200000",
           "date_created": "1790000000000", "dependencies": [{"task_id": "9b", "depends_on": "9a", "type": 1}]},
          {"id": "9b", "name": "Execute", "status": {"status": "Done"}, "priority": {"priority": "urgent"},
           "dependencies": [{"task_id": "9b", "depends_on": "9a", "type": 0}], "tags": [{"name": "ops"}]}
        ]}
        JSON;

    private const JIRA_CSV = <<<'CSV'
        Issue key,Summary,Status,Priority,Start date,Due Date,Created,Inward issue link (Blocks),Outward issue link (Blocks),Labels,Labels,Parent key
        PRJ-1,Epic,To Do,High,01/Nov/26 9:00 AM,2026-11-20,01/Oct/26 10:15 AM,,PRJ-2,backend,api,
        PRJ-2,Story,In Progress,,,,,PRJ-1,,,,PRJ-1
        CSV;

    private const TRELLO_JSON = <<<'JSON'
        {"lists": [{"id": "l1", "name": "To do"}, {"id": "l2", "name": "Done"}],
         "members": [{"id": "m1", "fullName": "Ada Lovelace"}],
         "cards": [
           {"id": "c1", "name": "Card one", "idList": "l1", "due": "2026-11-10T10:00:00.000Z", "start": "2026-11-01T00:00:00.000Z",
            "labels": [{"name": "x"}], "idMembers": ["m1"], "desc": "Hello"},
           {"id": "c2", "name": "Card two", "idList": "l2", "idParent": "c1"}
         ]}
        JSON;

    private const TASKBOARD_CSV = <<<'CSV'
        id;parent;title;status;priority;start;due;estimate;tags;depends_on
        design;;Design the screens;To do;high;2026-11-02;2026-11-06;8h;ux, ui;
        api;;Build the API;To do;;2026-11-04;2026-11-12;90;;design
        api-auth;api;Authentication endpoints;Doing;urgent;;;;;
        CSV;

    private const TASKBOARD_JSON = <<<'JSON'
        [{"id": "api", "title": "Build the API", "status": "To do", "subtasks": [{"id": "auth", "title": "Auth", "depends_on": ["api:related"]}]},
         {"title": "No id here", "status": "Doing"}]
        JSON;

    private EntityManagerInterface $em;

    private AppUser $alice;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->alice = new AppUser('alice@example.com');
        $this->em->persist($this->alice);
        $this->em->flush();
    }

    /** @return iterable<string, array{TaskImportSource, string, int}> */
    public static function sources(): iterable
    {
        yield 'ClickUp CSV' => [TaskImportSource::ClickUpCsv, self::CLICKUP_CSV, 3];
        yield 'ClickUp JSON' => [TaskImportSource::ClickUpJson, self::CLICKUP_JSON, 2];
        yield 'Jira CSV' => [TaskImportSource::JiraCsv, self::JIRA_CSV, 2];
        yield 'Trello JSON' => [TaskImportSource::TrelloJson, self::TRELLO_JSON, 2];
        yield 'TaskBoard CSV' => [TaskImportSource::TaskBoardCsv, self::TASKBOARD_CSV, 3];
        yield 'TaskBoard JSON' => [TaskImportSource::TaskBoardJson, self::TASKBOARD_JSON, 3];
    }

    #[DataProvider('sources')]
    public function testReimportingTheSameFileIsANoOpForEverySource(TaskImportSource $source, string $content, int $rows): void
    {
        $board = $this->board('Plan');

        $first = $this->import($board, $source, $content);
        self::assertFalse($first->hasErrors(), implode(' ', $first->errors));
        self::assertSame($rows, $first->created);
        $history = $this->rows(TaskChangeHistory::class);
        $links   = $this->rows(TaskLink::class);
        $deps    = $this->rows(TaskDependency::class);

        $second = $this->import($board, $source, $content);

        self::assertSame(0, $second->created);
        self::assertSame(0, $second->updated);
        self::assertSame($rows, $second->unchanged);
        self::assertSame(0, $second->skipped);
        self::assertSame(0, $second->columnsCreated);
        self::assertSame(0, $second->dependenciesCreated);
        self::assertSame($rows, $this->rows(Task::class), 'no duplicates');
        self::assertSame($history, $this->rows(TaskChangeHistory::class), 'no history written');
        self::assertSame($links, $this->rows(TaskLink::class));
        self::assertSame($deps, $this->rows(TaskDependency::class));
    }

    public function testClickUpCsvFillsTheBoardAndTheGantt(): void
    {
        $board  = $this->board('Plan');
        $result = $this->import($board, TaskImportSource::ClickUpCsv, self::CLICKUP_CSV);

        self::assertSame(3, $result->created);
        self::assertSame(2, $result->dependenciesCreated, 'Design blocks Build (Build waiting on Design is the same relation), Sub build blocked by Build');
        self::assertTrue($this->hasWarning($result, '"86zz"'));
        self::assertTrue($this->hasWarning($result, 'Ada Lovelace'), 'a name the assignee provider does not know');

        $design = $this->task('Design');
        self::assertSame('2026-11-01', $design->getStartAt()?->format('Y-m-d'));
        self::assertSame('2026-11-08', $design->getDueAt()?->format('Y-m-d'));
        self::assertSame('Mock-ups on two lines', $design->getDescription(), 'a quoted cell over two lines (whitespace collapsed)');
        self::assertSame(TaskPriority::High, $design->getPriority());
        self::assertSame('To do', $design->getColumn()?->getName());

        $build = $this->task('Build');
        self::assertSame('2026-11-02 09:00', $build->getStartAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-11-20 00:00', $build->getDueAt()?->format('Y-m-d H:i'));
        self::assertSame('In progress', $build->getColumn()?->getName());
        self::assertSame(['Build:blocks'], $this->relations($design));
        self::assertSame([], $this->relations($build));
        self::assertSame($build, $this->task('Sub build')->getParent());

        $link = $design->getLinks()->first();
        self::assertInstanceOf(TaskLink::class, $link);
        self::assertSame('clickup', $link->getImportSource());
        self::assertSame('86a1', $link->getExternalId());
    }

    public function testAChangedRowUpdatesItsTaskAndKeepsWhatTheSourceLeavesEmpty(): void
    {
        $board = $this->board('Plan');
        $this->import($board, TaskImportSource::ClickUpCsv, self::CLICKUP_CSV);

        // Changed in the application: an estimate (the source has none) and the priority of a row without one.
        $build = $this->task('Build');
        $build->setEstimatedMinutes(240);
        $build->setPriority(TaskPriority::Urgent);
        $this->em->flush();

        $changed = <<<'CSV'
            Task ID,Task Name,Status,Priority,Start Date,Due Date,Dependencies,Parent ID
            86a1,Design v2,Done,low,1793534400000,1794139200000,,
            86a2,Build,In progress,,"Monday, November 2nd 2026, 9:00:00 am",11/20/2026,86a1,
            86a3,Sub build,In progress,low,,,86a2,
            CSV;
        $result = $this->import($board, TaskImportSource::ClickUpCsv, $changed);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated, 'Design renamed, moved and reprioritised');
        self::assertSame(2, $result->unchanged, 'Build: empty priority and no estimate column keep the changes made here');

        $design = $this->task('Design v2');
        self::assertSame('Done', $design->getColumn()?->getName());
        self::assertTrue($design->isCompleted(), 'the last column completes the task');
        self::assertSame(TaskPriority::Low, $design->getPriority());
        self::assertSame('Mock-ups on two lines', $design->getDescription(), 'no Task Content column: kept');

        $build = $this->task('Build');
        self::assertSame(240, $build->getEstimatedMinutes());
        self::assertSame(TaskPriority::Urgent, $build->getPriority());
        self::assertSame($build, $this->task('Sub build')->getParent(), 'a row without a parent keeps its task where it is');
        self::assertSame(3, $this->rows(Task::class));
    }

    public function testClickUpJsonMatchesTheTasksOfAClickUpCsvImport(): void
    {
        $board = $this->board('Plan');
        $this->import($board, TaskImportSource::ClickUpCsv, "Task ID,Task Name,Status\n9a,Plan,To do\n");

        $result = $this->import($board, TaskImportSource::ClickUpJson, self::CLICKUP_JSON);

        self::assertSame(1, $result->created);
        self::assertSame(1, $result->updated, 'start / due dates added to Plan');
        self::assertSame(2, $this->rows(Task::class));
        self::assertSame(['Execute:blocks'], $this->relations($this->task('Plan')));
        self::assertTrue($this->task('Execute')->isCompleted());
    }

    public function testRowsWithoutAnIdAreMatchedByFingerprint(): void
    {
        $board = $this->board('Plan');
        $csv   = "Task Name,Status,Date Created\nWrite docs,To do,2026-10-01\nwrite   DOCS,To do,2026-10-01\nWrite docs,To do,2026-10-02\n";

        $first = $this->import($board, TaskImportSource::ClickUpCsv, $csv);
        self::assertSame(3, $first->created, 'same title and date twice: two tasks (the second is "-2")');
        self::assertTrue($this->hasWarning($first, '3 row(s) have no id'));

        $second = $this->import($board, TaskImportSource::ClickUpCsv, $csv);
        self::assertSame(0, $second->created);
        self::assertSame(3, $second->unchanged);
        self::assertTrue($this->hasWarning($second, 'fingerprint'));

        $renamed = $this->import($board, TaskImportSource::ClickUpCsv, "Task Name,Status,Date Created\nWrite the docs,To do,2026-10-01\n");
        self::assertSame(1, $renamed->created, 'a renamed row without an id is a new task');
        self::assertSame(4, $this->rows(Task::class));
    }

    public function testAnIdImportedIntoAnotherBoardOfTheTenantIsSkipped(): void
    {
        $first  = $this->board('First', 'acme');
        $second = $this->board('Second', 'acme');
        $other  = $this->board('Other tenant', 'globex');
        $this->import($first, TaskImportSource::ClickUpCsv, self::CLICKUP_CSV);

        $result = $this->import($second, TaskImportSource::ClickUpJson, self::CLICKUP_JSON . '');
        self::assertSame(2, $result->created, 'other ids: imported');

        $again = $this->import($second, TaskImportSource::ClickUpCsv, self::CLICKUP_CSV);
        self::assertSame(0, $again->created);
        self::assertSame(3, $again->skipped);
        self::assertTrue($this->hasWarning($again, 'already imported elsewhere'));

        $elsewhere = $this->import($other, TaskImportSource::ClickUpCsv, self::CLICKUP_CSV);
        self::assertSame(3, $elsewhere->created, 'another tenant is not looked at');
        self::assertSame(3 + 2 + 3, $this->rows(Task::class));
    }

    public function testRowsWithoutAnIdImportedIntoAnotherBoardOfTheTenantAreSkippedToo(): void
    {
        $first  = $this->board('First', 'acme');
        $second = $this->board('Second', 'acme');
        $other  = $this->board('Other tenant', 'globex');
        $csv    = "Task Name,Status,Date Created\nWrite docs,To do,2026-10-01\nShip,To do,\n";
        $this->import($first, TaskImportSource::ClickUpCsv, $csv);

        $again = $this->import($second, TaskImportSource::ClickUpCsv, $csv . "New here,To do,\n");
        self::assertSame(1, $again->created);
        self::assertSame(2, $again->skipped);
        self::assertTrue($this->hasWarning($again, '2 row(s) were skipped: already imported elsewhere'));

        self::assertSame(2, $this->import($other, TaskImportSource::ClickUpCsv, $csv)->created, 'another tenant is not looked at');

        $template = "title,status\nReview,To do\n";
        $this->import($first, TaskImportSource::TaskBoardCsv, $template);
        self::assertSame(1, $this->import($second, TaskImportSource::TaskBoardCsv, $template)->created, 'the own format stays board-local');
    }

    public function testTheCreationDateStartsTheGanttBarOfANewTaskOnly(): void
    {
        $board = $this->board('Plan');
        $this->import($board, TaskImportSource::ClickUpCsv, "Task ID,Task Name,Date Created,Start Date\n1,No start,1790000000000,\n2,With start,1790000000000,1793534400000\n");

        self::assertSame('2026-09-21', $this->task('No start')->getStartAt()?->format('Y-m-d'), 'Date Created when Start Date is missing');
        self::assertSame('2026-11-01', $this->task('With start')->getStartAt()?->format('Y-m-d'));

        $task = $this->task('No start');
        $task->setStartAt(new DateTimeImmutable('2026-10-05'));
        $this->em->flush();
        $again = $this->import($board, TaskImportSource::ClickUpCsv, "Task ID,Task Name,Date Created,Start Date\n1,No start,1791000000000,\n");

        self::assertSame(1, $again->unchanged, 'a re-import never sets the start from the creation date');
        self::assertSame('2026-10-05', $this->task('No start')->getStartAt()?->format('Y-m-d'));

        $this->import($board, TaskImportSource::JiraCsv, "Issue key,Summary,Created\nPRJ-1,Jira issue,01/Oct/26 10:15 AM\n");
        self::assertSame('2026-10-01', $this->task('Jira issue')->getStartAt()?->format('Y-m-d'));
    }

    public function testTheLookupSeesBoardsWithoutTenantAndOnlyImportLinks(): void
    {
        $first  = $this->board('First');
        $second = $this->board('Second');
        $task   = new Task($first, 'Legacy', $this->alice);
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'import://jira_csv/PRJ-9', 'jira_csv #PRJ-9', 'PRJ-9'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'https://example.com', 'Hand-made', 'PRJ-8'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'import://jira_csv/PRJ-5', 'jira_csv #PRJ-5', 'PRJ-5', importSource: 'jira'));
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'import://trello_json/PRJ-6', 'trello_json #PRJ-6', 'PRJ-6', importSource: 'trello'));
        $this->em->persist($task);
        $this->em->flush();

        $lookup = self::getContainer()->get(TaskImportLinkLookupInterface::class);
        self::assertInstanceOf(DoctrineTaskImportLinkLookup::class, $lookup);
        $found = $lookup->findOnOtherBoards($second, 'jira', ['PRJ-9', 'PRJ-8', 'PRJ-7', 'PRJ-6', 'PRJ-5']);
        ksort($found);
        self::assertSame(['PRJ-5' => true], $found, 'only links an import wrote: not a legacy-shaped or hand-made label, not another tool');
        self::assertSame([], $lookup->findOnOtherBoards($second, 'clickup', ['PRJ-5']));
        self::assertSame([], $lookup->findOnOtherBoards($first, 'jira', ['PRJ-5']), 'the board itself is not "another board"');
        self::assertSame([], $lookup->findOnOtherBoards($second, 'jira', array_map(static fn (int $i): string => 'X-' . $i, range(1, 600))), 'ids in chunks');
    }

    public function testLinksWithoutAnImportSourceAreNotMatched(): void
    {
        $board = $this->board('Plan');
        $task  = new Task($board, 'Old title', $this->alice, $board->getColumns()->first() ?: null);
        $task->addLink(new TaskLink($task, TaskLinkType::Other, 'import://clickup_json/86a1', 'clickup_json #86a1', '86a1'));
        $this->em->persist($task);
        $this->em->flush();

        $result = $this->import($board, TaskImportSource::ClickUpCsv, "Task ID,Task Name\n86a1,Design\n");

        self::assertSame(1, $result->created, 'a pre-1.22 (or hand-made) link is not trusted: see the UPGRADING backfill');
        self::assertSame('Old title', $this->task('Old title')->getTitle());
    }

    public function testTheOwnFormatCanBeImportedUnderSeveralTasksOfOneBoard(): void
    {
        $board = $this->board('Plan');
        $one   = new Task($board, 'Epic one', $this->alice);
        $two   = new Task($board, 'Epic two', $this->alice);
        $this->em->persist($one);
        $this->em->persist($two);
        $this->em->flush();

        $first  = $this->import($board, TaskImportSource::TaskBoardCsv, self::TASKBOARD_CSV, $one->getId());
        $second = $this->import($board, TaskImportSource::TaskBoardCsv, self::TASKBOARD_CSV, $two->getId());
        $again  = $this->import($board, TaskImportSource::TaskBoardCsv, self::TASKBOARD_CSV, $two->getId());

        self::assertSame(3, $first->created);
        self::assertSame(3, $second->created, 'a template, not the same tasks');
        self::assertSame(3, $again->unchanged);
        self::assertSame(2 + 6, $this->rows(Task::class));
    }

    private function import(TaskBoard $board, TaskImportSource $source, string $content, ?string $parentTaskId = null): TaskImportResult
    {
        $this->em->clear();
        $board = $this->em->find(TaskBoard::class, $board->getId());
        $actor = $this->em->find(AppUser::class, $this->alice->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertInstanceOf(AppUser::class, $actor);
        $parent = $parentTaskId !== null ? $this->em->find(Task::class, $parentTaskId) : null;

        $orchestrator = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $orchestrator);
        $result = $orchestrator->import($board, $source, $content, 'export', $actor, parentTask: $parent);
        $this->em->clear();

        return $result;
    }

    private function board(string $name, ?string $tenantKey = null): TaskBoard
    {
        $alice = $this->em->find(AppUser::class, $this->alice->getId());
        self::assertInstanceOf(AppUser::class, $alice);
        $this->alice = $alice;
        $board       = (new TaskBoard($name, sprintf('%s-%d', $name, count($this->em->getRepository(TaskBoard::class)->findAll())), $alice))->setTenantKey($tenantKey);
        foreach (['To do', 'In progress', 'Doing', 'Done'] as $position => $column) {
            $board->addColumn(new BoardColumn($board, $column, $position));
        }
        $this->em->persist($board);
        $this->em->flush();

        return $board;
    }

    private function task(string $title): Task
    {
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Task::class, $task, $title);

        return $task;
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return $this->em->getRepository($class)->count([]);
    }

    /**
     * @return list<string>
     */
    private function relations(Task $task): array
    {
        $relations = array_map(
            static fn (TaskDependency $d): string => $d->getTargetTask()->getTitle() . ':' . $d->getDependencyType()->value,
            [...$task->getOutgoingDependencies()],
        );
        sort($relations);

        return $relations;
    }

    private function hasWarning(TaskImportResult $result, string $needle): bool
    {
        foreach ($result->warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }
}
