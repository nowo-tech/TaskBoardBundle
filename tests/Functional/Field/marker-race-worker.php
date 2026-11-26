<?php

declare(strict_types=1);

/*
 * Since 3.3.0 (audit ZCF-L3): another import creating the board's field of one marker, in its own process and connection.
 * argv: database URL, board id, marker, seconds to hold the board's row. Prints "locked" once it holds the row (its field
 * inserted, not committed), then "committed".
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $url, $boardId, $marker, $seconds] = $argv;
$connection                                  = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
$connection->beginTransaction();
$connection->fetchOne('SELECT id FROM task_board_boards WHERE id = ? FOR UPDATE', [$boardId]);
$connection->executeStatement("INSERT INTO task_board_custom_fields (id, created_at, name, options, field_type, tenant_key, board_id, position, source_ref, source_options, version) VALUES ('00000000-0000-4000-8000-0000000000ff', '2026-11-26 10:00:00', 'Theirs', '[]', 'text', NULL, ?, 7, ?, NULL, 1)", [$boardId, $marker]);
echo "locked\n";
usleep((int) ((float) $seconds * 1_000_000));
$connection->commit();
$connection->close();
echo "committed\n";
