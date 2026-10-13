<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\Jira\JiraCsvImporter;
use Nowo\TaskBoardBundle\Import\TaskImporterInterface;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const JSON_THROW_ON_ERROR;

final class ImportersTest extends TestCase
{
    public function testClickUpJsonParsesNestedStructures(): void
    {
        $json = json_encode(['tasks' => [
            [
                'id'            => 'abc',
                'name'          => 'Parent task',
                'status'        => ['status' => 'In progress'],
                'priority'      => ['priority' => 'urgent'],
                'text_content'  => '<p>Rich <b>text</b></p>',
                'assignees'     => [['email' => ''], 'not-an-array', ['email' => 'Dev@Example.com']],
                'due_date'      => '1786000000000',
                'time_estimate' => '7200000',
                'tags'          => [['name' => 'backend'], ['name' => ''], ' ops ', '', 42],
                'url'           => 'https://app.clickup.com/t/abc',
            ],
            [
                'task_id'   => 'def',
                'task_name' => 'Child task',
                'parent'    => 'abc',
                'status'    => 'Done',
                'priority'  => 'low',
                'assignee'  => 'Jane <jane@example.com>',
                'tags'      => 'one, two',
            ],
            ['name' => ''],
            'not-a-row',
        ]], JSON_THROW_ON_ERROR);

        $tasks = (new ClickUpJsonImporter())->parse($json, 'export.json');

        self::assertCount(2, $tasks);
        self::assertSame('Parent task', $tasks[0]->title);
        self::assertSame('abc', $tasks[0]->externalId);
        self::assertNull($tasks[0]->parentExternalId);
        self::assertSame('In progress', $tasks[0]->status);
        self::assertSame(TaskPriority::Urgent, $tasks[0]->priority);
        self::assertSame('Rich text', $tasks[0]->description);
        self::assertSame('dev@example.com', $tasks[0]->assigneeEmail);
        self::assertNotNull($tasks[0]->dueAt);
        self::assertSame(120, $tasks[0]->estimatedMinutes);
        self::assertSame(['backend', 'ops'], $tasks[0]->tags);
        self::assertSame('https://app.clickup.com/t/abc', $tasks[0]->sourceUrl);

        self::assertSame('def', $tasks[1]->externalId);
        self::assertSame('abc', $tasks[1]->parentExternalId);
        self::assertSame('Done', $tasks[1]->status);
        self::assertSame(TaskPriority::Low, $tasks[1]->priority);
        self::assertSame('jane@example.com', $tasks[1]->assigneeEmail);
        self::assertSame(['one', 'two'], $tasks[1]->tags);
        self::assertNull($tasks[1]->description);
        self::assertNull($tasks[1]->sourceUrl);
    }

    public function testClickUpJsonAcceptsTopLevelListAndNestedList(): void
    {
        $importer = new ClickUpJsonImporter();

        $list = $importer->parse('[{"name":"A","status":{"name":"Todo"},"priority":{"name":"high"}}]', 'a.json');
        self::assertSame('Todo', $list[0]->status);
        self::assertSame(TaskPriority::High, $list[0]->priority);
        self::assertNull($list[0]->externalId);

        $nested = $importer->parse('{"meta":"x","data":[{"title":"B"}]}', 'b.json');
        self::assertSame('B', $nested[0]->title);
        self::assertNull($nested[0]->status);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidClickUpJson(): iterable
    {
        yield 'not json' => ['{oops', 'Invalid ClickUp JSON'];
        yield 'no rows' => ['{"meta":"x"}', 'No tasks found'];
        yield 'empty object' => ['{}', 'No tasks found'];
        yield 'no valid rows' => ['{"tasks":[{"name":""}]}', 'No valid tasks'];
    }

    #[DataProvider('invalidClickUpJson')]
    public function testClickUpJsonRejectsInvalidExports(string $content, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ClickUpJsonImporter())->parse($content, 'x.json');
    }

    public function testJiraCsvParsesIssues(): void
    {
        $csv = "Issue key;Summary;Status;Priority;Description;Assignee;Due Date;Original Estimate;Labels;Parent key;URL\n"
            . "PRJ-1;Epic work;To Do;Highest;<p>Big</p>;lead@example.com;2026-09-01;3:30;a|b;;https://jira/PRJ-1\n"
            . "PRJ-2;Sub work;Done;P4;;nobody;;;;PRJ-1;\n"
            . ";;;;;;;;;;\n";

        $tasks = (new JiraCsvImporter())->parse($csv, 'jira.csv');

        self::assertCount(2, $tasks);
        self::assertSame('PRJ-1', $tasks[0]->externalId);
        self::assertSame('To Do', $tasks[0]->status);
        self::assertSame(TaskPriority::Normal, $tasks[0]->priority);
        self::assertSame('Big', $tasks[0]->description);
        self::assertSame('lead@example.com', $tasks[0]->assigneeEmail);
        self::assertSame(210, $tasks[0]->estimatedMinutes);
        self::assertSame(['a', 'b'], $tasks[0]->tags);
        self::assertSame('https://jira/PRJ-1', $tasks[0]->sourceUrl);
        self::assertSame('PRJ-1', $tasks[1]->parentExternalId);
        self::assertSame(TaskPriority::Low, $tasks[1]->priority);
        self::assertNull($tasks[1]->assigneeEmail);
        self::assertNull($tasks[1]->sourceUrl);
        self::assertTrue((new JiraCsvImporter())->supports(TaskImportSource::JiraCsv));
        self::assertFalse((new JiraCsvImporter())->supports(TaskImportSource::ClickUpCsv));
    }

