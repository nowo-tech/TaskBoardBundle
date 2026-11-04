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
use Nowo\TaskBoardBundle\Import\Support\ImportFieldMapper;
use Nowo\TaskBoardBundle\Import\Support\JsonImportGuard;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardJsonImporter;
use Nowo\TaskBoardBundle\Import\Trello\TrelloJsonImporter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && str_contains($error['message'], 'memory')) {
        echo 'FATAL ', $error['message'], "\n";
    }
});

$size = 20_000_000 - 4096; // just under the upload form's cap
// The largest $make(n) the default weight accepts (audit EB-L6: the weight counts the doubled tables).
$atCap = static function (callable $make): string {
    $weigh = new ReflectionMethod(JsonImportGuard::class, 'weigh');
    $fits  = static function (string $json) use ($weigh): bool {
        try {
            $weigh->invoke(new JsonImportGuard(), $json);

            return true;
        } catch (ImportLimitExceededException) {
            return false;
        }
    };
    [$low, $high] = [1, 1 << 23];
    while ($low < $high) {
        $mid          = intdiv($low + $high + 1, 2);
        [$low, $high] = $fits($make($mid)) ? [$mid, $high] : [$low, $mid - 1];
    }

    return $make($low);
};
// A ClickUp API task with the field set of a real export (audit EB-I7), as the auditor measured it.
$clickUpTask = static fn (int $i): string => '{"id":"86' . $i . 'abc","custom_id":null,"name":"Task ' . $i . '","text_content":"' . str_repeat('Some description text. ', 6) . '","description":"' . str_repeat('Some description text. ', 6) . '","status":{"id":"sc1","status":"in progress","color":"#d3d3d3","orderindex":1,"type":"custom"},"orderindex":"1.000","date_created":"1793534400000","date_updated":"1793534400000","date_closed":null,"date_done":null,"archived":false,"creator":{"id":1,"username":"Ada","color":"#000","email":"ada@example.test","profilePicture":null},"assignees":[{"id":1,"username":"Ada","color":"#000","initials":"A","email":"ada@example.test","profilePicture":null}],"watchers":[],"checklists":[],"tags":[{"name":"bug","tag_fg":"#fff","tag_bg":"#f00","creator":1}],"parent":null,"priority":{"id":"2","priority":"high","color":"#ffcc00","orderindex":"2"},"due_date":"1793534400000","start_date":null,"points":null,"time_estimate":3600000,"custom_fields":[' . implode(',', array_map(static fn (int $k): string => '{"id":"cf' . $k . '","name":"Field ' . $k . '","type":"short_text","type_config":{},"date_created":"1","hide_from_guests":false,"required":false}', range(1, 5))) . '],"dependencies":[],"linked_tasks":[],"team_id":"1","url":"https://app.clickup.com/t/86' . $i . 'abc","sharing":{"public":false,"public_share_expires_on":null,"public_fields":["assignees","priority"],"token":null,"seo_optimized":false},"permission_level":"create","list":{"id":"1","name":"List","access":true},"project":{"id":"1","name":"Folder","hidden":false,"access":true},"folder":{"id":"1","name":"Folder","hidden":false,"access":true},"space":{"id":"1"}}';
$cases       = [
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
    // Audit EB-L4: a lone "\r" does not end a record for fgetcsv(), so it does not for the pre-pass either.
    'cr-split-cell'  => static fn (): array => ['csv', "a\n" . rtrim(str_repeat(str_repeat('x', 60000) . "\r", 330))],
    'cr-split-wide'  => static fn (): array => ['csv', "a,b\n" . rtrim(str_repeat(str_repeat(',', 400) . "\r", 2400))],
    'cr-wide-header' => static function (): array {
        $header = '';
        for ($i = 1; $i <= 1950 * 511; ++$i) {
            $header .= 'h' . $i . ($i % 511 === 0 ? "\r" : ',');
        }

        return ['csv', rtrim($header, "\r,") . "\n" . str_repeat("x\n", 40)];
    },
    // Audit EB-L5: keys and short strings are weighed.
    'json-strings' => static fn (): array => ['clickup', '[' . rtrim(str_repeat('"ab",', 1_999_990), ',') . ']'],
    'json-keys'    => static function (): array {
        $json = '{"cards":{';
        for ($i = 1; $i <= 1_400_000; ++$i) {
            $json .= '"' . dechex($i) . '":"",';
        }

        return ['trello', rtrim($json, ',') . '}}'];
    },
    'json-keys-nested' => static function (): array {
        $json = '{"cards":[{"name":"t","labels":{';
        for ($i = 1; $i <= 1_400_000; ++$i) {
            $json .= '"' . dechex($i) . '":"",';
        }

        return ['trello', rtrim($json, ',') . '}}]}'];
    },
    // Audit EB-I7: a realistic ClickUp API export within the default weight, and 5000 of them with the raised limit.
    'clickup-realistic'      => static fn (): array => ['clickup', '{"tasks":[' . implode(',', array_map($clickUpTask, range(1, 2700))) . ']}'],
    'clickup-realistic-5000' => static fn (): array => ['clickup-raised', '{"tasks":[' . implode(',', array_map($clickUpTask, range(1, 5000))) . ']}'],
    // The heaviest shapes per unit, sized just under the default weight: refused or parsed, never a fatal error.
    'worst-lists-at-cap'   => static fn (): array => ['clickup', $atCap(static fn (int $n): string => '[' . rtrim(str_repeat('[0],', $n), ',') . ']')],
    'worst-objects-at-cap' => static fn (): array => ['clickup', $atCap(static fn (int $n): string => '[' . rtrim(str_repeat('{"a":0},', $n), ',') . ']')],
    'worst-strings-at-cap' => static fn (): array => ['trello', $atCap(static fn (int $n): string => '{"cards":[{"name":"t","x":[' . rtrim(str_repeat('"ab",', $n), ',') . ']}]}')],
    'worst-zeros-at-cap'   => static fn (): array => ['trello', $atCap(static fn (int $n): string => '{"cards":[{"name":"t","x":[' . rtrim(str_repeat('0,', $n), ',') . ']}]}')],
    // What the limits still accept: 5000 rows of 20 filled columns, and 5000 ClickUp tasks of 20 fields.
    'normal-json' => static fn (): array => ['clickup', '[' . rtrim(str_repeat('{"name":"Task","status":"to do",' . implode(',', array_map(static fn (int $i): string => '"field' . $i . '":"value ' . $i . '"', range(1, 18))) . '},', 5000), ',') . ']'],
    'normal-csv'  => static fn (): array => ['csv', implode(',', array_map(static fn (int $i): string => 'h' . $i, range(1, 20))) . "\n" . str_repeat(implode(',', array_fill(0, 20, 'value')) . "\n", 5000)],
];

[$kind, $content] = $cases[$argv[1] ?? '']();
try {
    $rows = match ($kind) {
        'csv'            => (new DelimitedTableParser())->parse($content, 'x.csv'),
        'clickup'        => (new ClickUpJsonImporter())->parse($content, 'x.json'),
        'clickup-raised' => (new ClickUpJsonImporter(new ImportFieldMapper(), new JsonImportGuard(maxNodes: 9_500_000)))->parse($content, 'x.json'),
        'trello'         => (new TrelloJsonImporter())->parse($content, 'x.json'),
        'taskboard'      => (new TaskBoardJsonImporter())->parse($content, 'x.json'),
    };
    echo 'PARSED ', count($rows), "\n";
} catch (ImportLimitExceededException $exception) {
    echo 'REFUSED ', $exception->translationKey, "\n";
}
