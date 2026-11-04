<?php

declare(strict_types=1);

/*
 * Since 1.22.1 (audit EB-L6): an import posted through the real board-import controller (kernel, firewall, CSRF, form,
 * orchestrator, SQLite), so the request's own memory counts. Run by ImportControllerMemoryTest in a separate process:
 * php -d upload_max_filesize=25M -d post_max_size=30M import-controller.php <case> <memory_limit>
 * The file is built first; memory_limit is set right before the request. The kernel runs as in production (no query
 * logging or profiling). Prints "HTTP <status> tasks=<n> links=<n> history=<n> <flash>" or "FATAL <message>".
 * Since 1.22.2 (audit EB-L7) also 5000-row CSV and Trello imports, and an import that fails in its middle.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

putenv('TASK_BOARD_TEST_DEBUG=0');

ini_set('memory_limit', '2G');
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && str_contains($error['message'], 'memory')) {
        echo 'FATAL ', $error['message'], "\n";
    }
});

$zeros = static fn (int $count): string => rtrim(str_repeat('0,', $count), ',');
// A ClickUp API task with the field set of a real export, as the auditor measured it (EB-I7, EB-L6).
$task      = static fn (int $i): string => '{"id":"86' . $i . 'abc","custom_id":null,"name":"Task ' . $i . '","text_content":"' . str_repeat('Some description text. ', 6) . '","description":"' . str_repeat('Some description text. ', 6) . '","status":{"id":"sc1","status":"in progress","color":"#d3d3d3","orderindex":1,"type":"custom"},"orderindex":"1.000","date_created":"1793534400000","date_updated":"1793534400000","date_closed":null,"date_done":null,"archived":false,"creator":{"id":1,"username":"Ada","color":"#000","email":"ada@example.test","profilePicture":null},"assignees":[{"id":1,"username":"Ada","color":"#000","initials":"A","email":"ada@example.test","profilePicture":null}],"watchers":[],"checklists":[],"tags":[{"name":"bug","tag_fg":"#fff","tag_bg":"#f00","creator":1}],"parent":null,"priority":{"id":"2","priority":"high","color":"#ffcc00","orderindex":"2"},"due_date":"1793534400000","start_date":null,"points":null,"time_estimate":3600000,"custom_fields":[' . implode(',', array_map(static fn (int $k): string => '{"id":"cf' . $k . '","name":"Field ' . $k . '","type":"short_text","type_config":{},"date_created":"1","hide_from_guests":false,"required":false}', range(1, 5))) . '],"dependencies":[],"linked_tasks":[],"team_id":"1","url":"https://app.clickup.com/t/86' . $i . 'abc","sharing":{"public":false,"public_share_expires_on":null,"public_fields":["assignees","priority"],"token":null,"seo_optimized":false},"permission_level":"create","list":{"id":"1","name":"List","access":true},"project":{"id":"1","name":"Folder","hidden":false,"access":true},"folder":{"id":"1","name":"Folder","hidden":false,"access":true},"space":{"id":"1"}}';
$realistic = static fn (int $count): string => '{"tasks":[' . implode(',', array_map($task, range(1, $count))) . ']}';
$csv       = static fn (int $count): string => "Task ID,Task Name,Status,Priority,Assignees,Due Date,Time Estimate,Tags,Task Content\n" . implode('', array_map(static fn (int $i): string => '86' . $i . 'abc,Task ' . $i . ',in progress,high,ada@example.test,2030-01-0' . ($i % 9 + 1) . ',1h,bug,"' . str_repeat('Some description text. ', 6) . "\"\n", range(1, $count)));
$hex       = static fn (int $i): string => substr(md5((string) $i), 0, 24);
$card      = static fn (int $i): string => '{"id":"' . $hex($i) . '","badges":{"votes":0,"comments":2,"description":true,"due":null},"checkItemStates":[],"closed":false,"dateLastActivity":"2026-10-01T10:00:00.000Z","desc":"' . str_repeat('Lorem ipsum dolor sit amet. ', 8) . '","due":null,"idBoard":"' . $hex(0) . '","idChecklists":["' . $hex($i + 1_000_000) . '"],"idLabels":["' . $hex(7) . '"],"idList":"' . $hex(3) . '","idMembers":["' . $hex(9) . '"],"idShort":' . $i . ',"labels":[{"id":"' . $hex(7) . '","name":"Bug","color":"red"}],"name":"Card ' . $i . '","pos":16384,"shortUrl":"https://trello.com/c/abcd1234","url":"https://trello.com/c/abcd1234/' . $i . '-card"}';
$checklist = static fn (int $i): string => '{"id":"' . $hex($i + 1_000_000) . '","name":"Checklist","idCard":"' . $hex($i) . '","checkItems":[' . implode(',', array_map(static fn (int $k): string => '{"id":"' . $hex($i * 10 + $k + 3_000_000) . '","name":"Item ' . $k . '","state":"incomplete"}', range(1, 3))) . ']}';
$trello    = static fn (int $count): string => '{"id":"' . $hex(0) . '","name":"Board","lists":[{"id":"' . $hex(3) . '","name":"in progress"}],"members":[{"id":"' . $hex(9) . '","fullName":"Ada Lovelace","username":"ada"}],"cards":[' . implode(',', array_map($card, range(1, $count))) . '],"checklists":[' . implode(',', array_map($checklist, range(1, $count))) . ']}';
$cases     = [
    // A packed list grows by doubling and holds both tables while it copies: 2^22 + 1 zeros reach a 2^23 table.
    'list-2^22+1'      => static fn (): array => ['trello_json', '{"cards":[{"name":"t","x":[' . $zeros(4_194_305) . ']}]}'],
    'two-lists-2^21+1' => static fn (): array => ['trello_json', '{"cards":[{"name":"t","x":[' . $zeros(2_097_153) . '],"y":[' . $zeros(2_097_153) . ']}]}'],
    'clickup-zeros'    => static fn (): array => ['clickup_json', '[' . $zeros(4_194_305) . ']'],
    // Since 1.22.2: 5000 rows (the default max_rows) of CSV and of Trello JSON, and an import that fails in its middle.
    'csv-5000'    => static fn (): array => ['clickup_csv', $csv(5000)],
    'trello-5000' => static fn (): array => ['trello_json', $trello(5000)],
    'csv-failure' => static fn (): array => ['clickup_csv', $csv(1000)],
    'own-5000'    => static fn (): array => ['taskboard_json', '{"tasks":[' . implode(',', array_map(static fn (int $i): string => '{"id":"t' . $i . '","title":"Task ' . $i . '","status":"in progress","priority":"high","assignee":"ada@example.test","due":"2030-01-05","estimate":"1h","tags":["bug"],"description":"' . str_repeat('Some description text. ', 6) . '"}', range(1, 5000))) . ']}'],
    // A 5000-task ClickUp API export with the documented settings (max_json_nodes 7 500 000, memory_limit 256M).
    'clickup-5000-raised' => static fn (): array => ['clickup_json', $realistic(5000)],
    // The realistic export the default weight takes at 128 MB end to end, and one just above it.
    'realistic-2700' => static fn (): array => ['clickup_json', $realistic(2700)],
    'realistic-2750' => static fn (): array => ['clickup_json', $realistic(2750)],
];
[$source, $content] = $cases[$argv[1] ?? '']();
$path               = (string) tempnam(sys_get_temp_dir(), 'tb-import');
file_put_contents($path, $content);
unset($content);

$kernel = new FunctionalKernel(str_ends_with($argv[1] ?? '', '-raised') ? ['import' => ['max_json_nodes' => 7_500_000]] : []);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$client    = $container->get('test.client');
assert($client instanceof KernelBrowser);
$client->disableReboot();
$em = $container->get('doctrine.orm.entity_manager');
assert($em instanceof EntityManagerInterface);
(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
$owner = new AppUser('owner@example.com', ['ROLE_USER']);
$board = new TaskBoard('Import', 'import', $owner);
$board->addColumn(new BoardColumn($board, 'To do', 0));
$board->addColumn(new BoardColumn($board, 'in progress', 1));
$em->persist($owner);
$em->persist($board);
$em->flush();
$client->loginUser($owner);
if (($argv[1] ?? '') === 'csv-failure') {
    // The third flush (a batch in the middle of the import) fails: nothing of the import may be left behind.
    $em->getEventManager()->addEventListener(Events::onFlush, new class {
        private int $flushes = 0;

        public function onFlush(): void
        {
            if (++$this->flushes === 3) {
                throw new RuntimeException('Injected failure in the middle of the import.');
            }
        }
    });
}

gc_collect_cycles();
ini_set('memory_limit', $argv[2] ?? '128M');
$client->request('POST', '/tools/task-board/board/' . $board->getId() . '/import', ['task_import_form' => ['source' => $source, '_token' => PredictableCsrfTokenManager::value('task_import_form')]], ['task_import_form' => ['file' => new UploadedFile($path, str_starts_with($source, 'clickup_csv') ? 'export.csv' : 'export.json', str_starts_with($source, 'clickup_csv') ? 'text/csv' : 'application/json', null, true)]]);
$status = $client->getResponse()->getStatusCode();
$flash  = '';
if ($status === 302) {
    $flash = trim((string) ($client->followRedirect()->filter('[role=alert], .alert')->each(static fn ($node): string => $node->text())[0] ?? ''));
}
@unlink($path);
$connection = $em->getConnection();
$count      = static fn (string $table): string => (string) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
echo 'HTTP ', $status, ' tasks=', $count('task_board_tasks'), ' links=', $count('task_board_task_links'), ' history=', $count('task_board_task_change_history'), ' ', substr($flash, 0, 120), "\n";
