<?php

declare(strict_types=1);

/*
 * Spec 214, SC-001: one of several processes reserving numbers in one scope. argv: database URL, scope, reservations.
 * Prints each number it got, one per line.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\TaskBoardBundle\Number\TaskNumberReserver;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $url, $scope, $times] = $argv;
$params                         = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($url);
$connection                     = DriverManager::getConnection($params);
$reserver                       = new TaskNumberReserver();
for ($index = 0; $index < (int) $times; ++$index) {
    // A second range now and then, so ranges of different sizes interleave.
    $count = $index % 5 === 0 ? 2 : 1;
    $first = $reserver->reserve($connection, [$scope => $count])[$scope];
    for ($number = $first; $number < $first + $count; ++$number) {
        echo $number, "\n";
    }
}
