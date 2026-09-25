<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskBoardRepository;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecoveringFlusherTest extends TestCase
{
    public function testFlushesWhenNothingFails(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        RecoveringFlusher::flush($em, $registry);
    }

    public function testResetsClosedManagerAndRethrows(): void
    {
        $failure = new RuntimeException('Unique constraint violation');
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($failure);
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([
            'other'      => $this->createMock(EntityManagerInterface::class),
            'task_board' => $em,
        ]);
        $registry->expects(self::once())->method('resetManager')->with('task_board')->willReturn($em);

        $this->expectExceptionObject($failure);
        RecoveringFlusher::flush($em, $registry);
    }

    public function testDoesNotResetOpenManager(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        RecoveringFlusher::resetIfClosed($em, $registry);
    }

    public function testDoesNothingWhenManagerIsNotRegisteredOrRegistryMissing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $this->createMock(EntityManagerInterface::class)]);
        $registry->expects(self::never())->method('resetManager');

        RecoveringFlusher::resetIfClosed($em, $registry);
        RecoveringFlusher::resetIfClosed($em, null);
    }

    public function testRepositoryRecoversSoTheNextRequestCanSave(): void
    {
        $open    = true;
        $flushes = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em->expects(self::exactly(2))->method('flush')->willReturnCallback(static function () use (&$open, &$flushes): void {
            if (!$open) {
                throw new RuntimeException('The EntityManager is closed.');
            }

            if (++$flushes === 1) {
                $open = false;

                throw new RuntimeException('Duplicate slug');
            }
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use (&$open, $em): EntityManagerInterface {
            $open = true;

            return $em;
        });

        $repository = new DoctrineOrmTaskBoardRepository($em, $registry);
        $owner      = new TestUser('1', 'owner@example.com');

        try {
            $repository->save(new TaskBoard('Demo', 'demo', $owner));
            self::fail('The flush exception must be rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('Duplicate slug', $exception->getMessage());
        }

        $repository->save(new TaskBoard('Other', 'other', $owner));
        self::assertSame(2, $flushes);
    }
}
