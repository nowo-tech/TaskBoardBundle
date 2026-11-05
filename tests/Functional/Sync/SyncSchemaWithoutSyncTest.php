<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

use function array_keys;

/**
 * Since 1.25 (§6.4, principle 9): a host without `sync.enabled` sees no schema change — none of the people tables and
 * none of the sync tables they change.
 */
final class SyncSchemaWithoutSyncTest extends FunctionalTestCase
{
    public function testNoPeopleTablesWithoutSync(): void
    {
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata($this->em->getMetadataFactory()->getAllMetadata());

        foreach ([...SyncSchemaPeopleTest::NEW_TABLES, ...array_keys(SyncSchemaPeopleTest::NEW_COLUMNS)] as $table) {
            self::assertFalse($schema->hasTable($table), $table);
        }
        self::assertTrue($schema->hasTable('task_board_tasks'));
    }
}
