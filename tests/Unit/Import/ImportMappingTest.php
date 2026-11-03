<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\Jira\JiraCsvImporter;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardCsvImporter;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Since 1.22: the new mappings (Gantt fields, names, creation dates), dates, CSV parsing and the import scopes. */
final class ImportMappingTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function dates(): iterable
    {
        yield 'empty' => ['', null];
        yield 'milliseconds' => ['1793534400000', '2026-11-01 12:00'];
        yield 'seconds' => ['1793534400', '2026-11-01 12:00'];
        yield 'nine digits' => ['999999999', '2001-09-09 01:46'];
        yield 'iso date' => ['2026-11-03', '2026-11-03 00:00'];
        yield 'iso date time' => ['2026-11-03 14:05', '2026-11-03 14:05'];
        yield 'day first' => ['03/04/2026', '2026-04-03 00:00'];
        yield 'month first when the day reads as a month' => ['11/20/2026', '2026-11-20 00:00'];
        yield 'us with seconds' => ['1/10/2024, 9:00:00 AM', '2024-10-01 09:00'];
        yield 'us only' => ['1/25/2024, 9:00:00 PM', '2024-01-25 21:00'];
        yield 'dotted' => ['03.04.2026', '2026-04-03 00:00'];
        yield 'jira' => ['03/Nov/26 10:15 AM', '2026-11-03 10:15'];
        yield 'clickup text' => ['Friday, March 1st 2024, 9:46:52 am', '2024-03-01 09:46'];
        yield 'short weekday' => ['Mon, Nov 2nd 2026', '2026-11-02 00:00'];
        yield 'text month' => ['3 Nov 2026', '2026-11-03 00:00'];
        yield 'fallback' => ['2026-11-03T10:00:00Z', '2026-11-03 10:00'];
        yield 'garbage' => ['not a date', null];
        yield 'relative' => ['next monday', null];
        yield 'year out of range' => ['99999999999999999999', null];
        yield 'zero date' => ['0000-00-00', null];
        yield 'far future text' => ['3000-01-01T00:00:00Z', null];
    }

    #[DataProvider('dates')]
    public function testDatesAreReadDeterministically(string $raw, ?string $expected): void
    {
        $date = (new ImportFieldMapper())->parseDate($raw);

        self::assertSame($expected, $date?->format('Y-m-d H:i'));
        self::assertEquals($date, (new ImportFieldMapper())->parseDueDate($raw), 'same value on every run');
    }

    public function testListsNamesAndDependencies(): void
    {
        $mapper = new ImportFieldMapper();

        self::assertSame(['Ada Lovelace', 'Alan Turing'], $mapper->parseNames('[Ada  Lovelace, Alan Turing, Ada Lovelace]'));
        self::assertSame([], $mapper->parseNames('Ada <ada@example.com>'));
        self::assertSame([], $mapper->parseNames(''));
        self::assertSame(['86a', '86b'], $mapper->parseIdList('["86a", #86b; 86a]'));
        self::assertSame(['a', 'b'], $mapper->parseTags('[a, "b"]'));
        self::assertSame(
            [['x', TaskDependencyType::BlockedBy], ['y', TaskDependencyType::Blocks]],
            $mapper->mapDependencies('x, self', 'y', 'self'),
        );
    }

    public function testCsvDropsTheBomKeepsMultilineCellsAndJoinsRepeatedHeaders(): void
    {
        $rows = (new DelimitedTableParser())->parse("\xEF\xBB\xBFTask ID,Labels,Labels,Labels,Notes\n1,a,,b,\"two\nlines\"\n\n2,,c,,\n", 'x.csv');

        self::assertSame([
            ['Task ID' => '1', 'Labels' => 'a;b', 'Notes' => "two\nlines"],
            ['Task ID' => '2', 'Labels' => 'c', 'Notes' => ''],
        ], $rows);
        self::assertSame([], (new DelimitedTableParser())->parse("\xEF\xBB\xBF \n", 'x.csv'));
    }

    public function testTheCsvParserRefusesTooManyRowsAndTooLongCells(): void
    {
        try {
            (new DelimitedTableParser(maxRows: 1))->parse("a\n1\n2\n", 'x.csv');
            self::fail('too many rows');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The file has more than 1 rows; an import takes at most 1.', $exception->getMessage());
        }
        try {
            (new DelimitedTableParser(maxCellLength: 3))->parse("a\n1234\n", 'x.csv');
            self::fail('too long');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('A cell of the file is longer than 3 bytes.', $exception->getMessage());
        }
        self::assertCount(1, (new DelimitedTableParser(maxRows: 1, maxCellLength: 3))->parse("a\n123\n", 'x.csv'));
    }

    public function testClickUpCsvReadsTheListViewColumns(): void
    {
        $csv = "Task ID,Task Name,Assignees,Start Date,Date Created,Dependencies,Waiting On,Blocking,Priority\n"
            . "86a,Plan,\"[Ada Lovelace, Alan]\",Nov 2 2026,1790000000000,86b,\"[86c, 86a]\",86d,\n";

        $task = (new ClickUpCsvImporter())->parse($csv, 'c.csv')[0];

        self::assertSame(['Ada Lovelace', 'Alan'], $task->assigneeNames);
        self::assertNull($task->assigneeEmail);
        self::assertSame('2026-11-02', $task->startAt?->format('Y-m-d'));
        self::assertSame('2026-09-21', $task->createdAt?->format('Y-m-d'));
        self::assertSame([['86b', TaskDependencyType::BlockedBy], ['86c', TaskDependencyType::BlockedBy], ['86d', TaskDependencyType::Blocks]], $task->dependsOn);
        self::assertFalse($task->hasPriority);
    }

    public function testClickUpJsonReadsDatesDependenciesAndUserNames(): void
    {
        $json = '{"tasks":[{"id":"a","name":"A","start_date":"1793534400000","date_created":"1790000000000","priority":{"priority":"high"},'
            . '"assignees":[{"username":"ada"},{"username":"ada"},"bob",{"email":"x"}],'
            . '"dependencies":["b","a",{"task_id":"a","depends_on":"c"},{"task_id":"d","depends_on":"a"},{"task_id":"e","depends_on":"f"},{"task_id":"a","depends_on":"a"},{"task_id":"a"}]},'
            . '{"name":"No id","assignees":"Ada, Bob","dependencies":[{"task_id":"x","depends_on":"y"}]},'
            . '{"id":"z","name":"Z","assignees":[{"email":"z@example.com","username":"zed"}],"dependencies":"nope"}]}';

        [$a, $noId, $z] = (new ClickUpJsonImporter())->parse($json, 'c.json');

        self::assertSame('2026-11-01', $a->startAt?->format('Y-m-d'));
        self::assertSame('2026-09-21', $a->createdAt?->format('Y-m-d'));
        self::assertSame(['ada', 'bob'], $a->assigneeNames);
        self::assertTrue($a->hasPriority);
        self::assertSame([['b', TaskDependencyType::BlockedBy], ['c', TaskDependencyType::BlockedBy], ['d', TaskDependencyType::Blocks]], $a->dependsOn);
        self::assertSame(['Ada', 'Bob'], $noId->assigneeNames);
        self::assertSame([], $noId->dependsOn);
        self::assertFalse($noId->hasPriority);
        self::assertSame('z@example.com', $z->assigneeEmail);
        self::assertSame([], $z->assigneeNames);
        self::assertSame([], $z->dependsOn);
    }

    public function testJiraReadsStartCreatedBlocksAndNames(): void
    {
        $csv = "Issue key,Summary,Assignee,Start date,Created,Inward issue link (Blocks),Outward issue link (Blocks),Time Spent,Priority\n"
            . "P-1,Epic,Ada Lovelace,2026-11-02,03/Oct/26 10:15 AM,P-2,P-3,7200,\n";

        $task = (new JiraCsvImporter())->parse($csv, 'j.csv')[0];

        self::assertSame(['Ada Lovelace'], $task->assigneeNames);
        self::assertSame('2026-11-02', $task->startAt?->format('Y-m-d'));
        self::assertSame('2026-10-03 10:15', $task->createdAt?->format('Y-m-d H:i'));
        self::assertSame([['P-2', TaskDependencyType::BlockedBy], ['P-3', TaskDependencyType::Blocks]], $task->dependsOn);
        self::assertNull($task->estimatedMinutes, 'time spent is not an estimate');
        self::assertFalse($task->hasPriority);
    }

    public function testTrelloReadsTheStartAndTheMembers(): void
    {
        $json = '{"members":[{"id":"m1","fullName":"Ada"},{"id":"m2","username":"bob"},{"fullName":"No id"},"junk"],'
            . '"cards":[{"id":"c1","name":"Card","start":"2026-11-01","idMembers":["m1","m2","m9"]},{"id":"c2","name":"Other","idMembers":"m1"}]}';

        [$card, $other] = (new TrelloJsonImporter())->parse($json, 't.json');

        self::assertSame('2026-11-01', $card->startAt?->format('Y-m-d'));
        self::assertSame(['Ada', 'bob'], $card->assigneeNames);
        self::assertSame([], $other->assigneeNames);
        self::assertFalse($card->hasPriority);
    }

    public function testTheOwnFormatTakesANameAndKnowsAnEmptyPriority(): void
    {
        [$named, $mailed] = (new TaskBoardCsvImporter())->parse("title,assignee,priority\nA,Ada Lovelace,\nB,ada@example.com,high\n", 'x.csv');

        self::assertSame(['Ada Lovelace'], $named->assigneeNames);
        self::assertFalse($named->hasPriority);
        self::assertSame([], $mailed->assigneeNames);
        self::assertTrue($mailed->hasPriority);
    }

    public function testSourcesHaveAProviderAndAScope(): void
    {
        $user = new TestUser('1', 'dev');
        $task = new Task(new TaskBoard('Demo', 'demo', $user), 'Epic', $user);

        self::assertSame(['clickup', 'clickup', 'jira', 'trello', 'taskboard', 'taskboard'], array_map(static fn (TaskImportSource $s): string => $s->provider(), TaskImportSource::cases()));
        self::assertTrue(TaskImportSource::JiraCsv->hasGlobalIds());
        self::assertFalse(TaskImportSource::TaskBoardJson->hasGlobalIds());
        self::assertSame('clickup', TaskImportSource::ClickUpJson->scope());
        self::assertSame('clickup@' . $task->getId(), TaskImportSource::ClickUpJson->scope($task), 'an import into a task is task-local for every source');
        self::assertSame('taskboard', TaskImportSource::TaskBoardCsv->scope());
        self::assertSame('taskboard@' . $task->getId(), TaskImportSource::TaskBoardCsv->scope($task));
    }

    public function testEntitiesAndResultCarryTheNewFields(): void
    {
        $user   = new TestUser('1', 'dev');
        $board  = new TaskBoard('Demo', 'demo', $user);
        $parent = new Task($board, 'Parent', $user);
        $task   = new Task($board, 'Task', $user);
        $before = $task->getUpdatedAt();

        self::assertSame($task, $task->setParent(null));
        self::assertSame($before, $task->getUpdatedAt(), 'same parent: untouched');
        $task->setParent($parent);
        self::assertSame($parent, $task->getParent());
        self::assertTrue($parent->getSubtasks()->contains($task));
        $task->setParent($parent);
        self::assertCount(1, $parent->getSubtasks());
        $task->setParent(null);
        self::assertNull($task->getParent());
        self::assertCount(0, $parent->getSubtasks());

        $link = new TaskLink($task, TaskLinkType::Other, 'x', importSource: 'jira');
        self::assertSame('jira', $link->getImportSource());
        self::assertNull($link->setImportSource(null)->getImportSource());

        $result = (new TaskImportResult())->withUpdated(2)->withUnchanged(3)->withCreated(1);
        self::assertSame([1, 2, 3], [$result->created, $result->updated, $result->unchanged]);
        self::assertSame(TaskPriority::Normal, (new ImportFieldMapper())->mapPriority(''));
    }
}
