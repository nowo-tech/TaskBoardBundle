<?php

declare(strict_types=1);

/*
 * Since 2.31.0 (REQ-RUNTIME-003): the other person's save of TaskEditFormRaceTest, in its own process and connection.
 * argv: database URL, task id, new title, seconds to hold the row. Prints "locked" once it holds the task's row, then
 * "committed".
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $url, $taskId, $title, $seconds] = $argv;
$connection                                = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
$connection->beginTransaction();
$connection->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$taskId]);
$connection->executeStatement('UPDATE task_board_tasks SET title = ?, revision = revision + 1 WHERE id = ?', [$title, $taskId]);
echo "locked\n";
usleep((int) ((float) $seconds * 1_000_000));
$connection->commit();
$connection->close();
echo "committed\n";
