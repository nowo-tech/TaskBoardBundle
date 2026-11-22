<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionQuery;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientCandidate;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_map;

/**
 * Beacon spec 242 (REQ-ARCH-001, both host kinds): on the DDD host (plain PHP users, XML mapping, private entity
 * manager, user references by id) questions are asked, answered (also through an external link) and read through the
 * framework-free recipient port, with the users stored as ids.
 */
final class DddHostQuestionsTest extends KernelTestCase
{
    private TaskBoardEntityManager $em;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DddHostKernel(['task_questions' => ['enabled' => true, 'recipient_resolver' => 'app.ddd_question_recipients']]);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public function testQuestionsGoThroughTheFrameworkFreePortWithUsersById(): void
    {
        $container = self::getContainer();
        $ana       = DddUsers::all()['u-ana'];
        $tasks     = $container->get(TaskManagerInterface::class);
        $creator   = $container->get(TaskBoardCreatorInterface::class);
        $manager   = $container->get(TaskQuestionManager::class);
        $query     = $container->get(TaskQuestionQuery::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);
        self::assertInstanceOf(TaskBoardCreatorInterface::class, $creator);
        self::assertInstanceOf(TaskQuestionManager::class, $manager);
        self::assertInstanceOf(TaskQuestionQuery::class, $query);
        $board = $creator->create(new TaskBoardFormData('Domain board', 'domain-board'), $ana);
        $task  = $tasks->create($board, new TaskFormData('Story'), $ana);
        $this->em->flush();

        // The look-alike id the resolver offers is dropped; `U-ANA` is another person (ids are compared byte for byte).
        self::assertSame(['member:u-bruno', 'member:U-ANA', 'contact:42'], array_map(static fn (TaskQuestionRecipientCandidate $c): string => $c->id, $manager->candidates($ana, $task)));
        $question = $manager->ask($ana, $task, new TaskQuestionInput('Ship on Friday?', ['member:u-bruno', 'contact:42'], [new TaskQuestionOptionInput('Yes', null, true), new TaskQuestionOptionInput('No')]));
        $id       = $question->getId();
        $yes      = $question->getOptions()[0]->getId();
        $manager->answerAsSubject($id, 'member:u-bruno', 'Bruno', new TaskQuestionAnswerInput([$yes]));
        $manager->answerAsSubject($id, 'contact:42', 'Client', new TaskQuestionAnswerInput([$question->getOptions()[1]->getId()]), 2);
        $this->em->clear();

        $loaded = $manager->find($id);
        self::assertInstanceOf(TaskQuestion::class, $loaded);
        self::assertSame(TaskQuestionState::Answered, $loaded->getState());
        self::assertInstanceOf(TaskBoardUserRef::class, $loaded->getAskedBy());
        self::assertSame('u-ana', $loaded->getAskedBy()->id);
        self::assertSame(['member:u-bruno', 'contact:42'], array_map(static fn (TaskQuestionAnswer $a): string => $a->getAnsweredBySubject(), $loaded->getAnswers()));
        $snapshot = TaskQuestionSnapshot::of($loaded);
        self::assertSame('u-ana', $snapshot->askedById);
        self::assertSame(['No'], $snapshot->currentAnswer?->optionLabels);

        $answered = $query->answeredOnBoard($board->getId());
        self::assertSame([$id], array_map(static fn (TaskQuestionSnapshot $s): string => $s->id, $answered));
        $task = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $task);
        self::assertSame([], $query->openCounts([$task->getId()]));
        self::assertSame([], $query->openAskedBy($ana));

        self::assertSame(1, $manager->detachUser($ana, true));
    }
}
