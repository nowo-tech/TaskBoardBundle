<?php

declare(strict_types=1);

/*
 * Since 2.31.0 (REQ-RUNTIME-003): the other person's save of CustomFieldEditRaceTest, in its own process and connection.
 * argv: database URL, task id, field id, new text value, seconds to hold the task's row. Prints "locked" once it holds
 * the row, then "committed".
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $url, $taskId, $fieldId, $value, $seconds] = $argv;
$connection                                          = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
$connection->beginTransaction();
$connection->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$taskId]);
$connection->executeStatement('UPDATE task_board_custom_field_values SET text_value = ? WHERE task_id = ? AND field_id = ?', [$value, $taskId, $fieldId]);
echo "locked\n";
usleep((int) ((float) $seconds * 1_000_000));
$connection->commit();
$connection->close();
echo "committed\n";
