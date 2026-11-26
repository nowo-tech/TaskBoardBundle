<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonAnonymisedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPersonForgetCheckEvent;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;
use Throwable;

use function array_replace_recursive;

/**
 * Since 3.4.0 (Beacon spec 208 E, owner decision 2026-11-26): "Forget this person" on the People page — only with
 * `sync.people_forget`, for a manager of the board's connection whom a `SYNC_PERSON_FORGET_CHECK` listener allows
 * (deny by default), behind its own CSRF token and a confirm dialog; idempotent; audited by id.
 */
final class ForgetPersonTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    /** @var list<SyncPersonForgetCheckEvent> */
    private array $checks = [];

    /** @var list<SyncPersonAnonymisedEvent> */
    private array $forgotten = [];

    private bool $allow = true;

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['people_forget' => true]]);
    }

    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        parent::setUp();
        $this->checks    = [];
        $this->forgotten = [];
        $this->allow     = true;
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_PERSON_FORGET_CHECK, function (SyncPersonForgetCheckEvent $event): void {
            $this->checks[] = $event;
            $event->allow();
            if (!$this->allow) {
                // A deny sticks, whatever another listener allowed.
                $event->deny();
            }
        });
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_PERSON_ANONYMISED, function (SyncPersonAnonymisedEvent $event): void {
            $this->forgotten[] = $event;
        });
    }

    public function testAnAllowedManagerForgetsAPersonOnceBehindTheDialog(): void
    {
        $board  = $this->linked();
        $person = $this->freshPerson('1');
        $this->login($this->owner);

        $page = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="nowo-task-sync-person-forget"]'));
        self::assertCount(1, $page->filter('#nowo-task-sync-person-forget-' . $person->getId() . ' [data-testid="nowo-task-sync-person-forget-confirm"]'));
        self::assertNull($this->checks[0]->personId, 'the page asks once, without a person');
        self::assertSame($board->getId(), $this->checks[0]->boardId);

        // Another action's token is refused.
        $this->forget($board, $person, 'task_board_connection_people_former');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->freshPerson('1')->isAnonymised());

        $this->forget($board, $person);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection/people?filter=all&page=1');
        self::assertTrue($this->freshPerson('1')->isAnonymised());
        self::assertSame($person->getId(), $this->checks[1]->personId);
        self::assertCount(1, $this->forgotten);
        self::assertSame([$person->getId(), (string) $this->owner->getId()], [$this->forgotten[0]->personId, $this->forgotten[0]->actorId]);
        // The bundle's own lines (a DBAL 3 logger also logs every query of the test's fixtures).
        $records = (string) json_encode(array_values(array_filter($this->logger()->records, static fn (array $record): bool => str_contains((string) ($record['message'] ?? ''), 'TaskBoard'))));
        self::assertStringNotContainsString('one@remote.test', $records, 'logged by id only');

        // Idempotent: again, the same answer and no second event.
        $this->forget($board, $person);
        self::assertResponseRedirects();
        self::assertCount(1, $this->forgotten);

        $page = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        // A forgotten person is no longer listed (it can no longer be mapped).
        self::assertCount(0, $page->filter('[data-testid="nowo-task-sync-person-row"]'));
    }

    public function testDeniedByDefaultTheActionIsNotOfferedAndRefused(): void
    {
        $this->allow = false;
        $board       = $this->linked();
        $person      = $this->freshPerson('1');
        $this->login($this->owner);
        $page = $this->client->request('GET', self::BASE . $board->getId() . '/connection/people');
        self::assertCount(0, $page->filter('[data-testid="nowo-task-sync-person-forget"]'));
        $this->forget($board, $person);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->freshPerson('1')->isAnonymised());
    }

    public function testAStrangerAndAnotherDirectorysPersonAreNotFound(): void
    {
        $board  = $this->linked();
        $person = $this->freshPerson('1');
        $this->login($this->viewer('stranger@example.test'));
        $this->forget($board, $person);
        self::assertResponseStatusCodeSame(404);

        $this->login($this->owner);
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/unknown-person/forget', ['task_board_sync_people_forget' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people_forget')]]);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->freshPerson('1')->isAnonymised());
    }

    /**
     * REQ-RUNTIME-003: the directory's row is locked and read again before its counter numbers the former person, so a
     * forget committed by another process meanwhile (here: its counter written on another connection) is never numbered
     * twice; a lost race on the person (forgotten meanwhile) changes nothing and dispatches nothing.
     */
    public function testTheCounterIsReadUnderTheLockAndAPersonForgottenMeanwhileIsLeftAlone(): void
    {
        $this->linked();
        $person    = $this->freshPerson('1');
        $directory = $person->getDirectory();
        $before    = (int) $this->em->getConnection()->fetchOne('SELECT anonymous_counter FROM task_board_sync_directories WHERE id = ?', [$directory->getId()]);
        // Another process forgot someone of the same directory after this one loaded it.
        $this->em->getConnection()->executeStatement('UPDATE task_board_sync_directories SET anonymous_counter = anonymous_counter + 5 WHERE id = ?', [$directory->getId()]);

        $service = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $service);
        $service->anonymise($person, $this->owner);
        self::assertSame(TaskSyncPerson::ANONYMOUS_PREFIX . ($before + 6), $this->freshPerson('1')->getName());
        self::assertCount(1, $this->forgotten);

        // Forgotten meanwhile by another process: the loaded copy is stale, the row says it is done.
        $this->linkedSecond();
        $second = $this->freshPerson('2');
        $this->em->getConnection()->executeStatement('UPDATE task_board_sync_people SET anonymised_at = ?, name = ? WHERE id = ?', ['2026-01-01 00:00:00', 'former:99', $second->getId()]);
        $service->anonymise($second, $this->owner);
        self::assertSame('former:99', $this->freshPerson('2')->getName());
        self::assertCount(1, $this->forgotten);
    }

    public function testAFailureRollsBackAndForgetIsIdempotent(): void
    {
        $this->linked();
        $person  = $this->freshPerson('1');
        $service = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $service);
        $level = $this->em->getConnection()->getTransactionNestingLevel();
        // The people's table fails under the lock (a lost connection, a dropped table): rolled back, rethrown.
        $this->em->getConnection()->executeStatement('ALTER TABLE task_board_sync_people RENAME TO task_board_sync_people_away');
        $failed = null;
        try {
            $service->anonymise($person, $this->owner);
        } catch (Throwable $failure) {
            $failed = $failure;
        }
        $this->em->getConnection()->executeStatement('ALTER TABLE task_board_sync_people_away RENAME TO task_board_sync_people');
        self::assertNotNull($failed);
        self::assertSame($level, $this->em->getConnection()->getTransactionNestingLevel(), 'back to the entry level');
        self::assertCount(0, $this->forgotten);

        $this->linkedSecond();
        $second = $this->freshPerson('2');
        $service->forget($second, null);
        $service->forget($second, null);
        self::assertCount(1, $this->forgotten);
    }

    private function linkedSecond(): void
    {
        $board = $this->boardWithColumns('Two');
        $this->workspace([new RemotePerson('1', 'One', 'one@remote.test', RemoteRole::Member), new RemotePerson('2', 'Two', 'two@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t2', 'Two task', 1, assignees: [self::ref('2', 'two@remote.test', 'Two')]));
        $this->sync($board);
    }

    private function linked(): TaskBoard
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'One', 'one@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One task', 1, assignees: [self::ref('1', 'one@remote.test', 'One')]));
        $this->sync($board);

        return $board;
    }

    private function forget(TaskBoard $board, TaskSyncPerson $person, string $tokenId = 'task_board_connection_people_forget'): void
    {
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/people/' . $person->getId() . '/forget?filter=all&page=1', ['task_board_sync_people_forget' => ['_token' => PredictableCsrfTokenManager::value($tokenId)]]);
    }

    private function freshPerson(string $providerUserId): TaskSyncPerson
    {
        $this->em->clear();
        $this->refreshOwner();

        return $this->personOf($providerUserId);
    }
}
