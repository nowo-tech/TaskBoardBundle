<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionQuery;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Beacon spec 242: a board, a task, an asker (the board's owner) and a recipient (bob, a member of the task, so one of
 * its mention candidates), with `task_questions.enabled`.
 */
abstract class QuestionTestCase extends FunctionalTestCase
{
    protected TaskBoard $roadmap;

    protected Task $task;

    protected AppUser $bob;

    /** @var list<array{0: string, 1: object}> */
    protected array $events = [];

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['task_questions' => ['enabled' => true]];
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'acme');
        $this->task    = $this->task($this->roadmap, 'Pick the hosting');
        $this->bob     = $this->user('bob@example.com');
        $this->task->addMember(new TaskMember($this->task, $this->bob, TaskMemberRole::Assignee));
        $this->em->flush();
        // The board's tasks as a request reads them (the default recipients are the task's mention candidates).
        $this->em->refresh($this->roadmap);
        $this->events = [];
        $dispatcher   = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['nowo_task_board.task_question_asked', 'nowo_task_board.task_question_answered', 'nowo_task_board.task_question_withdrawn', 'nowo_task_board.task_status_changed', 'nowo_task_board.task_completed'] as $name) {
            $dispatcher->addListener($name, function (object $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    protected function manager(): TaskQuestionManager
    {
        $manager = self::getContainer()->get(TaskQuestionManager::class);
        self::assertInstanceOf(TaskQuestionManager::class, $manager);

        return $manager;
    }

    protected function query(): TaskQuestionQuery
    {
        $query = self::getContainer()->get(TaskQuestionQuery::class);
        self::assertInstanceOf(TaskQuestionQuery::class, $query);

        return $query;
    }

    protected function subject(AppUser $user): string
    {
        return 'user:' . $user->getId();
    }

    /**
     * @param list<string>|null $recipients
     */
    protected function ask(?Task $task = null, ?array $recipients = null, TaskQuestionChoiceMode $mode = TaskQuestionChoiceMode::Single, bool $other = false, bool $options = true): TaskQuestion
    {
        return $this->manager()->ask($this->owner, $task ?? $this->task, new TaskQuestionInput(
            text: 'Which hosting?',
            recipients: $recipients ?? [$this->subject($this->bob)],
            options: $options ? [new TaskQuestionOptionInput('Hetzner', 'EU, cheap', true), new TaskQuestionOptionInput('AWS'), new TaskQuestionOptionInput('OVH')] : [],
            choiceMode: $mode,
            allowOther: $other,
            context: "## Why\n\nWe need it **today**.",
        ));
    }

    /** @return list<string> */
    protected function eventNames(): array
    {
        return array_map(static fn (array $event): string => $event[0], $this->events);
    }
}
