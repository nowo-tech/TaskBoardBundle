<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Since 1.22 — regressions of the 1.22 audit (Appendix DL) over HTTP: an import only updates tasks the importer may
 * edit (DL-M1, DL-M2) and only matches links an import wrote (DL-L2).
 */
final class ImportAuthorizationTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private const JIRA = "Issue key,Summary,Status\nPRJ-1,Spec,To do\nPRJ-2,Payroll run,To do\nPRJ-3,Salary review,To do\n";

    /** DL-M1: a participant of one task cannot reach the other tasks of the board through "Import subtasks". */
    public function testASubtaskImportOnlyMatchesTheTasksImportedIntoThatTask(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->login($this->owner);
        $this->importBoard($board->getId(), self::JIRA);

        $mallory = $this->user('mallory@example.com');
        $own     = $this->task($board, 'Mallory task');
        $own->addMember(new TaskMember($own, $mallory, TaskMemberRole::Watcher));
        $this->em->flush();

        $this->login($mallory);
        $evil = "Issue key,Summary,Status,Inward issue link (Blocks)\nPRJ-2,PWNED by mallory,Done,PRJ-3\nPRJ-3,PWNED too,Done,\nPRJ-9,probe,To do,\n";
        $this->post('/task/' . $own->getId() . '/import', ['task_import_form' => ['source' => 'jira_csv', 'createMissingColumns' => '1', '_token' => $this->csrf()]], ['task_import_form' => ['file' => $this->upload($evil)]]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Subtasks: 3 created, 0 updated, 0 unchanged, 0 skipped');

        $this->em->clear();
        foreach (['Payroll run', 'Salary review'] as $title) {
            $victim = $this->em->getRepository(Task::class)->findOneBy(['title' => $title]);
            self::assertInstanceOf(Task::class, $victim, $title . ' kept its title');
            self::assertSame('To do', $victim->getColumn()?->getName());
            self::assertNull($victim->getParent());
        }
        foreach ($this->em->getRepository(TaskDependency::class)->findAll() as $dependency) {
            self::assertSame($own->getId(), $dependency->getSourceTask()->getParent()?->getId(), 'only between Mallory\'s new subtasks');
        }
        self::assertCount(3, $this->em->getRepository(Task::class)->findBy(['parent' => $own->getId()]));
    }

    /** DL-M2: a task a TASK_ACCESS_CHECK listener marks read-only is skipped by a board re-import. */
    public function testABoardReimportSkipsTasksTheImporterCannotEdit(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->login($this->owner);
        $this->importBoard($board->getId(), self::JIRA);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getTask()->getTitle() === 'Payroll run') {
                $event->grant();
                $event->markReadOnly();
            }
        });

        $this->importBoard($board->getId(), "Issue key,Summary,Status,Parent key,Inward issue link (Blocks)\nPRJ-2,Rewritten through import,Done,,\nPRJ-1,Spec,Done,PRJ-2,PRJ-2\nPRJ-3,Salary review,To do,,\n");
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Import done: 0 created, 1 updated, 1 unchanged, 1 skipped');
        self::assertSelectorTextContains('body', '1 row(s) were skipped: they match tasks you cannot edit.');
        self::assertSelectorTextNotContains('body', 'Rewritten through import');

        $this->em->clear();
        $locked = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Payroll run']);
        self::assertInstanceOf(Task::class, $locked, 'not rewritten');
        self::assertSame('To do', $locked->getColumn()?->getName());
        $spec = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Spec']);
        self::assertInstanceOf(Task::class, $spec);
        self::assertNull($spec->getParent(), 'not moved under the read-only task');
        self::assertSame('Done', $spec->getColumn()?->getName());
        self::assertCount(0, $this->em->getRepository(TaskDependency::class)->findAll(), 'no relation to the read-only task');
    }

    /** DL-L2: a link the link form planted (legacy label, external id) does not capture the next re-import. */
    public function testALinkWithoutImportSourceIsNeverMatched(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->login($this->owner);
        $this->importBoard($board->getId(), self::JIRA);
        $trap = $this->task($board, 'Attacker task');
        $trap->addLink(new TaskLink($trap, TaskLinkType::Other, 'import://jira_csv/PRJ-7', 'jira_csv #PRJ-7', 'PRJ-7'));
        $this->em->flush();

        $this->importBoard($board->getId(), "Issue key,Summary,Status,Description\nPRJ-7,Secret plan,To do,Confidential\n");

        $this->em->clear();
        $attacker = $this->em->find(Task::class, $trap->getId());
        self::assertSame('Attacker task', $attacker?->getTitle());
        self::assertNull($attacker?->getDescription());
        self::assertInstanceOf(Task::class, $this->em->getRepository(Task::class)->findOneBy(['title' => 'Secret plan']), 'a task of its own');
    }

    private function importBoard(string $boardId, string $csv): void
    {
        $this->post('/board/' . $boardId . '/import', ['task_import_form' => ['source' => 'jira_csv', '_token' => $this->csrf()]], ['task_import_form' => ['file' => $this->upload($csv)]]);
        self::assertResponseRedirects();
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $files
     */
    private function post(string $path, array $parameters, array $files = []): void
    {
        $this->client->request('POST', self::BASE . $path, $parameters, $files);
    }

    private function csrf(): string
    {
        return PredictableCsrfTokenManager::value('task_import_form');
    }

    private function upload(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-import');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'tasks.csv', null, null, true);
    }
}
