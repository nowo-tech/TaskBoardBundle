<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Since 1.25: the import routes ask the `import_tasks` decision (a host narrows imports to a dedicated permission,
 * e.g. Beacon's `project.tasks.import`): 403 when a BOARD_ACCESS_CHECK listener denies it; by default a task
 * participant still imports subtasks under their task (as since 1.18).
 */
final class SyncReassignImportGuardTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private const CSV = "Issue key,Summary,Status\nPRJ-1,Spec,To do\n";

    public function testADeniedImportDecisionAnswers403OnBothImportRoutes(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $task  = $this->task($board, 'Parent');
        $this->login($this->owner);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_IMPORT_TASKS) {
                $event->deny();
            }
        });

        $this->import('/board/' . $board->getId() . '/import');
        self::assertResponseStatusCodeSame(403);
        $this->import('/task/' . $task->getId() . '/import');
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Task::class)->findAll(), 'nothing was imported');
    }

    public function testATaskParticipantStillImportsSubtasksByDefault(): void
    {
        $board       = $this->board(columns: ['To do', 'Done']);
        $task        = $this->task($board, 'Parent');
        $participant = $this->user('participant@example.com');
        $task->addMember(new TaskMember($task, $participant, TaskMemberRole::Watcher));
        $this->em->flush();
        $this->login($participant);

        $this->import('/task/' . $task->getId() . '/import');
        self::assertResponseRedirects();
        $this->import('/board/' . $board->getId() . '/import');
        self::assertResponseStatusCodeSame(403);
    }

    private function import(string $path): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tb-import');
        file_put_contents($file, self::CSV);
        $this->client->request(
            'POST',
            self::BASE . $path,
            ['task_import_form' => ['source' => 'jira_csv', 'createMissingColumns' => '1', '_token' => PredictableCsrfTokenManager::value('task_import_form')]],
            ['task_import_form' => ['file' => new UploadedFile($file, 'tasks.csv', null, null, true)]],
        );
    }
}
