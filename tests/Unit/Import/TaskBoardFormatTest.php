<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\NullTaskImportUserResolver;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardCsvImporter;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardJsonImporter;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardRowMapper;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/** Since 1.18: the bundle's own CSV / JSON — hierarchy, start dates, Gantt relations — and importing into a task. */
final class TaskBoardFormatTest extends TestCase
{
    private const CSV = <<<'CSV'
        ID;Parent;Title;Status;Priority;Assignee;Start;Due;Estimate;Tags;Depends_on;Description
        design;;Design the screens;To do;high;Ana@Example.com;2026-11-02;2026-11-06;8h;ux, ui;;Mock-ups
        api;;Build the API;To do;;;2026-11-04;2026-11-12;90;;;
        api-auth;api;Authentication endpoints;Doing;urgent;;;;;;;
        release;;Release;To do;;;2026-11-13;2026-11-13;;;design,api:related,ghost;
        ;;;;;;;;;;;
        CSV;

    public function testTheCsvMapsEveryColumn(): void
    {
        $rows = (new TaskBoardCsvImporter())->parse(self::CSV, 'plan.csv');

        self::assertCount(4, $rows);
        [$design, $api, $auth, $release] = $rows;
        self::assertSame('design', $design->externalId);
        self::assertNull($design->parentExternalId);
        self::assertSame('To do', $design->status);
        self::assertSame(TaskPriority::High, $design->priority);
        self::assertSame('ana@example.com', $design->assigneeEmail);
        self::assertSame('2026-11-02', $design->startAt?->format('Y-m-d'));
        self::assertSame('2026-11-06', $design->dueAt?->format('Y-m-d'));
        self::assertSame(480, $design->estimatedMinutes);
        self::assertSame(['ux', 'ui'], $design->tags);
        self::assertSame('Mock-ups', $design->description);
        self::assertSame(90, $api->estimatedMinutes);
        self::assertSame('api', $auth->parentExternalId);
        self::assertSame(TaskPriority::Urgent, $auth->priority);
        self::assertSame([['design', TaskDependencyType::BlockedBy], ['api', TaskDependencyType::Related], ['ghost', TaskDependencyType::BlockedBy]], $release->dependsOn);
        self::assertTrue((new TaskBoardCsvImporter())->supports(TaskImportSource::TaskBoardCsv));
        self::assertFalse((new TaskBoardCsvImporter())->supports(TaskImportSource::TaskBoardJson));
    }

    public function testTheJsonNestsSubtasksAndAcceptsListsAndAnObject(): void
    {
        $json = '{"tasks": [{"id": "api", "title": "Build the API", "tags": ["back", "api"], "subtasks": [{"id": "auth", "title": "Auth", "subtasks": [{"title": "Tokens"}]}]}, {"title": "Release", "depends_on": ["api", "auth:blocks"]}, "noise", {"title": ""}]}';
        $rows = (new TaskBoardJsonImporter())->parse($json, 'plan.json');

        self::assertSame(['Build the API', 'Auth', 'Tokens', 'Release'], array_map(static fn ($r): string => $r->title, $rows));
        self::assertSame(['back', 'api'], $rows[0]->tags);
        self::assertSame('api', $rows[1]->parentExternalId);
        self::assertSame('auth', $rows[2]->parentExternalId);
        self::assertSame([['api', TaskDependencyType::BlockedBy], ['auth', TaskDependencyType::Blocks]], $rows[3]->dependsOn);
        self::assertCount(1, (new TaskBoardJsonImporter())->parse('[{"title": "Alone"}]', 'list.json'));
        self::assertTrue((new TaskBoardJsonImporter())->supports(TaskImportSource::TaskBoardJson));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string}>
     */
    public static function badFiles(): iterable
    {
        yield 'bad date' => ["title;start\nA;02/11/2026", false, 'is not a date'];
        yield 'impossible date' => ["title;due\nA;2026-02-31", false, 'is not a date'];
        yield 'bad relation' => ["title;depends_on\nA;b:after", false, 'is not a dependency type'];
        yield 'no titles' => ["title;status\n;To do", false, 'No rows with a "title"'];
        yield 'not json' => ['{nope', true, 'not valid JSON'];
        yield 'json object' => ['{"title": "A"}', true, 'must be a list'];
        yield 'json empty' => ['[]', true, 'No tasks with a "title"'];
        yield 'json subtasks without id' => ['[{"title": "A", "subtasks": [{"title": "B"}]}]', true, 'has subtasks but no "id"'];
    }

