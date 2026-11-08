<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Recurrence;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Form\TaskRecurrenceFormType;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Spec 215, FR-008: a task that follows a provider cannot repeat; a native task on a linked board can.
 */
final class TaskRecurrenceSyncTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return self::SYNC_CONFIG + ['recurrence' => ['enabled' => true]];
    }

    public function testASyncedTaskIsRefusedAndANativeOneOnTheSameBoardIsNot(): void
    {
        $board = $this->board('Linked', ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'));
        $this->sync($board);
        $synced  = $this->taskTitled('Design');
        $manager = self::getContainer()->get(TaskRecurrenceManager::class);
        self::assertInstanceOf(TaskRecurrenceManager::class, $manager);
        $rule = new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'week', 1, new DateTimeImmutable());

        try {
            $manager->create($synced, $rule, $this->owner);
            self::fail('A synced task must be refused.');
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame('synced_task', $exception->reason);
        }

        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/task/' . $synced->getId() . '/panel');
        self::assertSelectorExists('[data-testid="nowo-task-repeat-synced"]');
        self::assertSelectorNotExists('[data-testid="nowo-task-repeat-edit"]');
        $this->client->request('POST', '/tools/task-board/task/' . $synced->getId() . '/recurrence', ['task_recurrence' => ['mode' => 'fixed', 'interval' => '1', 'unit' => 'week', 'end' => 'never', 'leadDays' => '1', '_token' => PredictableCsrfTokenManager::value(TaskRecurrenceFormType::CSRF_TOKEN_ID)]]);
        self::assertResponseStatusCodeSame(403);

        $native = $this->task($this->freshBoard($board), 'Local');
        self::assertTrue($manager->create($native, $rule, $this->owner)->isActive());
    }
}
