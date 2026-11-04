<?php

declare(strict_types=1);

/*
 * Since 1.22.1 (audits DL-L6, EB): the memory PoCs of Appendix EB as a regression check. Run by
 * ImportMemoryLimitsTest in a separate process: php -d memory_limit=128M import-limits.php <case>
 * Prints "REFUSED <translation key>", "PARSED <rows>" or "FATAL <message>".
 */

use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpJsonImporter;
use Nowo\TaskBoardBundle\Import\Support\DelimitedTableParser;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardJsonImporter;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && str_contains($error['message'], 'memory')) {
        echo 'FATAL ', $error['message'], "\n";
    }
});

$size  = 20_000_000 - 4096; // just under the upload form's cap
$cases = [
    'wide-header'       => static fn (): array => ['csv', str_repeat(',', $size) . "\nx\n"],
    'wide-row'          => static fn (): array => ['csv', "Issue key,Summary\n" . str_repeat(',', $size) . "\n"],
    'wide-tab'          => static fn (): array => ['csv', 'h' . str_repeat("\t", $size) . "\nx\n"],
    'quoted-row'        => static fn (): array => ['csv', "a,b\nx\"" . str_repeat(',', $size) . "\n"],
    'empty-grid'        => static fn (): array => ['csv', implode(',', array_map(static fn (int $i): string => 'h' . $i, range(1, 512))) . "\n" . str_repeat(str_repeat(',', 511) . "x\n", 5000)],
    'blank-lines'       => static fn (): array => ['csv', "a\n1\n" . str_repeat("\n", $size) . "b\n"],
    'one-cell'          => static fn (): array => ['csv', "a\n\"" . str_repeat('x', $size) . "\"\n"],
    'json-clickup'      => static fn (): array => ['clickup', '[' . rtrim(str_repeat('{},', intdiv($size, 3)), ',') . ']'],
    'json-clickup-ints' => static fn (): array => ['clickup', '[' . rtrim(str_repeat('0,', intdiv($size, 2)), ',') . ']'],
    'json-trello'       => static fn (): array => ['trello', '{"cards":[' . rtrim(str_repeat('{"name":"t"},', intdiv($size, 13)), ',') . ']}'],
    'json-taskboard'    => static fn (): array => ['taskboard', '[' . rtrim(str_repeat('{},', intdiv($size, 3)), ',') . ']'],
    // What the limits still accept: 5000 rows of 20 filled columns.
    'normal-csv' => static fn (): array => ['csv', implode(',', array_map(static fn (int $i): string => 'h' . $i, range(1, 20))) . "\n" . str_repeat(implode(',', array_fill(0, 20, 'value')) . "\n", 5000)],
];

[$kind, $content] = $cases[$argv[1] ?? '']();
try {
    $rows = match ($kind) {
        'csv'       => (new DelimitedTableParser())->parse($content, 'x.csv'),
        'clickup'   => (new ClickUpJsonImporter())->parse($content, 'x.json'),
        'trello'    => (new TrelloJsonImporter())->parse($content, 'x.json'),
        'taskboard' => (new TaskBoardJsonImporter())->parse($content, 'x.json'),
    };
    echo 'PARSED ', count($rows), "\n";
} catch (ImportLimitExceededException $exception) {
    echo 'REFUSED ', $exception->translationKey, "\n";
}
