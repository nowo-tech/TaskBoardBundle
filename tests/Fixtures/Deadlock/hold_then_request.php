<?php

declare(strict_types=1);

/*
 * Audit PX-M1 (MySQL only): the "other process" of a real InnoDB deadlock. It writes rows (so InnoDB picks the test's
 * request as the victim), takes the row it holds (`UPDATE … WHERE id = held`), waits for the request to lock its first
 * row, then asks for that row (`wanted`) and rolls back.
 *
 * Arguments: host port dbname user password table heldColumn heldId wantedId fillIds(comma-separated task ids)
 */

[, $host, $port, $db, $user, $pass, $table, $column, $held, $wanted, $fill] = $argv;
$pdo                                                                        = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
foreach (array_filter(explode(',', $fill)) as $id) {
    $pdo->prepare('UPDATE task_board_tasks SET title = CONCAT(title, ?) WHERE id = ?')->execute(['!', $id]);
}
$pdo->prepare('UPDATE ' . $table . ' SET ' . $column . ' = ' . $column . ' WHERE id = ?')->execute([$held]);
$pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = ? FOR UPDATE')->execute([$held]);
usleep(1_500_000);
try {
    $pdo->prepare('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE')->execute([$wanted]);
    echo 'other: got the lock';
} catch (PDOException $exception) {
    echo 'other: ' . $exception->getMessage();
}
usleep(300_000);
try {
    $pdo->rollBack();
} catch (Throwable) {
}
