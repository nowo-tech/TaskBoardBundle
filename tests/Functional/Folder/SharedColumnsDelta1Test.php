<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskStatusChangedEvent;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Since 2.19.0, audit delta PW-1 (the proofs of concept turned into regressions): joining a sharing folder needs the right
 * on every board already in it (D1-L1); a saved column set goes through the shared-columns rules (D1-L2); a deletion asks
 * the column transition policy for the tasks it moves on the other boards (D1-L3); the follow-up events carry the channel
 * of the write (D1-I1); a detached actor is resolved for the propagation (D1-I3).
 */
final class SharedColumnsDelta1Test extends CopyTestCase
{
    /** @var list<TaskStatusChangedEvent> */
    private array $statusEvents = [];

    protected function setUp(): void
    {
        self::$taskBoardConfig = ['column_transition_policy' => HumansOnlyColumnTransitionPolicy::class, 'write_provenance_provider' => 'app.write_provenance'];
        PathWriteProvenance::reset();
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener('nowo_task_board.task_status_changed', function (TaskStatusChangedEvent $event): void {
            $this->statusEvents[] = $event;
        });
    }

    protected function tearDown(): void
    {
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    public function testJoiningASharingFolderNeedsTheRightOnEveryBoardInIt(): void
    {
        $lead     = $this->user('lead@example.com');
        $intruder = $this->user('intruder@example.com');
        $one      = $this->board('One', ['Todo', 'Doing', 'Done'], creator: $lead, tenantKey: 'acme');
        $two      = $this->board('Two', ['Todo', 'Doing', 'Done'], creator: $lead, tenantKey: 'acme');
        $own      = $this->board('Intruder', ['A'], creator: $intruder, tenantKey: 'acme');
        $this->signIn($lead);
        $folder = $this->restructurer()->createFolder($lead, 'acme', 'Lead folder');
        $this->restructurer()->moveBoardToFolder($lead, $one->getId(), $folder->id);
        $this->restructurer()->moveBoardToFolder($lead, $two->getId(), $folder->id);
        $this->statusSets()->shareFrom($lead, $folder->id, $one->getId());

        $this->signIn($intruder);
        try {
            $this->restructurer()->moveBoardToFolder($intruder, $own->getId(), $folder->id);
            self::fail('no right on the boards already in the folder');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::NOT_ALLOWED, $refused->reason);
        }

        // The lead keeps his shared columns.
        $this->signIn($lead);
        $this->clear();
        $this->columns()->update(ColumnTypes::ordered($this->fresh($one))[1], new BoardColumnFormData('Review'), $lead);
        $this->clear();
        self::assertSame(['Todo', 'Review', 'Done'], $this->names($two));
        self::assertNull($this->fresh($own)->getFolderId());
    }

    public function testAColumnSetGoesThroughTheSharedColumnsRules(): void
    {
        $admin  = $this->admin();
        $member = $this->user('member@example.com');
        $mine   = $this->board('Member board', ['Todo', 'Done'], creator: $member, tenantKey: 'acme');
        $other  = $this->board('Other', ['Todo', 'Done'], creator: $admin, tenantKey: 'acme');
        $folder = $this->sharing($admin, [$mine, $other]);
        $set    = ColumnSet::fromBoard($this->board('Template', ['Extra'], creator: $member, tenantKey: 'acme'), 'Mine', $member, 'acme');
        $this->em->persist($set);
        $this->em->flush();

        $this->signIn($member);
        foreach ([$member, null] as $actor) {
            try {
                $this->columnSets()->applyToBoard($set, $this->fresh($mine), $actor);
                self::fail('refused');
            } catch (TaskBoardRestructureRefusedException $refused) {
                self::assertSame(TaskBoardRestructureRefusedException::NOT_ALLOWED, $refused->reason);
            }
        }
        // The board UI says so.
        $this->login($member);
        $this->client->request('POST', '/tools/task-board/board/' . $mine->getId() . '/column-sets/apply', ['column_set_apply_form' => ['columnSetId' => $set->getId(), '_token' => PredictableCsrfTokenManager::value('column_set_apply_form')]]);
        self::assertResponseRedirects();
        self::assertStringContainsString('shares its statuses with boards you may not manage', $this->client->followRedirect()->text());
        $this->clear();
        self::assertSame(['Todo', 'Done'], $this->names($mine));

        // With the rights, the set reaches every board of the folder.
        $this->signIn($admin);
        $this->columnSets()->applyToBoard($set, $this->fresh($mine), $admin);
        $this->clear();
        self::assertSame(['Todo', 'Done', 'Extra'], $this->names($mine));
        self::assertSame(['Todo', 'Done', 'Extra'], $this->names($other));
        self::assertCount(3, $this->statusSets()->statusSet($folder) ?? []);
    }

