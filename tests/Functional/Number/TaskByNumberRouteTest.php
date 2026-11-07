<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 214, FR-009, FR-010: the short link and the number on the pages.
 */
final class TaskByNumberRouteTest extends TaskNumberTestCase
{
    public function testTheShortLinkRedirectsToTheTaskPageOfTheTenant(): void
    {
        $task = $this->task($this->board('One', tenantKey: 'acme'), 'a');
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/tasks/n/1');

        self::assertResponseRedirects('/t/acme/tasks/task/' . $task->getId());
    }

    public function testAGuestGetsNoAnswerAboutANumber(): void
    {
        $this->task($this->board('One', tenantKey: 'acme'), 'a');

        $this->client->request('GET', '/t/acme/tasks/n/1');

        self::assertFalse($this->client->getResponse()->isRedirect('/t/acme/tasks/task/'));
        self::assertGreaterThanOrEqual(300, $this->client->getResponse()->getStatusCode());
    }

    /** Unknown, retired and unreadable answer the same 404, and another tenant's number does not exist here. */
    public function testUnknownRetiredAndUnreadableAnswerTheSame404(): void
    {
        $board    = $this->board('One', tenantKey: 'acme');
        $readable = $this->task($board, 'readable');
        $retired  = $this->task($board, 'retired');
        $this->task($board, 'secret');
        $this->task($this->board('Two', tenantKey: 'other'), 'there');
        $this->em->remove($retired);
        $this->em->flush();
        $this->login($this->owner);
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getTask()->getTitle() === 'secret') {
                $event->deny();
            }
        });
        foreach (['/t/acme/tasks/n/99', '/t/acme/tasks/n/2', '/t/acme/tasks/n/3', '/t/nobody/tasks/n/1'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        foreach (['/t/acme/tasks/n/0', '/t/acme/tasks/n/abc'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $this->client->request('GET', '/t/acme/tasks/n/1');
        self::assertResponseRedirects('/t/acme/tasks/task/' . $readable->getId());
    }

    /** FR-006: an alias reaches the task in its new tenant, for whoever may read it there. */
    public function testAnAliasGoesToTheTaskInItsNewTenant(): void
    {
        $from = $this->board('One', tenantKey: 'acme');
        $to   = $this->board('Two', tenantKey: 'other');
        $task = $this->task($from, 'moved');
        (new ReflectionProperty(Task::class, 'board'))->setValue($task, $to);
        $this->em->flush();
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/tasks/n/1');
        self::assertResponseRedirects('/t/other/tasks/task/' . $task->getId());

        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->deny();
        });
        $this->client->request('GET', '/t/acme/tasks/n/1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAliasOfATaskNowOutsideAnyTenantIsNotFound(): void
    {
        $task = $this->task($this->board('One', tenantKey: 'acme'), 'moved');
        (new ReflectionProperty(Task::class, 'board'))->setValue($task, $this->board('Two'));
        $this->em->flush();
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/tasks/n/1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutATenantTheBoardNamesTheScope(): void
    {
        $this->reconfigure(['task_numbers' => ['enabled' => true, 'scope' => 'board']]);
        $board = $this->board('One');
        $task  = $this->task($board, 'a');
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/n/1');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/tools/task-board/n/1?board=unknown');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/tools/task-board/n/1?board=' . $board->getId());
        self::assertResponseRedirects('/tools/task-board/task/' . $task->getId());

        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->deny();
        });
        $this->client->request('GET', '/tools/task-board/n/1?board=' . $board->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePagesShowTheNumber(): void
    {
        $this->reconfigure(['task_numbers' => ['enabled' => true]]);
        $board  = $this->board('One');
        $parent = $this->task($board, 'parent');
        $this->task($board, 'child', parent: $parent);
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nowo-task-card[data-title="parent"] .nowo-task-number', '#1');

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tr[data-number="1"] .nowo-task-number', '#1');
        self::assertStringContainsString('#1', (string) $crawler->filter('tr[data-number="1"]')->attr('data-search-extra'));
        self::assertCount(1, $crawler->filter('option[value="number"]'));

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/gantt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('#1 · ', (string) $this->client->getCrawler()->filter('.nowo-task-gantt__bar')->first()->attr('title'));

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $parent->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('#1', $crawler->filter('.nowo-task-detail__number [data-nowo-ui-clipboard]')->attr('data-nowo-ui-clipboard-text'));
        self::assertSelectorTextContains('.nowo-task-subtree__item .nowo-task-number', '#2');
    }

    public function testATaskWithNoNumberYetShowsNothing(): void
    {
        $this->reconfigure(['task_numbers' => ['enabled' => true]]);
        $board = $this->board('One');
        $task  = $this->task($board, 'a');
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->em->getClassMetadata(Task::class)->getTableName() . ' SET number = NULL');
        $this->em->clear();
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.nowo-task-card .nowo-task-number');
        self::assertInstanceOf(TaskBoard::class, $task->getBoard());
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
