<?php

declare(strict_types=1);

/*
 * Beacon spec 239, FR-013 (MySQL / PostgreSQL): the "other move" of a lock-order test, on its own connection. It locks
 * the task `first` and says so ("locked" on stdout), waits for the test's move to start, then asks for `second`, prints
 * what happened ("other: got the lock" or "other: <the driver's error>") and rolls back.
 *
 * Arguments: database URL, first task id, second task id, wait before the second lock (microseconds).
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $first, $second, $wait] = $argv;
$connection                      = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
$connection->beginTransaction();
$connection->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$first]);
echo "locked\n";
flush();
usleep((int) $wait);

try {
    $connection->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$second]);
    echo 'other: got the lock';
} catch (Throwable $exception) {
    echo 'other: ', $exception->getMessage();
}
usleep(300_000);

try {
    $connection->rollBack();
} catch (Throwable) {
}
$connection->close();
