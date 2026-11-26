<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\RecordingTransitionPolicy;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\TransientFailureKind;
use RuntimeException;

use function class_exists;
use function json_decode;

/**
 * Since 3.4.0 (client API v1, §8): what the client API does with each answer of the host's idempotency store — a
 * request still running, a store that fails to keep an answer, and a lock conflict after a partial commit (kept, so the
 * same key never writes twice).
 */
final class ClientApiIdempotencyStoreTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        self::$api        = ['hooks' => ['authenticator' => 'app.api_host', 'rate_limiter' => 'app.api_host', 'plan_gate' => 'app.api_host', 'workspace' => 'app.api_host', 'users' => 'app.api_host', 'time_entries' => 'app.api_host', 'attachments' => 'app.api_host', 'members' => 'app.api_host', 'idempotency_store' => 'app.client_host']];
        self::$hostConfig = ['column_transition_policy' => 'app.transition_policy'];
        parent::setUp();
    }

    public function testEachOutcomeOfTheStore(): void
    {
        $board = $this->board('Roadmap', tenantKey: 'tenant-a');
        $body  = ['boardId' => $board->getId(), 'title' => 'Keyed'];

        ClientApiTestHost::$claim = TaskApiIdempotencyOutcome::InFlight;
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'k']);
        $this->assertError(409, 'idempotency_in_flight');

        // The write is done even when the store cannot keep the answer.
        ClientApiTestHost::$claim         = null;
        ClientApiTestHost::$completeFails = true;
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'k2']);
        self::assertResponseStatusCodeSame(201);
        ClientApiTestHost::$completeFails = false;

        // A body-less write with a key (the fingerprint of an empty body).
        $task = $this->task($board, 'Members');
        $this->call('DELETE', '/tasks/' . $task->getId() . '/members/me', null, ['HTTP_IF_MATCH' => '"1"', 'HTTP_IDEMPOTENCY_KEY' => 'k3']);
        self::assertResponseIsSuccessful();

        // A lock conflict after part of the write was committed: the answer is kept for the key. ConcurrencyConflict exists
        // from nowo-tech/transaction-contract 1.0.1 on (the dependency floor job installs 1.0.0).
        if (!class_exists(ConcurrencyConflict::class)) {
            self::markTestSkipped('nowo-tech/transaction-contract < 1.0.1 has no ConcurrencyConflict.');
        }
        $doing = $board->getColumns()->get(1);
        self::assertNotNull($doing);
        RecordingTransitionPolicy::$decider = static fn (): never => throw new ConcurrencyConflict(2, TransientFailureKind::Deadlock, null, new RuntimeException('deadlock'), true);
        $this->call('POST', '/tasks/' . $task->getId() . '/move', ['columnId' => $doing->getId()], ['HTTP_IF_MATCH' => '"1"', 'HTTP_IDEMPOTENCY_KEY' => 'k4']);
        $this->assertError(409, 'partial');
        $kept = ClientApiTestHost::$completed[array_key_last(ClientApiTestHost::$completed)];
        self::assertSame([409, 'partial'], [$kept[0], json_decode($kept[1], true)['error'] ?? null]);
    }
}
