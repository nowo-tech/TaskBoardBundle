<?php

declare(strict_types=1);

/*
 * Beacon spec 239, FR-013 (MySQL / PostgreSQL): a concurrent write through the real `TaskManager` in its own process and
 * kernel. It opens a transaction, locks the column's row (as a move does first), says so ("locked" on stdout), waits so
 * that the test's write reads the column and then waits on that lock, writes, commits and prints "moved".
 *
 * Arguments: mode, task id, column id, [position,] wait before the write (microseconds). Modes:
 *  - move:  TaskManager::move() of the task to the position (renumbers the column);
 *  - enter: TaskManager::moveToColumn() of the task (the column's next free position).
 */

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$mode = $argv[1];
if ($mode === 'move') {
    [, , $taskId, $columnId, $position, $wait] = $argv;
} else {
    [, , $taskId, $columnId, $wait] = $argv;
    $position                       = null;
}
$kernel = new FunctionalKernel();
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em        = $container->get(PrivateEntityManagerReplay::enabled() && $container->has('app.task_board_em') ? 'app.task_board_em' : 'doctrine.orm.entity_manager');
assert($em instanceof EntityManagerInterface);
$manager = $container->get(TaskManager::class);
assert($manager instanceof TaskManager);

$connection = $em->getConnection();
$connection->beginTransaction();
$connection->fetchOne('SELECT id FROM task_board_columns WHERE id = ? FOR UPDATE', [$columnId]);
// Straight to the stream (no output buffer a kernel may have opened), so the test reads it now.
fwrite(\STDOUT, "locked\n");
fflush(\STDOUT);
usleep((int) $wait);

$task = $em->find(Task::class, $taskId);
assert($task instanceof Task);
$actor = $task->getCreator() ?? $task;
if ($position === null) {
    $manager->moveToColumn($task, $columnId, $actor);
} else {
    $manager->move($task, $columnId, (int) $position, $actor);
}
$connection->commit();
fwrite(\STDOUT, 'moved');
$kernel->shutdown();
