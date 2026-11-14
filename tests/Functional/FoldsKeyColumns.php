<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;

/**
 * Since 2.15.1 (REQ-SEC-006): recreates the schema with the key columns on a folding collation (SQLite NOCASE; MySQL /
 * MariaDB `utf8mb4_general_ci`, case- and accent-insensitive and PAD SPACE), as a database before the UPGRADING ALTER.
 * PostgreSQL has no built-in folding collation: the test is skipped there.
 */
trait FoldsKeyColumns
{
    private function foldKeyColumns(EntityManagerInterface $em): void
    {
        $platform  = $em->getConnection()->getDatabasePlatform();
        $collation = match (true) {
            $platform instanceof SQLitePlatform        => 'NOCASE',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_general_ci',
            default                                    => null,
        };
        if ($collation === null) {
            self::markTestSkipped('PostgreSQL has no built-in case-insensitive collation; its columns compare exactly.');
        }
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        foreach ($metadata as $class) {
            foreach (TaskBoardMetadataListener::KEY_FIELDS[$class->getName()] ?? [] as $field) {
                $class->fieldMappings[$field]->options = ['collation' => $collation];
            }
        }
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($metadata);
        $em->clear();
    }
}
