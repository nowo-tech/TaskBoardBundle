<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingDeniedException;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingRow;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingService;
use Nowo\TaskBoardBundle\Sync\People\PeopleProjection;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingPeopleInviter;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;
use Symfony\Component\Clock\NativeClock;

use function array_map;
use function array_replace_recursive;

/**
 * Since 1.27.0 (TB-SYNC-2): {@see PeopleMappingService} edges — every pre-fill source, the refusals of a save, a
 * re-application left pending by its time budget and finished by the next save or sync, and a directory shared with a
 * board the actor may not manage.
 */
final class PeopleMappingServiceTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['people' => ['resolver' => 'app.people_resolver', 'inviter' => 'app.people_inviter']]]);
    }

    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        RecordingPeopleInviter::reset();
        parent::setUp();
    }

    public function testEveryPreFillSource(): void
    {
        $board = $this->board();
        $this->user('sue@example.com');
        $this->user('mapped@example.com');
        $this->user('twin1@example.com')->setDisplayName('Twin');
        $this->user('twin2@example.com')->setDisplayName('Twin');
        $this->em->flush();
        ScriptedPeopleResolver::$candidates = ['sue@example.com', 'mapped@example.com', 'twin1@example.com', 'twin2@example.com'];
        ScriptedPeopleResolver::$unverified = ['sue@example.com'];
        ScriptedPeopleResolver::$verified   = ['mapped@example.com'];
        $this->workspace([
            new RemotePerson('1', 'Sue', 'sue@example.com', RemoteRole::Member),
            new RemotePerson('2', 'Mapped', 'mapped@example.com', RemoteRole::Member),
            new RemotePerson('3', 'Twin', null, RemoteRole::Member),
            new RemotePerson('4', 'Gone', null, RemoteRole::Member),
            new RemotePerson('5', 'Ext', null, RemoteRole::Member),
        ]);
        $this->sync($board);
        $directory = $this->directory();
        $this->directories()->anonymise($this->personOf('4'), $this->owner);
        $this->directories()->changeMapping($this->personOf('5'), TaskSyncPersonMapping::External, null, null, 'external');
        $this->em->flush();

        $service    = $this->mapping();
        $board      = $this->freshBoard($board);
        $candidates = $service->candidates($board);
        $rows       = [];
        foreach ($service->rows($this->directory(), $candidates) as $row) {
            $rows[$row->person->getName()] = $row->prefill;
        }
        self::assertSame(['Ext' => PeopleMappingRow::PREFILL_EXTERNAL, 'Mapped' => PeopleMappingRow::PREFILL_MAPPED, 'Sue' => PeopleMappingRow::PREFILL_EMAIL, 'Twin' => PeopleMappingRow::PREFILL_NONE], $rows, 'two "Twin" candidates: no name pre-fill; the forgotten person is left out');
        self::assertSame($directory->getId(), $this->directory()->getId());
        self::assertSame('jose maria perez', PeopleMappingService::normalizeName("  José-María\tPérez "));
    }

    public function testSaveRefusalsAndInvitations(): void
    {
        $board = $this->board();
        $ana   = $this->viewer('ana@example.com');
        $this->user('zed@example.com');
        ScriptedPeopleResolver::$candidates = ['ana@example.com', 'zed@example.com'];
        ScriptedPeopleResolver::$refuseById = ['zed@example.com'];
        $this->workspace([
            new RemotePerson('1', 'No mail', null, RemoteRole::Member),
            new RemotePerson('2', 'Ana', 'ana@example.com', RemoteRole::Member),
            new RemotePerson('3', 'Zed', 'zed@example.com', RemoteRole::Member),
            new RemotePerson('4', 'Bad mail', 'not-an-email', RemoteRole::Member),
        ]);
        $this->sync($board);
        $board  = $this->freshBoard($board);
        $result = $this->mapping()->save($this->directory(), $board, [
            'nope'                        => '',
            $this->personOf('1')->getId() => PeopleMappingService::INVITE,
            $this->personOf('4')->getId() => PeopleMappingService::INVITE,
            $this->personOf('2')->getId() => PeopleMappingService::INVITE,
            $this->personOf('3')->getId() => (string) $this->userId('zed@example.com'),
        ], $this->owner);
        self::assertSame(['unknown_person', 'invite_unavailable', 'invite_unavailable', 'not_candidate'], $result->errors);
        self::assertSame(['ana@example.com'], RecordingPeopleInviter::$invited, 'the invited e-mail had an account: mapped at once');
        self::assertCount(1, $result->changes);
        self::assertSame((string) $ana->getId(), $result->changes[0]['userId']);
        self::assertSame(TaskSyncPersonMapping::Manual, $this->personOf('2')->getMapping());

        // A value that is no candidate at all.
        $result = $this->mapping()->save($this->directory(), $this->freshBoard($board), [$this->personOf('1')->getId() => '999999'], $this->owner);
        self::assertSame(['not_candidate'], $result->errors);
        self::assertFalse($this->mapping(inviter: false)->canInvite($board, $this->owner));
    }

    public function testAReapplicationLeftPendingIsFinishedByTheNextSaveOrSync(): void
    {
        $board                              = $this->board();
        $ana                                = $this->viewer('ana@example.com');
        $bob                                = $this->viewer('bob@example.com');
        ScriptedPeopleResolver::$candidates = ['ana@example.com', 'bob@example.com'];
        $this->workspace([new RemotePerson('1', 'Ann', null, RemoteRole::Member)]);
        $this->put($this->row('t1', 'One', 1, assignees: [self::ref('1')]), $this->row('t2', 'Two', 2, assignees: [self::ref('1')]), $this->row('t3', 'Three', 3, assignees: [self::ref('1')]));
        $this->sync($board);
        $id = $this->personOf('1')->getId();

        // No time budget, one item per batch: the first batch runs, the rest is pending.
        $result = $this->mapping(batch: 1, seconds: 0.0)->save($this->directory(), $this->freshBoard($board), [$id => (string) $ana->getId()], $this->owner);
        self::assertSame(1, $result->pending);
        self::assertNotNull($this->personOf('1')->getReassignId());

        // Another target while it is pending (and no budget to finish it first): refused.
        $result = $this->mapping(batch: 1, seconds: 0.0)->save($this->directory(), $this->freshBoard($board), [$id => (string) $bob->getId()], $this->owner);
        self::assertSame(['reassign_in_progress'], $result->errors);

        // The next sync finishes it (the container's service: default budget and batch).
        $this->sync($board);
        self::assertNull($this->personOf('1')->getReassignId());
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->em->clear();
            $this->refreshOwner();
            self::assertSame(['ana@example.com'], array_map(static fn (TaskMember $m): string => $m->getUser() instanceof AppUser ? $m->getUser()->getEmail() : '', $this->taskTitled($title)->getMembers()->toArray()), $title);
        }

        // With a time budget, batches follow each other until done.
        $result = $this->mapping(batch: 1)->save($this->directory(), $this->freshBoard($board), [$id => (string) $bob->getId()], $this->owner);
        self::assertSame(0, $result->pending);
        self::assertSame(3, $result->moved->tasks);

        // A pending re-association whose target is gone is cancelled; one the resolver now refuses stays pending.
        $person = $this->personOf('1');
        $person->setPendingReassign('11111111-1111-4111-8111-111111111111', null, false);
        $this->em->flush();
        $this->mapping()->resume($this->directory(), $this->owner);
        self::assertNull($this->personOf('1')->getReassignId());
        $person = $this->personOf('1');
        $person->setPendingReassign('11111111-1111-4111-8111-111111111111', $this->em->find(AppUser::class, $bob->getId()), false);
        $this->em->flush();
        ScriptedPeopleResolver::$candidates = ['ana@example.com'];
        $this->mapping()->resume($this->directory(), $this->owner);
        self::assertNotNull($this->personOf('1')->getReassignId());
    }

    public function testADirectorySharedWithABoardTheActorCannotManageIsDenied(): void
    {
        ScriptedPeopleResolver::$scope      = 'org:shared';
        ScriptedPeopleResolver::$candidates = ['ana@example.com'];
        $this->user('ana@example.com');
        $mine   = $this->board();
        $theirs = $this->board('Theirs', creator: $this->user('boss@example.com'));
        $this->workspace([new RemotePerson('1', 'Ann', null, RemoteRole::Member)]);
        $this->sync($mine);
        $this->connection($theirs, $this->directory());

        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $mine->getId() . '/connection/people');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::BASE . $mine->getId() . '/connection/people/save', ['task_board_sync_people' => ['_token' => 'x']]);
        self::assertResponseStatusCodeSame(404);

        $this->expectException(PeopleMappingDeniedException::class);
        $this->mapping()->save($this->directory(), $this->freshBoard($mine), [], $this->owner);
    }

    private function mapping(int $batch = 500, float $seconds = 10.0, bool $inviter = true): PeopleMappingService
    {
        $container  = self::getContainer();
        $people     = $container->get(TaskSyncPersonRepository::class);
        $resolver   = $container->get(SyncPersonResolverInterface::class);
        $projection = $container->get(PeopleProjection::class);
        $store      = $container->get(SyncStore::class);
        $access     = $container->get(TaskBoardResourceAccessChecker::class);
        $invite     = $container->get('app.people_inviter');
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);
        self::assertInstanceOf(SyncPersonResolverInterface::class, $resolver);
        self::assertInstanceOf(PeopleProjection::class, $projection);
        self::assertInstanceOf(SyncStore::class, $store);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $access);
        self::assertInstanceOf(RecordingPeopleInviter::class, $invite);
        $reassigner = new TaskSyncPeopleReassigner($this->em, $people, $this->directories(), $projection, $store, $resolver, $access, $this->dispatcher(), new NativeClock(), $batch);

        return new PeopleMappingService($this->em, $people, $this->directories(), $reassigner, $resolver, $inviter ? $invite : null, $this->dispatcher(), 200, $seconds);
    }

    private function directories(): TaskSyncPeopleDirectoryService
    {
        $directories = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $directories);

        return $directories;
    }

    private function directory(): TaskSyncDirectory
    {
        $this->em->clear();
        $this->refreshOwner();
        $directory = $this->em->getRepository(TaskSyncDirectory::class)->findOneBy([]);
        self::assertInstanceOf(TaskSyncDirectory::class, $directory);

        return $directory;
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(AppUser::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(AppUser::class, $user);

        return (int) $user->getId();
    }

    private function connection(TaskBoard $board, TaskSyncDirectory $directory): void
    {
        $board      = $this->freshBoard($board);
        $connection = new TaskBoardConnection(board: $board, provider: 'clickup', importScope: 'clickup', connectionRef: 'conn-2', containerId: 'L2', containerLabel: 'Other', createdBy: $this->owner);
        $connection->setDirectory($this->em->find(TaskSyncDirectory::class, $directory->getId()));
        $this->em->persist($connection);
        $this->em->flush();
    }
}
