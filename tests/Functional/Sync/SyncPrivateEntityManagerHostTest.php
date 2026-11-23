<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * Since 2.27.0 (REQ-RUNTIME-003 rule 2): with `database.private_entity_manager: true` a remote sync (pages, dry-run
 * rollback, reloads) never touches the application's entity manager: its entities stay managed and the changes it left
 * pending are still pending, unwritten, until the application flushes them.
 */
final class SyncPrivateEntityManagerHostTest extends SyncFunctionalTestCase
{
    private EntityManagerInterface $host;

    protected static function syncConfig(): array
    {
        return self::SYNC_CONFIG + [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $host    = self::getContainer()->get('doctrine.orm.entity_manager');
        $private = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $private);
        $this->host = $host;
        // The fixtures go through the bundle's manager, as a host goes through the bundle's services.
        $this->em = $private;
        $this->refreshOwner();
    }

    public function testTheApplicationManagerIsUntouchedByASync(): void
    {
        $owner = $this->host->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $bystander = new AppUser('bystander@example.com');
        $this->host->persist($bystander);
        $this->host->flush();
        $bystander->setDisplayName('Edited by the application');
        $added = new AppUser('added@example.com');
        $this->host->persist($added);
        $board = $this->board('Remote');
        $this->put($this->remote('r1', 'One'), $this->remote('r2', 'Two'), $this->remote('r3', 'Three'));

        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status);
        $this->assertUntouched($owner, $bystander, $added);

        $this->put($this->remote('r1', 'One edited', 2));
        $report = $this->sync($board, dryRun: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame(1, $report->updated, 'the dry run planned the edit');
        $this->assertUntouched($owner, $bystander, $added);
        self::assertSame(3, (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host->getClassMetadata(Task::class)->getTableName() . ' WHERE board_id = ?', [$board->getId()]));

        $this->host->flush();
        self::assertSame('Edited by the application', $this->displayName('bystander@example.com'), 'the application writes its own changes');
        self::assertSame(1, $this->users('added@example.com'));
    }

    private function assertUntouched(AppUser $owner, AppUser $bystander, AppUser $added): void
    {
        $unitOfWork = $this->host->getUnitOfWork();
        self::assertTrue($this->host->contains($owner), 'the application user stays managed');
        self::assertTrue($this->host->contains($bystander));
        self::assertTrue($unitOfWork->isScheduledForInsert($added), 'the pending insert is still pending');
        $unitOfWork->computeChangeSets();
        self::assertArrayHasKey('displayName', $unitOfWork->getEntityChangeSet($bystander), 'the pending update is still pending');
        self::assertNull($this->displayName('bystander@example.com'), 'never written by the sync');
        self::assertSame(0, $this->users('added@example.com'));
    }

    private function displayName(string $email): mixed
    {
        return $this->host->getConnection()->fetchOne('SELECT display_name FROM ' . $this->host->getClassMetadata(AppUser::class)->getTableName() . ' WHERE email = ?', [$email]);
    }

    private function users(string $email): int
    {
        return (int) $this->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host->getClassMetadata(AppUser::class)->getTableName() . ' WHERE email = ?', [$email]);
    }
}
