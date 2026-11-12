<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\ManagerOf;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Audit OC-I2: the manager that holds the entity, even when getManagerForClass() names another one (DoctrineBundle 3.4
 * with a dedicated database.entity_manager).
 */
final class ManagerOfTest extends TestCase
{
    public function testTheManagerHoldingTheEntityIsFoundAmongTheRegistrysManagers(): void
    {
        $entity   = new stdClass();
        $default  = $this->manager(false, true);
        $tasks    = $this->manager(true, true);
        $closed   = $this->manager(true, false);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($default);
        $registry->method('getManagers')->willReturn(['closed' => $closed, 'default' => $default, 'tasks' => $tasks]);

        self::assertSame($tasks, ManagerOf::entity($registry, $entity));
        self::assertNull(ManagerOf::entity(null, $entity));

        $none = $this->createStub(ManagerRegistry::class);
        $none->method('getManagerForClass')->willReturn(null);
        $none->method('getManagers')->willReturn(['default' => $default]);
        self::assertNull(ManagerOf::entity($none, $entity), 'no manager holds it: no refresh');
    }

    private function manager(bool $contains, bool $open): EntityManagerInterface
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('contains')->willReturn($contains);
        $manager->method('isOpen')->willReturn($open);

        return $manager;
    }
}
