<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\BundleEntityDetacher;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Since 3.0.0: a long batch clears the bundle's private manager, and never touches any other manager (no clear, no
 * detach): the host-manager path of 2.27-2.32 was removed with the application's-manager mode.
 */
final class BundleEntityDetacherTest extends TestCase
{
    public function testAnyOtherManagerIsLeftUntouched(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('clear');
        $manager->expects(self::never())->method('detach');
        $manager->expects(self::never())->method('getUnitOfWork');

        BundleEntityDetacher::detach($manager);
    }

    public function testThePrivateManagerIsCleared(): void
    {
        // Without a live private manager there is nothing to clear, and none is created (the sync tests clear a live one).
        $private = new TaskBoardEntityManager($this->createStub(ManagerRegistry::class));

        BundleEntityDetacher::detach($private);

        self::assertFalse($private->isCreated());
    }
}
