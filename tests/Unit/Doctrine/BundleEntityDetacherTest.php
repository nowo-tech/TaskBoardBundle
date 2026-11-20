<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Nowo\TaskBoardBundle\Doctrine\BundleEntityDetacher;
use Nowo\TaskBoardBundle\Doctrine\RolledBackHostRows;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;

/**
 * Since 2.27.0 (audits WA-1, WA-2): the safe fallbacks of the shared-manager detach and of the dry-run forget.
 */
final class BundleEntityDetacherTest extends TestCase
{
    public function testNothingIsDetachedWhenTheRowsToKeepCannotBeWorkedOut(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willThrowException(new RuntimeException('unreadable'));
        $manager->expects(self::never())->method('detach');
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, $context];
            }
        };

        BundleEntityDetacher::detach($manager, $logger);

        self::assertSame([['warning', ['bundle' => 'nowo_task_board', 'exception' => RuntimeException::class]]], $logger->records);
        // Without a logger it is silent.
        BundleEntityDetacher::detach($manager);
    }

    public function testARowThatCannotBeReadAgainLeavesTheManager(): void
    {
        $updated  = new stdClass();
        $inserted = new stdClass();
        $other    = $this->createMock(EntityManagerInterface::class);
        $manager  = $this->createMock(EntityManagerInterface::class);
        $events   = new EventManager();
        $gone     = new stdClass();
        $manager->method('getEventManager')->willReturn($events);
        $manager->method('contains')->willReturnCallback(static fn (object $entity): bool => $entity !== $gone);
        $manager->method('refresh')->willThrowException(new RuntimeException('gone'));
        $detached = [];
        $manager->method('detach')->willReturnCallback(static function (object $entity) use (&$detached): void {
            $detached[] = $entity;
        });

        $recorder = RolledBackHostRows::start($manager);
        self::assertInstanceOf(RolledBackHostRows::class, $recorder);
        $recorder->postPersist(new PostPersistEventArgs($inserted, $manager));
        $recorder->postUpdate(new PostUpdateEventArgs($inserted, $manager));
        $recorder->postUpdate(new PostUpdateEventArgs($updated, $manager));
        // A row no longer managed (detached meanwhile) is left alone.
        $recorder->postUpdate(new PostUpdateEventArgs($gone, $manager));
        // Another manager's rows and TaskBoard rows are not recorded.
        $recorder->postUpdate(new PostUpdateEventArgs(new stdClass(), $other));
        $recorder->postPersist(new PostPersistEventArgs(new TaskBoardFolder('f', 'F'), $manager));
        $recorder->forget();

        self::assertSame([$inserted, $updated], $detached);
        self::assertFalse($events->hasListeners('postPersist'));
        self::assertFalse($events->hasListeners('postUpdate'));
    }
}
