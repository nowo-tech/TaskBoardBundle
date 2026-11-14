<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;

use function array_intersect_key;
use function array_key_exists;
use function str_starts_with;

/**
 * Since 2.19.0 (Beacon spec 234): the Task API answers carry `created_by`, `updated_by`, `channel` and `token_name` on
 * tasks and comments (additive: every other field unchanged); null without a provenance provider. The channel is the
 * host's (from the path it serves), never the client's; the label is plain text in the JSON.
 */
final class ApiProvenanceTest extends ApiTestCase
{
    private const LABEL = '<b>CI</b> "deploy" token';

    protected function setUp(): void
    {
        PathWriteProvenance::reset();
        self::$hostConfig = str_starts_with($this->name(), 'testWithoutAProvider') ? [] : ['write_provenance_provider' => 'app.write_provenance'];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    public function testWithoutAProviderTheFieldsAreThereAndNull(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $task                 = $this->task($this->board('Roadmap', tenantKey: 'tenant-a'), 'Own');
        $this->issueToken();

        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame(['created_by' => 'uuid-' . $this->owner->getId(), 'updated_by' => null, 'channel' => null, 'token_name' => null], array_intersect_key($answer, ['created_by' => 1, 'updated_by' => 1, 'channel' => 1, 'token_name' => 1]));
        self::assertSame('uuid-' . $this->owner->getId(), $answer['creator']['id'] ?? null, 'the existing fields stay');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Renamed', 'channel' => 'mcp']);
        self::assertResponseIsSuccessful();
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame('uuid-' . $this->owner->getId(), $this->json()['updated_by']);
        self::assertNull($this->json()['channel']);
        self::assertNull($this->json()['token_name']);
    }

    public function testTheHostsChannelAndTokenNameAreStoredAndAnswered(): void
    {
        ApiTestHost::$tenants       = ['tenant-a'];
        $task                       = $this->task($this->board('Roadmap', tenantKey: 'tenant-a'), 'Own');
        PathWriteProvenance::$label = self::LABEL;
        $this->issueToken();

        // A channel the client sends is ignored: the host decides from the path.
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId() . '?channel=web', ['name' => 'Through the API', 'channel' => 'web', 'token_name' => 'forged'], ['HTTP_X_CHANNEL' => 'web']);
        self::assertResponseIsSuccessful();
        $this->get('/api/tasks/v2/task/' . $task->getId());
        $answer = $this->json();
        self::assertSame('uuid-' . $this->owner->getId(), $answer['created_by']);
        self::assertSame('uuid-' . $this->owner->getId(), $answer['updated_by']);
        self::assertSame('api', $answer['channel']);
        self::assertSame(self::LABEL, $answer['token_name'], 'plain text, json_encode only');
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<b>', $raw, 'JSON escapes markup characters');
        self::assertStringNotContainsString('&lt;', $raw, 'no HTML entities in the value');
        self::assertStringNotContainsString(ApiTestCase::TOKEN, $raw, 'never the secret');

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/comment', ['comment_text' => 'Looks good']);
        self::assertResponseIsSuccessful();
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy(['task' => $task]);
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertSame(WriteChannel::Api, $comment->getChannel());

        $this->get('/api/tasks/v2/task/' . $task->getId() . '/comment');
        self::assertResponseIsSuccessful();
        $comments = $this->listOf('comments');
        self::assertCount(1, $comments);
        self::assertSame('uuid-' . $this->owner->getId(), $comments[0]['created_by']);
        self::assertSame('uuid-' . $this->owner->getId(), $comments[0]['updated_by']);
        self::assertSame('api', $comments[0]['channel']);
        self::assertSame(self::LABEL, $comments[0]['token_name']);
        self::assertTrue(array_key_exists('user', $comments[0]), 'the existing fields stay');
    }
}
