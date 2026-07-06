<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpCsvImporter;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use PHPUnit\Framework\TestCase;

final class ClickUpCsvImporterTest extends TestCase
{
    public function testParsesClickUpCsvExport(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/../../Fixtures/clickup/sample.csv');
        $tasks   = (new ClickUpCsvImporter())->parse($content, 'clickup.csv');

        self::assertCount(3, $tasks);
        self::assertSame('Setup project', $tasks[0]->title);
        self::assertSame('1001', $tasks[0]->externalId);
        self::assertSame(TaskPriority::High, $tasks[0]->priority);
        self::assertSame(['backend', 'setup'], $tasks[0]->tags);
        self::assertNull($tasks[0]->parentExternalId);

        self::assertSame('Import tasks', $tasks[1]->title);
        self::assertSame('1002', $tasks[1]->externalId);
        self::assertSame('1001', $tasks[1]->parentExternalId);
        self::assertSame(TaskPriority::Urgent, $tasks[1]->priority);
        self::assertSame(90, $tasks[1]->estimatedMinutes);
        self::assertSame('dev@example.com', $tasks[1]->assigneeEmail);
    }

    public function testSupportsClickUpCsvSource(): void
    {
        $importer = new ClickUpCsvImporter();
        self::assertTrue($importer->supports(TaskImportSource::ClickUpCsv));
        self::assertFalse($importer->supports(TaskImportSource::JiraCsv));
    }
}