    public function testAnMcpDeletionIsRefusedWhenItWouldMoveASiblingTaskIntoAHumansOnlyColumn(): void
    {
        $one = $this->board('One', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $two = $this->board('Two', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $this->sharing($this->owner, [$one, $two]);
        $this->columns()->setHumansOnly(ColumnTypes::ordered($this->fresh($one))[0], true, $this->owner);
        $task = $this->task($this->fresh($two), 'Sibling task in Doing', 1);
        $this->clear();
        self::assertTrue(ColumnTypes::ordered($this->fresh($two))[0]->isHumansOnly());

        try {
            $this->columns()->delete(ColumnTypes::ordered($this->fresh($one))[1], $this->owner, new ColumnTransitionVia(ColumnTransitionVia::MCP, ColumnTransitionVia::OP_COLUMN_DELETE));
            self::fail('the sibling task would enter a humans-only column');
        } catch (ColumnTransitionRefusedException $refused) {
            self::assertSame($task->getId(), $refused->transition->task?->id);
        }
        $this->clear();
        self::assertSame(['Todo', 'Doing', 'Done'], $this->names($one), 'nothing written');
        self::assertSame('Doing', $this->reload($task)->getColumn()?->getName());

        // A person may.
        $this->columns()->delete(ColumnTypes::ordered($this->fresh($one))[1], $this->owner);
        $this->clear();
        self::assertSame('Todo', $this->reload($task)->getColumn()?->getName());
    }

    public function testFollowUpEventsCarryTheWriteChannelAndADetachedActorIsResolved(): void
    {
        $one = $this->board('One', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $two = $this->board('Two', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $this->sharing($this->owner, [$one, $two]);
        $task = $this->task($this->fresh($two), 'Sibling', 1);
        $this->clear();
        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::Api, 'Agent');
        $this->statusEvents           = [];

        // $this->owner is detached by clear(): the propagation resolves it.
        $this->columns()->delete(ColumnTypes::ordered($this->fresh($one))[1], $this->owner);

        $this->clear();
        self::assertSame('Todo', $this->reload($task)->getColumn()?->getName(), 'carried to the sibling');
        self::assertNotNull($this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => TaskChangeType::Column]));
        $sibling = array_values(array_filter($this->statusEvents, static fn (TaskStatusChangedEvent $e): bool => $e->task->id === $task->getId()));
        self::assertCount(1, $sibling);
        self::assertSame(['api', 'columns'], [$sibling[0]->via->channel, $sibling[0]->via->operation]);
    }

    /**
     * @param list<TaskBoard> $boards
     */
    private function sharing(AppUser $actor, array $boards): string
    {
        $this->signIn($actor);
        $folder = $this->restructurer()->createFolder($actor, 'acme', 'Shared');
        foreach ($boards as $board) {
            $this->restructurer()->moveBoardToFolder($actor, $board->getId(), $folder->id);
        }
        $this->statusSets()->shareFrom($actor, $folder->id, $boards[0]->getId());

        return $folder->id;
    }

    private function admin(): AppUser
    {
        return $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    private function fresh(TaskBoard $board): TaskBoard
    {
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);

        return $fresh;
    }

    /** @return list<string> */
    private function names(TaskBoard $board): array
    {
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);

        return array_map(static fn (BoardColumn $c): string => $c->getName(), ColumnTypes::ordered($fresh));
    }

    private function signIn(AppUser $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function columns(): BoardColumnManager
    {
        $service = self::getContainer()->get(BoardColumnManager::class);
        self::assertInstanceOf(BoardColumnManager::class, $service);

        return $service;
    }

    private function columnSets(): ColumnSetManager
    {
        $service = self::getContainer()->get(ColumnSetManager::class);
        self::assertInstanceOf(ColumnSetManager::class, $service);

        return $service;
    }

    private function statusSets(): FolderStatusSets
    {
        $service = self::getContainer()->get(FolderStatusSets::class);
        self::assertInstanceOf(FolderStatusSets::class, $service);

        return $service;
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }
}
