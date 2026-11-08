<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Recurrence;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Form\TaskRecurrenceFormType;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 215, FR-017 – FR-021: the Repeat section, the Repeat page and its actions; nothing with the option off.
 */
final class TaskRecurrencePagesTest extends CopyTestCase
{
    private const BASE = '/tools/task-board';

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['recurrence' => ['enabled' => true]];
        parent::setUp();
    }

    public function testWithoutTheOptionThereIsNoSectionRouteOrIcon(): void
    {
        self::$taskBoardConfig = ['calendar' => ['enabled' => false]];
        self::ensureKernelShutdown();
        $this->setUp();
        $task = $this->task($this->board(), 'Task');
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . '/task/' . $task->getId());
        self::assertSelectorNotExists('[data-testid="nowo-task-repeat"]');
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::getContainer()->has(TaskRecurrenceManager::class));
    }

    public function testMakingATaskRecurringFromThePageAndTheActions(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Invoices');
        $task->setDueAt(new DateTimeImmutable('+3 days'));
        $this->em->flush();
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertSelectorTextContains('[data-testid="nowo-task-repeat"]', 'Does not repeat.');
        self::assertSelectorTextContains('[data-testid="nowo-task-repeat-edit"]', 'Make recurring');

        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-repeat-aside"]');

        $form = ['mode' => 'fixed', 'interval' => '1', 'unit' => 'week', 'weekdays' => ['1', '3'], 'end' => 'never', 'leadDays' => '2', 'copySubtasks' => '1', '_token' => PredictableCsrfTokenManager::value(TaskRecurrenceFormType::CSRF_TOKEN_ID)];
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence', ['task_recurrence' => ['end' => 'date'] + $form]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Give the end date.');
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence', ['task_recurrence' => ['end' => 'count'] + $form]);
        self::assertSelectorTextContains('body', 'Give how many times.');

        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence', ['task_recurrence' => $form]);
        self::assertResponseRedirects(self::BASE . '/task/' . $task->getId());
        $series = $this->em->getRepository(TaskRecurrence::class)->findOneBy([]);
        self::assertInstanceOf(TaskRecurrence::class, $series);
        self::assertSame([1, 3], $series->rule()->weekdays);
        self::assertSame(2, $series->getLeadDays());
        self::assertFalse($series->copiesMembers());
        self::assertSame($this->owner->getId(), $series->getAuthor()?->getId());

        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertSelectorTextContains('[data-testid="nowo-task-repeat-sentence"]', 'Every 1 week(s) (Mon, Wed) · next');
        self::assertSelectorTextContains('[data-testid="nowo-task-repeat-edit"]', 'Edit repeat');
        $this->client->request('GET', self::BASE . '/board/' . $board->getId());
        self::assertSelectorExists('[data-testid="nowo-task-repeat-icon"]');
        $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/list');
        self::assertSelectorExists('[data-testid="nowo-task-repeat-icon"]');

        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertSelectorExists('[data-testid="nowo-task-repeat-aside"]');
        $date = (string) $crawler->filter('[data-testid="nowo-task-repeat-skip"] input[name="date"]')->first()->attr('value');
        $this->action($task, 'skip', $date);
        self::assertContains($date, $this->fresh($series)->getSkippedDates());
        $this->action($task, 'unskip', $date);
        self::assertNotContains($date, $this->fresh($series)->getSkippedDates());
        $this->action($task, 'skip', '1999-01-01');
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->peek('error');
        self::assertSame(['task_board.recurrence.flash.not_done'], $flashes);
        $this->action($task, 'pause');
        self::assertSame(TaskRecurrence::STATUS_PAUSED, $this->fresh($series)->getStatus());
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertSelectorTextContains('[data-testid="nowo-task-repeat-status"]', 'Paused');
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertSelectorExists('[data-testid="nowo-task-repeat-resume"]');
        $this->action($task, 'resume');
        self::assertTrue($this->fresh($series)->isActive());
        $this->action($task, 'pause');
        $this->action($task, 'pause');
        $this->action($task, 'resume');
        $this->action($task, 'resume');
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence/stop', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        // Editing keeps the series and changes it.
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence', ['task_recurrence' => ['mode' => 'after_completion', 'unit' => 'day', 'interval' => '3'] + $form]);
        self::assertSame(TaskRecurrenceRule::MODE_AFTER_COMPLETION, $this->fresh($series)->getMode());
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertSelectorTextContains('[data-testid="nowo-task-repeat-skip"]', 'Skip the next one');
        $this->action($task, 'skip');
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertSelectorExists('[data-testid="nowo-task-repeat-unskip"]');
        $this->action($task, 'stop');
        self::assertSame(TaskRecurrence::STATUS_STOPPED, $this->fresh($series)->getStatus());
        $this->action($task, 'pause');
        self::assertResponseStatusCodeSame(404, 'no series any more');
    }

    public function testAccessAndRefusals(): void
    {
        self::$taskBoardConfig = ['recurrence' => ['enabled' => true, 'max_subtasks' => 0]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        $task  = $this->task($board, 'Parent');
        $this->task($board, 'Child', 0, $task);
        $this->clear();
        $this->login($this->owner);
        $form = ['mode' => 'fixed', 'interval' => '1', 'unit' => 'month', 'monthDay' => '-1', 'end' => 'never', 'leadDays' => '1', 'copySubtasks' => '1', '_token' => PredictableCsrfTokenManager::value(TaskRecurrenceFormType::CSRF_TOKEN_ID)];

        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence', ['task_recurrence' => $form]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Too many subtasks: 1, at most 0.');

        $this->client->request('GET', self::BASE . '/task/unknown/recurrence');
        self::assertResponseStatusCodeSame(404);
        $this->login($this->user('stranger@example.com'));
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertResponseStatusCodeSame(404);

        $this->login($this->owner);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->markReadOnly();
        });
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertSelectorExists('[data-testid="nowo-task-repeat"]');
        self::assertSelectorNotExists('[data-testid="nowo-task-repeat-edit"]', 'read-only text');
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence', ['task_recurrence' => $form]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence/pause', ['_token' => PredictableCsrfTokenManager::value('task_board_recurrence_pause')]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/logout-none');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/recurrence');
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'a guest');
    }

    public function testTheViewResetsItsCache(): void
    {
        $view = self::getContainer()->get(TaskRecurrenceView::class);
        self::assertInstanceOf(TaskRecurrenceView::class, $view);
        $task = $this->task($this->board(), 'Task');
        self::assertFalse($view->repeats($task));
        $manager = self::getContainer()->get(TaskRecurrenceManager::class);
        self::assertInstanceOf(TaskRecurrenceManager::class, $manager);
        $manager->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'day', 1, new DateTimeImmutable()), $this->owner);
        self::assertFalse($view->repeats($task), 'cached for the request');
        $view->reset();
        self::assertTrue($view->repeats($task));
    }

    private function action(Task $task, string $action, ?string $date = null): void
    {
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/recurrence/' . $action, ['_token' => PredictableCsrfTokenManager::value('task_board_recurrence_' . $action), 'date' => $date ?? '']);
    }

    private function fresh(TaskRecurrence $series): TaskRecurrence
    {
        $this->clear();
        $fresh = $this->em->find(TaskRecurrence::class, $series->getId());
        self::assertInstanceOf(TaskRecurrence::class, $fresh);

        return $fresh;
    }
}
