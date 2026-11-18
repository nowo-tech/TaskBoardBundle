<?php

declare(strict_types=1);

/*
 * Audit TE-2 (REQ-RUNTIME-003): the lock sequence of TaskQuestionManager::ask() — the task's row, then the task's open
 * questions — on a connection of its own, in a separate process. Prints "locked" once the task's row is held, waits,
 * then takes the open questions and prints "ask: counted <n>" (or the error). Run by TaskQuestionConcurrencyTest.
 *
 * Usage: php ask_locks.php <database url> <task id> <wait in microseconds>
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $task, $wait] = $argv;
$connection            = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
$connection->executeStatement(str_starts_with($url, 'postgresql') ? "SET lock_timeout = '10s'" : 'SET SESSION innodb_lock_wait_timeout = 10');
$connection->beginTransaction();
$connection->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$task]);
fwrite(\STDOUT, "locked\n");
fflush(\STDOUT);
usleep((int) $wait);
try {
    $open = count($connection->fetchAllAssociative("SELECT id FROM task_board_task_questions WHERE task_id = ? AND state = 'open' FOR UPDATE", [$task]));
    echo 'ask: counted ' . $open . "\n";
} catch (Throwable $failure) {
    echo 'ask: ' . $failure->getMessage() . "\n";
}
try {
    $connection->rollBack();
} catch (Throwable) {
}
