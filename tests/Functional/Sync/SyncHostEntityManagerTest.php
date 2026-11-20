<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;

/**
 * Since 2.26.1 (REQ-RUNTIME-003 rule 2): without `database.private_entity_manager` (the 2.x default) a remote sync
 * writes through the application's entity manager. It keeps its memory flat by detaching the TaskBoard rows after each
 * page and after a dry-run rollback, never by clearing that manager: the application's entities stay managed and keep
 * their changes. The changes the application left pending are written by the sync's flush (the deprecated 2.14
 * behaviour the private manager removes) instead of being dropped.
 */
final class SyncHostEntityManagerTest extends SyncFunctionalTestCase
{
    public function testTheApplicationManagerKeepsItsEntitiesAndChangesAcrossASync(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private-manager replay turns the option on (see SyncPrivateEntityManagerHostTest).');
        }
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertSame($host, $this->em, 'the sync writes through the application manager');
        $board = $this->board('Remote');
        $local = $this->task($board, 'Local');
        $this->put($this->remote('r1', 'One'), $this->remote('r2', 'Two'), $this->remote('r3', 'Three'));
        $bystander = $this->user('bystander@example.com');
        $bystander->setDisplayName('Edited by the application');
        $added = new AppUser('added@example.com');
        $host->persist($added);
        $owner = $this->owner;

        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        $this->assertApplicationEntitiesKept($host, $owner, $bystander, $added);
        self::assertFalse($host->contains($local), 'the TaskBoard rows leave the manager (memory stays flat)');
        self::assertFalse($host->contains($board));
        self::assertSame(4, $this->tasks($host, $board));

        // A dry run writes the page, rolls it back and detaches its TaskBoard rows only.
        $this->put($this->remote('r1', 'One edited', 2));
        $bystander->setDisplayName('Edited again');
        $report = $this->sync($board, dryRun: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame(1, $report->updated, 'the dry run planned the edit');
        $this->assertApplicationEntitiesKept($host, $owner, $bystander, $added);
        self::assertSame('Edited again', $bystander->getDisplayName());
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $host->getClassMetadata(Task::class)->getTableName() . ' WHERE title = ?', ['One edited']), 'nothing of the dry run is kept');

        // The deprecated shared mode: the sync's flushes wrote the application's pending changes, never dropped them.
        self::assertSame('Edited again', $host->getConnection()->fetchOne('SELECT display_name FROM ' . $host->getClassMetadata(AppUser::class)->getTableName() . ' WHERE email = ?', ['bystander@example.com']));
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $host->getClassMetadata(AppUser::class)->getTableName() . ' WHERE email = ?', ['added@example.com']));
    }

    private function assertApplicationEntitiesKept(EntityManagerInterface $host, AppUser $owner, AppUser $bystander, AppUser $added): void
    {
        self::assertTrue($host->isOpen());
        self::assertSame($owner, $this->owner, 'the actor is the same managed instance');
        self::assertTrue($host->contains($owner), 'the actor stays managed');
        self::assertTrue($host->contains($bystander), 'an application entity stays managed');
        self::assertTrue($host->contains($added), 'an entity the application persisted stays managed');
    }

    private function tasks(EntityManagerInterface $host, TaskBoard $board): int
    {
        return (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $host->getClassMetadata(Task::class)->getTableName() . ' WHERE board_id = ?', [$board->getId()]);
    }
}
