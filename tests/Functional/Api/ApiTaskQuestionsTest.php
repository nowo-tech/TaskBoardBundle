<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiQuestionOperations;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_column;

/**
 * Beacon spec 242: the question routes of the Task API — list, ask, answer, withdraw, the answered questions of a list,
 * the token user's open questions; 404 alike for what is out of reach, 409 `CONFLICT_002` for a racing answer.
 */
final class ApiTaskQuestionsTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private Task $task;

    private AppUser $bob;

    protected function setUp(): void
    {
        self::$hostConfig = self::$hostConfig !== [] ? self::$hostConfig : ['task_questions' => ['enabled' => true]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->issueToken();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->task    = $this->task($this->roadmap, 'Pick the hosting');
        $this->bob     = $this->user('bob@example.com');
        $this->task->addMember(new TaskMember($this->task, $this->bob, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->em->refresh($this->roadmap);
        $this->issueToken(email: 'bob@example.com', token: 'bt_bob');
    }

    public function testAskAnswerSupersedeAndTheRace(): void
    {
        $base = '/api/tasks/v2/task/' . $this->task->getId() . '/question';
        $this->send('POST', $base, [
            'text'       => 'Which hosting?',
            'context'    => '**Markdown** stays Markdown',
            'options'    => [['label' => 'Hetzner', 'description' => 'EU', 'recommended' => true], ['label' => 'AWS']],
            'recipients' => ['user:' . $this->bob->getId()],
            'due_date'   => '2099-01-31',
            'surprise'   => 1,
        ]);
        self::assertResponseIsSuccessful();
        $question = $this->json();
        self::assertSame('open', $question['state']);
        self::assertSame('**Markdown** stays Markdown', $question['context']);
        self::assertSame('2099-01-31', $question['due_date']);
        self::assertSame(['Hetzner', 'AWS'], array_column($question['options'], 'label'));
        self::assertSame([true, false], array_column($question['options'], 'recommended'));
        self::assertSame(1, $question['version']);
        self::assertNotEmpty($question['warnings']);
        $id      = $question['id'];
        $hetzner = $question['options'][0]['id'];
        $aws     = $question['options'][1]['id'];

        $this->get($base);
        self::assertSame([$id], $this->idsOf('questions'));
        $this->get($base . '?state=answered');
        self::assertSame([], $this->idsOf('questions'));
        $this->get($base . '?state=nope');
        self::assertResponseStatusCodeSame(400);

        // The owner is no recipient.
        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['options' => [$hetzner]]);
        $this->assertCode(403, 'QUESTION_004');

        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['options' => [$hetzner]], token: 'bt_bob');
        self::assertResponseIsSuccessful();
        self::assertSame('answered', $this->json()['state']);
        self::assertSame([$hetzner], $this->json()['answer']['options']);
        self::assertSame('api', $this->json()['answer']['via']);

        // The racing second answer: 409 CONFLICT_002 with the current version, no Retry-After.
        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['options' => [$aws]], token: 'bt_bob');
        $this->assertCode(409, 'CONFLICT_002');
        self::assertSame(2, $this->json()['current_version']);
        self::assertFalse($this->client->getResponse()->headers->has('Retry-After'));

        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['options' => [$aws], 'version' => 2], token: 'bt_bob');
        self::assertResponseIsSuccessful();
        self::assertSame([$aws], $this->json()['answer']['options']);
        self::assertCount(2, $this->json()['answers']);
        self::assertNotNull($this->json()['answers'][1]['superseded_at']);

        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['options' => ['bogus'], 'version' => 3], token: 'bt_bob');
        $this->assertCode(400, 'QUESTION_002');

        // The answered questions of the list (a Decisions view).
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/question');
        self::assertSame([$id], $this->idsOf('questions'));
        self::assertTrue($this->json()['last_page']);
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/question?state=answered&answered_since=0&start_id=' . $id);
        self::assertSame([], $this->idsOf('questions'));
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/question?answered_since=yesterday');
        self::assertResponseStatusCodeSame(400);
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/question?state=open');
        self::assertResponseStatusCodeSame(400);

        $this->send('POST', '/api/tasks/v2/question/' . $id . '/withdraw', ['reason' => 'Done', 'version' => 1]);
        $this->assertCode(409, 'CONFLICT_002');
        $this->send('POST', '/api/tasks/v2/question/' . $id . '/withdraw', ['reason' => 'Done']);
        self::assertResponseIsSuccessful();
        self::assertSame('Done', $this->json()['withdrawn']['reason']);
        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['options' => [$aws], 'version' => 4], token: 'bt_bob');
        $this->assertCode(409, 'QUESTION_003');
    }

    public function testTheInputAndTheReachAreChecked(): void
    {
        $base = '/api/tasks/v2/task/' . $this->task->getId() . '/question';
        foreach ([
            [['text' => 'Q', 'recipients' => ['user:' . $this->bob->getId()], 'options' => [['label' => 7]]], 400, 'QUESTION_001'],
            [['text' => 'Q', 'recipients' => ['user:' . $this->bob->getId()], 'choice' => 'any'], 400, 'QUESTION_001'],
            [['text' => 'Q', 'recipients' => ['user:' . $this->bob->getId()], 'due_date' => '31/01/2099'], 400, 'QUESTION_001'],
            [['text' => 'Q', 'recipients' => ['user:999']], 400, 'QUESTION_001'],
            [['text' => 'Q', 'recipients' => 'user:1'], 400, 'INPUT_002'],
        ] as [$body, $status, $code]) {
            $this->send('POST', $base, $body);
            $this->assertCode($status, $code);
        }

        // Read again: the requests above ended their unit of work.
        $task = $this->em->find(Task::class, $this->task->getId());
        self::assertInstanceOf(Task::class, $task);
        $task->archive();
        $this->em->flush();
        $this->send('POST', $base, ['text' => 'Q', 'recipients' => ['user:' . $this->bob->getId()]]);
        $this->assertCode(409, 'QUESTION_005');

        $this->send('POST', '/api/tasks/v2/task/missing/question', ['text' => 'Q', 'recipients' => []]);
        $this->assertCode(404, 'ACCESS_001');
        $this->send('POST', '/api/tasks/v2/question/missing/answer', ['options' => []]);
        $this->assertCode(404, 'ACCESS_001');
        $this->send('POST', '/api/tasks/v2/question/missing/withdraw', ['reason' => 'x']);
        $this->assertCode(404, 'ACCESS_001');

        // A read-only token may list, not ask.
        $this->issueToken(TaskApiLevel::Read, token: 'bt_read');
        $this->get($base, 'bt_read');
        self::assertResponseIsSuccessful();
        $this->send('POST', $base, ['text' => 'Q', 'recipients' => ['user:' . $this->bob->getId()]], token: 'bt_read');
        $this->assertCode(403, 'TOKEN_001');
    }

    public function testAQuestionOutOfReachIsNotFound(): void
    {
        $this->send('POST', '/api/tasks/v2/task/' . $this->task->getId() . '/question', ['text' => 'Q', 'options' => [['label' => 'A'], ['label' => 'B']], 'choice' => 'multiple', 'allow_other' => true, 'recipients' => ['user:' . $this->bob->getId()]]);
        self::assertResponseIsSuccessful();
        $id = $this->json()['id'];
        // A token limited to another tenant does not see it.
        $this->issueToken(where: TaskApiWhere::limitedTo(['tenant-b'], []), token: 'bt_b');
        $this->send('POST', '/api/tasks/v2/question/' . $id . '/answer', ['other_text' => 'x'], token: 'bt_b');
        $this->assertCode(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/task/' . $this->task->getId() . '/question', 'bt_b');
        $this->assertCode(404, 'ACCESS_001');
    }

    public function testMyOpenQuestions(): void
    {
        $this->send('POST', '/api/tasks/v2/task/' . $this->task->getId() . '/question', ['text' => 'Free text?', 'recipients' => ['user:' . $this->bob->getId()]]);
        $id = $this->json()['id'];
        $this->get('/api/tasks/v2/user/question', 'bt_bob');
        self::assertSame([$id], $this->idsOf('questions'));
        $this->get('/api/tasks/v2/user/question');
        self::assertSame([], $this->idsOf('questions'));

        // In process (the host's MCP server), with a limit.
        $operations = self::getContainer()->get(TaskApiQuestionOperations::class);
        self::assertInstanceOf(TaskApiQuestionOperations::class, $operations);
        $principal = new TaskApiPrincipal($this->bob, TaskApiLevel::Full, TaskApiWhere::everything(), 'mcp', 'MCP', TaskApiPrincipal::CHANNEL_MCP);
        self::assertCount(1, $operations->myQuestions($principal, 1));
        $this->send('POST', '/api/tasks/v2/task/' . $this->task->getId() . '/question', ['text' => 'Second?', 'recipients' => ['user:' . $this->bob->getId()]]);
        self::assertCount(1, $operations->myQuestions($principal, 1));
        self::assertCount(2, $operations->myQuestions($principal, 5));
    }

    private function assertCode(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->json()['ECODE'] ?? null);
    }
}
