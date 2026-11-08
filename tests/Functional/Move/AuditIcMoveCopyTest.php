<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use Nowo\TaskBoardBundle\Controller\TaskCopyController;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Audit IC-L1, IC-L2 and IC-I1: access on every task of the subtree, nothing slips between the plan and the transaction,
 * and a host's targets are narrowed to boards the user may edit.
 */
final class AuditIcMoveCopyTest extends CopyTestCase
{
    private const BASE = '/tools/task-board';

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['move' => ['enabled' => true]];
        parent::setUp();
    }

    public function testADuplicateLeavesOutASubtaskTheUserCannotSee(): void
    {
        $board  = $this->board('Source');
        $task   = $this->task($board, 'Parent');
        $secret = $this->task($board, 'Secret step', 0, $task);
        $this->task($board, 'Under the secret', 0, $secret);
        $this->task($board, 'Visible step', 0, $task, 1);
        $this->clear();
        $this->login($this->owner);
        $this->hide($secret->getId(), deny: true);

        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/duplicate');
        self::assertSelectorTextContains('[data-testid="nowo-task-duplicate-hidden"]', '2 subtask(s) you cannot see are left out.');
        self::assertSelectorTextContains('[data-testid="nowo-task-duplicate-copied"]', 'This task and 1 subtask(s)');
        $fingerprint = (string) $crawler->filter('input[name="fingerprint"]')->attr('value');
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/duplicate', ['_token' => PredictableCsrfTokenManager::value(TaskCopyController::DUPLICATE_TOKEN), 'fingerprint' => $fingerprint]);
        self::assertResponseRedirects();

        $this->clear();
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['title' => 'Secret step']), 'not copied');
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['title' => 'Under the secret']));
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['title' => 'Visible step']));
    }

    public function testAMoveIsRefusedWhenASubtaskCannotBeEditedOrSeen(): void
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $task   = $this->task($source, 'Parent');
        $locked = $this->task($source, 'Locked step', 0, $task);
        $this->clear();
        $this->login($this->owner);
        $url = self::BASE . '/task/' . $task->getId() . '/move-board?copy%5Btarget%5D=' . $target->getId();

        $this->hide($locked->getId(), deny: false);
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('[data-testid="nowo-task-copy-refusal"]', 'You may not change “Locked step”, which would move with it.');
        self::assertSelectorNotExists('[data-testid="nowo-task-move-confirm"]');

        $this->hide($locked->getId(), deny: true);
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('[data-testid="nowo-task-copy-refusal"]', 'A subtask you cannot see would move with it.');
        self::assertStringNotContainsString('Locked step', (string) $this->client->getResponse()->getContent());

        $this->clear();
        self::assertSame($source->getId(), $this->reload($locked)->getBoard()->getId());
    }

    public function testAWriteBetweenThePlanAndTheTransactionRefusesTheMove(): void
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $stays  = $this->task($source, 'Stays');
        $task   = $this->task($source, 'Moves');
        $this->clear();
        $other  = $this->user('ana@example.com');
        $task   = $this->reload($task);
        $stays  = $this->reload($stays);
        $target = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $target);
        $plan = $this->planner()->plan($task, $target);

        // A concurrent write in the window (simulated in the QUOTA_CHECK that move() asks there): a dependency and a member.
        $em         = $this->em;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::QUOTA_CHECK, static function (TaskBoardQuotaCheckEvent $event) use ($task, $stays, $em): void {
            $task->addOutgoingDependency(new TaskDependency($task, $stays, TaskDependencyType::Blocks));
            $em->flush();
        }, 100);

        $this->assertRefused('stale', fn () => $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner));
        self::assertTrue($this->em->isOpen(), 'a refusal inside the transaction leaves the entity manager usable');
        $row = $this->em->getConnection()->fetchAssociative('SELECT s.board_id AS a, t.board_id AS b FROM task_board_task_dependencies d JOIN task_board_tasks s ON s.id = d.source_task_id JOIN task_board_tasks t ON t.id = d.target_task_id');
        self::assertIsArray($row);
        self::assertSame($row['a'], $row['b'], 'no dependency crosses boards');
        self::assertSame($source->getId(), $this->em->getConnection()->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));

        // A new plan sees the dependency and removes it.
        $dispatcher->removeListener(TaskBoardEvents::QUOTA_CHECK, $dispatcher->getListeners(TaskBoardEvents::QUOTA_CHECK)[0]);
        $member = new TaskMember($task, $other, TaskMemberRole::Watcher);
        $task->addMember($member);
        $this->em->flush();
        $fresh = $this->planner()->plan($task, $target);
        self::assertSame(['Stays'], $fresh->dependenciesRemoved);
        self::assertNotSame($plan->snapshot, $fresh->snapshot, 'a member changes the snapshot too');
    }

    public function testAHostsTargetsAreNarrowedToBoardsTheUserMayEdit(): void
    {
        self::$taskBoardConfig = ['move' => ['enabled' => true, 'targets' => 'app.all_targets']];
        self::ensureKernelShutdown();
        $this->setUp();
        $source   = $this->board('Source');
        $mine     = $this->board('Mine');
        $foreign  = $this->board('Foreign', creator: $this->user('ana@example.com'));
        $archived = $this->board('Archived');
        $archived->archive();
        $this->em->flush();
        $task = $this->task($source, 'Task');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/move-board');
        self::assertSame(['', $mine->getId()], $crawler->filter('select[name="copy[target]"] option')->each(static fn ($option): string => (string) $option->attr('value')));
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/move-board?copy%5Btarget%5D=' . $foreign->getId());
        self::assertResponseStatusCodeSame(404);
    }

    private function hide(string $taskId, bool $deny): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($taskId, $deny): void {
            if ($event->getTask()->getId() === $taskId) {
                $deny ? $event->deny() : $event->markReadOnly();
            }
        });
    }
}