    public function testJiraCsvRejectsEmptyOrSummaryLessFiles(): void
    {
        try {
            (new JiraCsvImporter())->parse('', 'jira.csv');
            self::fail('Expected exception for empty file');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('empty', $exception->getMessage());
        }

        $this->expectExceptionMessage('No issues with a Summary column');
        (new JiraCsvImporter())->parse("Key,Status\nPRJ-1,Done\n", 'jira.csv');
    }

    public function testTrelloJsonMapsListsLabelsAndParents(): void
    {
        $json = json_encode([
            'lists' => [['id' => 'l1', 'name' => 'Doing'], ['name' => 'no id'], 'bogus'],
            'cards' => [
                [
                    'id'       => 'c1',
                    'name'     => 'Card one',
                    'idList'   => 'l1',
                    'desc'     => 'Plain <i>desc</i>',
                    'due'      => '2026-10-01T10:00:00.000Z',
                    'labels'   => [['name' => 'red'], ['name' => ''], 'x'],
                    'shortUrl' => 'https://trello.com/c/1',
                ],
                ['id' => 'c2', 'name' => 'Card two', 'idList' => 'missing', 'idParent' => 'c1'],
                ['name' => ''],
                'bogus',
            ],
        ], JSON_THROW_ON_ERROR);

        $importer = new TrelloJsonImporter();
        $tasks    = $importer->parse($json, 'board.json');

        self::assertTrue($importer->supports(TaskImportSource::TrelloJson));
        self::assertCount(2, $tasks);
        self::assertSame('Doing', $tasks[0]->status);
        self::assertSame('Plain desc', $tasks[0]->description);
        self::assertSame(['red'], $tasks[0]->tags);
        self::assertNotNull($tasks[0]->dueAt);
        self::assertSame('https://trello.com/c/1', $tasks[0]->sourceUrl);
        self::assertNull($tasks[1]->status);
        self::assertSame('c1', $tasks[1]->parentExternalId);
        self::assertNull($tasks[1]->description);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTrelloJson(): iterable
    {
        yield 'not json' => ['nope', 'Invalid Trello JSON'];
        yield 'no cards' => ['{"lists":[]}', 'No cards found'];
        yield 'cards not a list' => ['{"cards":"x"}', 'No cards found'];
        yield 'no valid cards' => ['{"cards":[{"name":""}]}', 'No valid cards'];
    }

    #[DataProvider('invalidTrelloJson')]
    public function testTrelloJsonRejectsInvalidExports(string $content, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new TrelloJsonImporter())->parse($content, 'x.json');
    }

    public function testClickUpCsvRejectsEmptyFilesAndRowsWithoutTitle(): void
    {
        try {
            (new ClickUpCsvImporter())->parse("Task Name,Status\n", 'c.csv');
            self::fail('Expected exception for header-only file');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('empty', $exception->getMessage());
        }

        $this->expectExceptionMessage('No tasks with a Task Name column');
        (new ClickUpCsvImporter())->parse("Task Name,Status\n,Done\n", 'c.csv');
    }

    public function testClickUpCsvFallsBackToParentColumn(): void
    {
        $tasks = (new ClickUpCsvImporter())->parse("Task ID,Task Name,Parent\n2,Child,1\n", 'c.csv');

        self::assertSame('1', $tasks[0]->parentExternalId);
    }

    public function testImportSourceLabelsAndExtensions(): void
    {
        $expected = [
            'clickup_csv'  => ['csv', 'txt', 'tsv'],
            'clickup_json' => ['json'],
            'jira_csv'     => ['csv', 'txt', 'tsv'],
            'trello_json'  => ['json'],
        ];

        foreach (TaskImportSource::cases() as $source) {
            self::assertSame('task_board.import.source.' . $source->value, $source->labelKey());
            self::assertSame($expected[$source->value], $source->acceptedExtensions());
        }
    }

    public function testImportResultWithers(): void
    {
        $result = (new TaskImportResult())
            ->withCreated(3)
            ->withSkipped(2)
            ->withColumnsCreated(1)
            ->withErrors(['boom'])
            ->withWarnings(['careful']);

        self::assertSame([3, 2, 1, ['boom'], ['careful']], [$result->created, $result->skipped, $result->columnsCreated, $result->errors, $result->warnings]);
        self::assertTrue($result->hasErrors());
    }

    public function testEveryImporterSupportsOnlyItsSource(): void
    {
        /** @var array<string, TaskImporterInterface> $importers */
        $importers = [
            'clickup_csv'  => new ClickUpCsvImporter(),
            'clickup_json' => new ClickUpJsonImporter(),
            'jira_csv'     => new JiraCsvImporter(),
            'trello_json'  => new TrelloJsonImporter(),
        ];

        foreach ($importers as $value => $importer) {
            foreach (TaskImportSource::cases() as $source) {
                self::assertSame($source->value === $value, $importer->supports($source));
            }
        }
    }
}
