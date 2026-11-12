<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

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
use SimpleXMLElement;
use ZipArchive;

use function array_map;
use function array_slice;
use function explode;
use function file_put_contents;
use function str_getcsv;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Since 2.13.0: the CSV and XLSX exports of the filtered multi-board list: streamed, capped by
 * `multi_board.export.max_rows`, no-store, and safe to open in a spreadsheet (formula injection).
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
final class MultiBoardExportTest extends MultiBoardTestCase
{
    /** Cells a spreadsheet would evaluate, and what the export writes instead. */
    private const INJECTIONS = [
        '=HYPERLINK("http://evil.example","x")' => "'=HYPERLINK(\"http://evil.example\",\"x\")",
        '+1+cmd|\' /C calc\'!A0'                => "'+1+cmd|' /C calc'!A0",
        '-2+3'                                  => "'-2+3",
        '@SUM(A1:A2)'                           => "'@SUM(A1:A2)",
        'Safe;=cmd'                             => 'Safe; =cmd',
    ];

    private AppUser $alice;

    private TaskBoard $board;

    protected function setUp(): void
    {
        self::$taskBoardConfig = ['multi_board' => ['enabled' => true, 'scope_provider' => 'app.multi_scope', 'export' => ['max_rows' => 6]], 'task_numbers' => ['enabled' => true]];
        parent::setUp();
        $this->alice = $this->user('alice@example.com');
        $this->alice->setDisplayName('=Alice');
        $this->board = $this->board('Website', creator: $this->alice);
        $this->allow($this->alice, new MultiBoardScopeEntry($this->board->getId(), '@Project'));
        $this->login($this->alice);
    }

    public function testTheCsvIsGuardedAgainstFormulaInjection(): void
    {
        $due = 1;
        foreach (self::INJECTIONS as $title => $ignored) {
            $this->make($this->board, $title, ['assignees' => [$this->alice], 'tags' => ['=tag'], 'due' => '2030-01-0' . $due++, 'priority' => TaskPriority::High, 'estimate' => 45, 'logged' => 90, 'start' => '2029-12-31']);
        }

        $csv = $this->export('csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=tasks-', (string) $this->client->getInternalResponse()->getHeader('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getInternalResponse()->getHeader('Cache-Control'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertNull($this->client->getInternalResponse()->getHeader('X-Task-Board-Export-Truncated'));

        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($csv, 3))));
        self::assertSame(['Number', 'Title', 'Board / Project', 'Column', 'Status', 'Priority', 'Assignees', 'Start', 'Due', 'Overdue', 'Labels', 'Estimate (min)', 'Logged (min)', 'Task ID'], $lines[0]);
        self::assertCount(6, $lines);
        $titles = [];
        foreach (array_slice($lines, 1) as $line) {
            $titles[] = $line[1];
            self::assertSame(["'@Project", 'To do', 'Open', 'High', "'=Alice", '2029-12-31', 'No', "'=tag", '45', '90'], [$line[2], $line[3], $line[4], $line[5], $line[6], $line[7], $line[9], $line[10], $line[11], $line[12]]);
        }
        self::assertSame(array_values(self::INJECTIONS), $titles);
    }

    public function testTheXlsxIsAValidWorkbookWithGuardedText(): void
    {
        foreach (array_keys(self::INJECTIONS) as $i => $title) {
            $this->make($this->board, $title, ['due' => '2030-01-0' . ($i + 1), 'estimate' => 30]);
        }
        $this->make($this->board, "<b>&\"quoted\"</b>\x01", ['due' => '2030-01-09']);

        $xlsx = $this->export('xlsx');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('.xlsx', (string) $this->client->getInternalResponse()->getHeader('Content-Disposition'));
        $file = tempnam(sys_get_temp_dir(), 'tb-xlsx');
        self::assertIsString($file);
        file_put_contents($file, $xlsx);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file, ZipArchive::CHECKCONS));
        self::assertSame(6, $zip->numFiles);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml'] as $part) {
            self::assertNotFalse(simplexml_load_string((string) $zip->getFromName($part)), $part);
        }
        $sheet = new SimpleXMLElement((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($file);

        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        self::assertSame([], $sheet->xpath('//s:f'), 'no formula element');
        $titles = array_map(static fn (SimpleXMLElement $cell): string => (string) $cell, $sheet->xpath('//s:row[position() > 1]/s:c[starts-with(@r, "B")]/s:is/s:t') ?: []);
        self::assertSame([...array_values(self::INJECTIONS), '<b>&"quoted"</b>'], $titles);
        self::assertSame('Title', (string) ($sheet->xpath('//s:row[1]/s:c[@r="B1"]/s:is/s:t')[0] ?? ''));
        $estimate = $sheet->xpath('//s:row[2]/s:c[@r="L2"]')[0] ?? null;
        self::assertNotNull($estimate);
        self::assertSame('30', (string) $estimate->v, 'numbers stay numbers');
        self::assertSame('', (string) $estimate['t']);
    }

    public function testTheExportStopsAtMaxRowsAndSaysSo(): void
    {
        for ($i = 1; $i <= 8; ++$i) {
            $this->make($this->board, 'Task ' . $i, ['due' => '2030-01-0' . $i, 'tags' => ['x']]);
        }

        foreach (['', 'label=x'] as $query) {
            $csv = $this->export('csv', $query);
            self::assertResponseHeaderSame('X-Task-Board-Export-Truncated', '6');
            self::assertCount(7, explode("\n", trim($csv)), $query);
            self::assertStringContainsString('Task 6', $csv);
            self::assertStringNotContainsString('Task 7', $csv);
        }
    }

    public function testTheExportFollowsTheFiltersAndTheSort(): void
    {
        $this->make($this->board, 'Alpha', ['priority' => TaskPriority::Low]);
        $this->make($this->board, 'Bravo', ['priority' => TaskPriority::Urgent]);
        $this->make($this->board, 'Charlie', ['completed' => true]);

        $csv = $this->export('csv', 'sort=title&dir=desc&status=all');
        self::assertMatchesRegularExpression('/Charlie.*\n.*Bravo.*\n.*Alpha/', $csv);
        self::assertStringContainsString('Done', $csv);

        $csv = $this->export('csv', 'priority=urgent');
        self::assertStringContainsString('Bravo', $csv);
        self::assertStringNotContainsString('Alpha', $csv);
    }

    public function testTheListLinksToTheExportOfTheSameFilter(): void
    {
        $this->make($this->board, 'Alpha');

        $this->get('priority=high&sort=title');

        self::assertSelectorExists('a[data-testid="nowo-task-multi-export-csv"][href="/tools/task-board/tasks/export.csv?priority=high&sort=title&dir=asc"]');
        self::assertSelectorExists('a[data-testid="nowo-task-multi-export-xlsx"][href^="/tools/task-board/tasks/export.xlsx?"]');
    }

    public function testAnEmptyExportHasTheHeaderOnly(): void
    {
        $this->make($this->board, 'Alpha');

        $csv = $this->export('csv', 'label=nothing');
        self::assertResponseIsSuccessful();
        self::assertCount(1, explode("\n", trim($csv)));

        $this->export('xlsx', 'q=nothing');
        self::assertResponseIsSuccessful();
    }
}
