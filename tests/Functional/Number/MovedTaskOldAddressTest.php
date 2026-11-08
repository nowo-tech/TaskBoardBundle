<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.5.0 (TB-14): the task page of a task moved to another tenant redirects to its page there, as the `#number` alias
 * does, for whoever may read it; anyone else keeps the 404 (the task's existence is not told).
 */
final class MovedTaskOldAddressTest extends TaskNumberTestCase
{
    public function testTheOldAddressRedirectsToTheNewTenant(): void
    {
        $task = $this->movedTask('Two', 'other');
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/tasks/task/' . $task->getId());

        self::assertResponseRedirects('/t/other/tasks/task/' . $task->getId());

        // Audit IM-I12: HEAD answers as GET.
        $this->client->request('HEAD', '/t/acme/tasks/task/' . $task->getId());
        self::assertResponseRedirects('/t/other/tasks/task/' . $task->getId());
    }

    public function testAReaderWhoMayNotSeeTheTaskGetsThe404(): void
    {
        $task = $this->movedTask('Two', 'other');
        $this->login($this->owner);
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->deny();
        });

        $this->client->request('GET', '/t/acme/tasks/task/' . $task->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testATaskNowOutsideAnyTenantAnUnknownIdAPanelFetchAndAPostKeepThe404(): void
    {
        $outside = $this->movedTask('Two', null);
        $moved   = $this->movedTask('Three', 'other');
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/tasks/task/' . $outside->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/t/acme/tasks/task/00000000-0000-0000-0000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $this->client->xmlHttpRequest('GET', '/t/acme/tasks/task/' . $moved->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/t/acme/tasks/task/' . $moved->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testATaskOfTheTenantOpensAsBefore(): void
    {
        $task = $this->task($this->board('One', tenantKey: 'acme'), 'here');
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/tasks/task/' . $task->getId());

        self::assertResponseIsSuccessful();
    }

    private function movedTask(string $boardName, ?string $tenantKey): Task
    {
        $task = $this->task($this->board('One ' . $boardName, tenantKey: 'acme'), 'moved to ' . $boardName);
        (new ReflectionProperty(Task::class, 'board'))->setValue($task, $this->board($boardName, tenantKey: $tenantKey));
        $this->em->flush();

        return $task;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
