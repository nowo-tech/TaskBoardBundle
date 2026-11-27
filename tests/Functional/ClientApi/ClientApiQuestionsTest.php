<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_column;

/**
 * Since 3.4.0 (client API v1, `task_questions.enabled`): a task's questions with `context` as tokens/1 and
 * `waitingForMe`, the "Waiting for answer" inbox, and the counts on the cards.
 */
final class ClientApiQuestionsTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        self::$hostConfig = ['task_questions' => ['enabled' => true]];
        parent::setUp();
    }

    public function testQuestionsAddressedToTheCaller(): void
    {
        $board = $this->board('Roadmap', tenantKey: 'tenant-a');
        $task  = $this->task($board, 'Decide');
        $other = $this->task($board, 'Quiet');
        $fresh = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);
        $question = new TaskQuestion($fresh, 'Which **one**?', 'See #[x](issue:1)', TaskQuestionChoiceMode::Single, false, $this->em->find(AppUser::class, $this->owner->getId()), new DateTimeImmutable());
        $question->addRecipient(new TaskQuestionRecipient($question, 'user:' . $this->owner->getId(), 'Owner'));
        $this->em->persist($question);
        $this->em->flush();

        $this->call('GET', '/tasks/' . $task->getId() . '/questions');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $row = $this->json()['questions'][0];
        self::assertSame(['tokens/1', true], [$row['context']['format'], $row['waitingForMe']]);
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $row['context']['blocks'][0]['tokens'][1]);

        $this->call('GET', '/tasks/' . $task->getId() . '/questions?state=answered');
        self::assertSame([], $this->json()['questions']);
        $this->call('GET', '/tasks/' . $task->getId() . '/questions?state=maybe');
        $this->assertError(400, 'invalid_field');

        $this->call('GET', '/questions/waiting');
        self::assertSame([$question->getId()], array_column($this->json()['questions'], 'id'));

        $this->call('GET', '/boards/' . $board->getId() . '/tasks?sort=number');
        $cards = [];
        foreach ($this->json()['tasks'] as $card) {
            $cards[$card['title']] = [$card['openQuestions'], $card['waitingForMe']];
        }
        self::assertSame([1, true], $cards['Decide']);
        self::assertSame([0, false], $cards[$other->getTitle()]);
        $this->call('GET', '/me');
        self::assertTrue($this->json()['features']['questions']);
    }
}
