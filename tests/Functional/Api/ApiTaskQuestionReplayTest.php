<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use RuntimeException;

/**
 * Beacon spec 242 (2.22.0's write-unit rule): the question writes of the Task API survive a replay of
 * nowo-tech/transaction-doctrine's write unit. The first insert of the request meets a deadlock inside the flush; the unit
 * rolls it back, resets the manager and runs the controller again, which reads everything again by id and writes the
 * principal's user as the manager manages it now. Exactly one question, one answer and one activity entry each.
 */
final class ApiTaskQuestionReplayTest extends ApiTestCase
{
    private static int $deadlocks = 0;

    private Task $task;

    private AppUser $bob;

    protected function setUp(): void
    {
        self::$hostConfig = ['__write_units' => true, 'task_questions' => ['enabled' => true]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->task           = $this->task($board, 'Pick the hosting');
        $this->bob            = $this->user('bob@example.com');
        $this->task->addMember(new TaskMember($this->task, $this->bob, TaskMemberRole::Assignee));
        $this->em->flush();
        // The board's tasks as a request reads them (the default recipients are the task's mention candidates).
        $this->em->refresh($board);
        $this->issueToken();
        $this->issueToken(email: 'bob@example.com', token: 'bt_bob');
        self::$deadlocks = 0;
    }

    public function testAnAskAndAnAnswerAreReplayedOnceWithManagedActors(): void
    {
        $this->deadlockOnce();
        $this->send('POST', '/api/tasks/v2/task/' . $this->task->getId() . '/question', [
            'text'       => 'Which hosting?',
            'options'    => [['label' => 'Hetzner', 'recommended' => true], ['label' => 'AWS']],
            'recipients' => ['user:' . $this->bob->getId()],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, self::$deadlocks, 'the first attempt met the deadlock');
        $question = $this->json();

        $this->em->clear();
        $questions = $this->em->getRepository(TaskQuestion::class)->findBy(['task' => $this->task->getId()]);
        self::assertCount(1, $questions, 'the replay asked once');
        self::assertSame($this->owner->getId(), $questions[0]->getAskedBy()?->getId());

        self::$deadlocks = 0;
        $this->deadlockOnce();
        $this->send('POST', '/api/tasks/v2/question/' . $question['id'] . '/answer', ['options' => [$question['options'][0]['id']]], [], 'bt_bob');
        self::assertResponseIsSuccessful();
        self::assertSame(1, self::$deadlocks);
        self::assertSame(2, $this->json()['version'], 'one answer, one version step');

        $this->em->clear();
        $answers = $this->em->getRepository(TaskQuestionAnswer::class)->findBy(['question' => $question['id']]);
        self::assertCount(1, $answers, 'the replay answered once');
        self::assertSame($this->bob->getId(), $answers[0]->getAnsweredBy()?->getId());
        $entries = $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $this->task->getId(), 'changeType' => TaskChangeType::QuestionAnswered]);
        self::assertCount(1, $entries);
        self::assertSame($this->bob->getId(), $entries[0]->getUser()?->getId());
    }

    /** The first insert of the request fails with a deadlock, inside the flush, as a concurrent writer would make it. */
    private function deadlockOnce(): void
    {
        $this->em->getEventManager()->addEventListener(Events::postPersist, new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if (ApiTaskQuestionReplayTest::deadlock()) {
                    throw new DeadlockException(new class('Deadlock found when trying to get lock') extends RuntimeException implements DriverException {
                        public function getSQLState(): string
                        {
                            return '40P01';
                        }
                    }, null);
                }
            }
        });
    }

    public static function deadlock(): bool
    {
        if (self::$deadlocks > 0) {
            return false;
        }
        ++self::$deadlocks;

        return true;
    }
}
