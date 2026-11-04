<?php

declare(strict_types=1);

/*
 * Since 1.22.1 (audit EB-L6): a JSON import posted through the real board-import controller (kernel, firewall, CSRF,
 * form, orchestrator, SQLite), so the request's own memory counts. Run by ImportControllerMemoryTest in a separate
 * process: php -d upload_max_filesize=25M -d post_max_size=30M import-controller.php <case> <memory_limit>
 * The file is built first; memory_limit is set right before the request. Prints "HTTP <status> tasks=<n> <flash>" or
 * "FATAL <message>".
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

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
$cases     = [
    // A packed list grows by doubling and holds both tables while it copies: 2^22 + 1 zeros reach a 2^23 table.
    'list-2^22+1'      => static fn (): array => ['trello_json', '{"cards":[{"name":"t","x":[' . $zeros(4_194_305) . ']}]}'],
    'two-lists-2^21+1' => static fn (): array => ['trello_json', '{"cards":[{"name":"t","x":[' . $zeros(2_097_153) . '],"y":[' . $zeros(2_097_153) . ']}]}'],
    'clickup-zeros'    => static fn (): array => ['clickup_json', '[' . $zeros(4_194_305) . ']'],
    // The realistic export the default weight takes at 128 MB end to end, and one just above it.
    'realistic-1200' => static fn (): array => ['clickup_json', $realistic(1200)],
    'realistic-1250' => static fn (): array => ['clickup_json', $realistic(1250)],
];
[$source, $content] = $cases[$argv[1] ?? '']();
$path               = (string) tempnam(sys_get_temp_dir(), 'tb-import');
file_put_contents($path, $content);
unset($content);

$kernel = new FunctionalKernel();
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

gc_collect_cycles();
ini_set('memory_limit', $argv[2] ?? '128M');
$client->request('POST', '/tools/task-board/board/' . $board->getId() . '/import', ['task_import_form' => ['source' => $source, '_token' => PredictableCsrfTokenManager::value('task_import_form')]], ['task_import_form' => ['file' => new UploadedFile($path, 'export.json', 'application/json', null, true)]]);
$status = $client->getResponse()->getStatusCode();
$flash  = '';
if ($status === 302) {
    $flash = trim((string) ($client->followRedirect()->filter('[role=alert], .alert')->each(static fn ($node): string => $node->text())[0] ?? ''));
}
@unlink($path);
$tasks = $em->getRepository(Task::class)->count([]);
echo 'HTTP ', $status, ' tasks=', $tasks, ' ', substr($flash, 0, 120), "\n";
