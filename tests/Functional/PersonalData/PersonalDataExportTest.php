<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PersonalData;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporter;
use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporterInterface;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use ReflectionProperty;
use stdClass;

use function array_column;
use function array_keys;
use function array_unique;
use function array_values;
use function json_encode;
use function sort;

/**
 * Since 3.4.0 (Beacon WORKLIST): the personal-data export lists what the account erasure detaches — one section per
 * erasure step (parity, as VaultBundle 2.5.0's `ERASURE_SECTIONS`) — and after the erasure those sections are empty.
 * Never a secret or a token.
 */
final class PersonalDataExportTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return ['sync' => self::SYNC_CONFIG['sync'] + ['plans' => ['enabled' => true]], 'task_questions' => ['enabled' => true], 'recurrence' => ['enabled' => true]];
    }

    public function testEveryErasureStepHasItsSectionAndEverySectionIsInTheDocument(): void
    {
        self::assertSame([...TaskSyncPeopleReassigner::ERASURE_STEPS, 'recurrence'], array_keys(TaskBoardPersonalDataExporter::ERASURE_SECTIONS));
        $sections = [...array_values(array_unique(TaskBoardPersonalDataExporter::ERASURE_SECTIONS)), ...TaskBoardPersonalDataExporter::EXTRA_SECTIONS];
        $all      = TaskBoardPersonalDataExporter::SECTIONS;
        sort($sections);
        sort($all);
        self::assertSame($all, $sections);

        $document = $this->exporter()->export($this->user('nobody@example.com'));
        $keys     = array_keys($document);
        sort($keys);
        self::assertSame($all, $keys);
    }

    public function testTheExportListsWhatTheErasureDetachesAndNothingAfterIt(): void
    {
        $bob = $this->fixture();

        $document = $this->exporter()->export($bob);
        foreach (TaskBoardPersonalDataExporter::SECTIONS as $section) {
            self::assertNotSame([], $document[$section], $section);
        }
        self::assertSame('Bob board', $document['boards'][0]['name']);
        self::assertSame('Bob task', $document['tasks_created'][0]['title']);
        self::assertSame('assignee', $document['task_memberships'][0]['role']);
        self::assertEqualsCanonicalizing([['created'], ['started']], array_column($document['import_plans'], 'roles'));
        self::assertSame('field-1', $document['custom_field_values'][0]['field_id']);
        self::assertSame('assignee', $document['member_history'][0]['role']);
        $json = (string) json_encode($document);
        self::assertStringNotContainsString('conn-secret-ref', $json, 'never a connection reference');
        self::assertStringNotContainsString('Body of a comment', $json, 'no bodies');

        $reassigner = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);
        $reassigner->detachUser($bob, $this->owner, true);
        $recurrence = self::getContainer()->get(TaskRecurrenceManager::class);
        self::assertInstanceOf(TaskRecurrenceManager::class, $recurrence);
        $recurrence->forgetAuthor($bob);
        $this->em->clear();

        $after = $this->exporter()->export($this->em->find(AppUser::class, $bob->getId()) ?? $bob);
        foreach (TaskBoardPersonalDataExporter::ERASURE_SECTIONS as $step => $section) {
            self::assertSame([], $after[$section], $step . ' => ' . $section);
        }
        // What the database removes with the user's row is still theirs until then.
        self::assertNotSame([], $after['task_memberships']);
    }

    public function testAUserWithoutAnIdHasEmptySections(): void
    {
        $document = $this->exporter()->export(new stdClass());
        self::assertSame([], $document['boards']);
    }

    private function fixture(): AppUser
    {
        $bob   = $this->user('bob@example.com');
        $board = $this->board('Bob board', creator: $bob);
        $task  = new Task(board: $board, title: 'Bob task', creator: $bob, column: $board->getColumns()->first() ?: null);
        $task->recordWrite($bob, null);
        $this->em->persist($task);
        $task->addMember(new TaskMember($task, $bob, TaskMemberRole::Assignee));
        $comment = new TaskComment($task, $bob, 'Body of a comment');
        $this->em->persist($comment);
        $this->em->persist(new TaskDocument($task, 'Bob doc', 'text', $bob));
        $this->em->persist(new TaskChangeHistory($task, $bob, TaskChangeType::Title, 'a', 'b'));
        $this->em->persist(new TaskChangeHistory($task, $this->owner, TaskChangeType::MemberAdded, null, 'bob@example.com', 'assignee user:' . $bob->getId()));
        $this->em->persist(new TaskChangeHistory($task, $bob, TaskChangeType::CustomField, null, 'Blue', 'field:field-1 Colour'));
        $this->em->persist(new TaskTimeEntry($task, $bob, 30, new DateTimeImmutable('2026-11-01')));
        $question = new TaskQuestion($task, 'Which?', null, TaskQuestionChoiceMode::Single, false, $bob, new DateTimeImmutable());
        $question->addRecipient(new TaskQuestionRecipient($question, 'user:' . $bob->getId(), 'Bob'));
        $this->em->persist($question);
        $this->em->persist(new TaskRecurrence($board, $task, $bob, 'fixed', 'week', 1, new DateTimeImmutable('2026-11-02')));
        $this->em->persist(new TaskSyncImportPlan(null, 'clickup', 'conn-secret-ref', 'Team token', (string) $bob->getId(), new DateTimeImmutable()));
        $started = new TaskSyncImportPlan(null, 'clickup', 'conn-secret-ref', 'Team token', null, new DateTimeImmutable());
        (new ReflectionProperty(TaskSyncImportPlan::class, 'startedById'))->setValue($started, (string) $bob->getId());
        $this->em->persist($started);
        $directory = new TaskSyncDirectory('clickup', 'project:test', '9000001');
        $this->em->persist($directory);
        $person = new TaskSyncPerson($directory, 'r-1', 'Bob remote', new DateTimeImmutable());
        $person->map(TaskSyncPersonMapping::Manual, $bob, null, new DateTimeImmutable());
        $this->em->persist($person);
        $this->em->flush();

        return $bob;
    }

    private function exporter(): TaskBoardPersonalDataExporterInterface
    {
        $exporter = self::getContainer()->get(TaskBoardPersonalDataExporterInterface::class);
        self::assertInstanceOf(TaskBoardPersonalDataExporterInterface::class, $exporter);

        return $exporter;
    }
}