    /**
     * @dataProvider badFiles
     */
    public function testBadFilesAreRefusedWithTheRow(string $content, bool $json, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $json ? (new TaskBoardJsonImporter())->parse($content, 'x.json') : (new TaskBoardCsvImporter())->parse($content, 'x.csv');
    }

    public function testTheMapperListsItsColumns(): void
    {
        self::assertContains('depends_on', TaskBoardRowMapper::COLUMNS);
        self::assertNull((new TaskBoardRowMapper())->map(['title' => ['not', 'text']], 1));
    }

    public function testImportingIntoATaskBuildsTheSubtaskTreeStartDatesAndGanttRelations(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));
        $board->addColumn(new BoardColumn($board, 'Doing', 1));
        $epic = new Task($board, 'Epic', $user);

        /** @var list<Task> $saved */
        $saved          = [];
        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturnCallback(static function () use (&$saved, $epic): array {
            return [$epic, ...$saved];
        });
        $taskRepository->method('save')->willReturnCallback(static function (Task $task) use (&$saved): void {
            $saved[] = $task;
        });
        $persisted     = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $task) use (&$persisted, &$saved): void {
            $persisted[] = $task;
            $saved[]     = $task;
        });

        $orchestrator = new TaskImportOrchestrator(
            importers: [new TaskBoardCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
            dependencyManager: new TaskDependencyManager($taskRepository, new TaskChangeRecorder()),
        );
        $csv    = self::CSV . "\nloop;;Loop;To do;;;;;;;release:blocks;\nloop2;;Loop again;To do;;;;;;;release:blocks;\ndup;;Twice;To do;;;;;;;design,design;";
        $result = $orchestrator->import($board, TaskImportSource::TaskBoardCsv, $csv, 'plan.csv', $user, parentTask: $epic);

        self::assertFalse($result->hasErrors());
        self::assertSame(7, $result->created);
        $byTitle = [];
        foreach ($persisted as $task) {
            self::assertInstanceOf(Task::class, $task);
            $byTitle[$task->getTitle()] = $task;
        }
        self::assertSame($epic, $byTitle['Design the screens']->getParent());
        self::assertSame($byTitle['Build the API'], $byTitle['Authentication endpoints']->getParent());
        self::assertSame('2026-11-04', $byTitle['Build the API']->getStartAt()?->format('Y-m-d'));
        $relations = array_map(
            static fn ($d): string => $d->getTargetTask()->getTitle() . ':' . $d->getDependencyType()->value,
            [...$byTitle['Release']->getOutgoingDependencies()],
        );
        self::assertSame(['Design the screens:blocked_by', 'Build the API:related'], $relations);
        self::assertSame(5, $result->dependenciesCreated, 'release → design, release → api, loop → release, loop2 → release, dup → design');
        self::assertCount(1, array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'duplicate or cycle')));
        self::assertCount(1, array_filter($result->warnings, static fn (string $w): bool => str_contains($w, '"ghost"')));
    }

    public function testWithoutTheDependencyServiceRelationsAreIgnoredAndUnknownParentsHangFromTheTask(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $board->addColumn(new BoardColumn($board, 'To do', 0));
        $epic = new Task($board, 'Epic', $user);

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findByBoard')->willReturn([$epic]);
        $persisted     = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $task) use (&$persisted): void {
            $persisted[] = $task;
        });
        $orchestrator = new TaskImportOrchestrator(
            importers: [new TaskBoardCsvImporter()],
            taskRepository: $taskRepository,
            columnManager: new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class)),
            changeRecorder: new TaskChangeRecorder(),
            userResolver: new NullTaskImportUserResolver(),
            entityManager: $entityManager,
        );
        $result = $orchestrator->import($board, TaskImportSource::TaskBoardCsv, "id;parent;title;depends_on\nb;missing;Orphan;a", 'x.csv', $user, parentTask: $epic);

        self::assertSame(0, $result->dependenciesCreated);
        self::assertInstanceOf(Task::class, $persisted[0]);
        self::assertSame($epic, $persisted[0]->getParent());
        self::assertStringContainsString('under the task you import into', $result->warnings[0]);
    }
}
