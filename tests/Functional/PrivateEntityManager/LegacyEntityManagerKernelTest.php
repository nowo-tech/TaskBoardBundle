<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PrivateEntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;

/**
 * Since 2.15.0: without `database.private_entity_manager` (the default) TaskBoard writes through
 * `database.entity_manager` exactly as 2.14 did, so a TaskBoard save also flushes what the application left pending
 * there. This is the behaviour the option removes; it becomes the only mode in 3.0.
 */
final class LegacyEntityManagerKernelTest extends FunctionalTestCase
{
    public function testTheDefaultFlushesTheApplicationManagerAs214Did(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private-manager replay turns the option on.');
        }
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $host->persist(new AppUser('pending@example.com'));
        $creator = self::getContainer()->get(TaskBoardCreatorInterface::class);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);

        $creator->create(new TaskBoardFormData('Legacy', 'legacy'), $this->owner);

        $table = $host->getClassMetadata(AppUser::class)->getTableName();
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE email = ?', ['pending@example.com']));
    }
}
