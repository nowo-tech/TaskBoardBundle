<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskClientApiDeniedEvent;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function str_repeat;

/**
 * Since 3.4.0 (client API v1): authentication, scopes, contexts, budgets, the answers' headers and the refusal events
 * (§3, §4.1, §4.6 of the Beacon Desktop tasks design).
 */
final class ClientApiAccessTest extends ClientApiTestCase
{
    /** @var list<TaskClientApiDeniedEvent> */
    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->denied = [];
        $events       = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(TaskBoardEvents::CLIENT_API_DENIED, function (TaskClientApiDeniedEvent $event): void {
            $this->denied[] = $event;
        });
    }

    public function testNoTokenAnUnknownOneAndAnOverlongOneAreTheSame401(): void
    {
        foreach ([null, 'unknown', str_repeat('x', 4097)] as $token) {
            $this->call('GET', '/me', token: $token);
            $this->assertError(401, 'invalid_token');
            self::assertSame('Bearer error="invalid_token"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
        // An overlong token never reaches the host's resolver.
        self::assertSame(['unknown'], ClientApiTestHost::$resolved);
        self::assertSame(['invalid_token', 'invalid_token', 'invalid_token'], array_map(static fn (TaskClientApiDeniedEvent $event): string => $event->reason, $this->denied));
        self::assertNull($this->denied[0]->tokenId);
    }

    public function testTheResolverIsAskedOncePerRequestAndTheAnswerIsNeverACookie(): void
    {
        $this->call('GET', '/me');
        self::assertResponseIsSuccessful();
        self::assertSame([self::CLIENT_TOKEN], ClientApiTestHost::$resolved);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('99', $this->client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testAReadTokenMayNotWriteAndAWriteOnlyTokenMayNotRead(): void
    {
        $this->clientToken([TaskClientPrincipal::SCOPE_READ]);
        $this->call('POST', '/tasks', ['boardId' => 'x', 'title' => 'No'], ['HTTP_IDEMPOTENCY_KEY' => 'k1']);
        $this->assertError(403, 'insufficient_scope');
        self::assertSame('Bearer error="insufficient_scope", scope="task-board:write"', $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $this->clientToken([TaskClientPrincipal::SCOPE_WRITE]);
        $this->call('GET', '/me');
        $this->assertError(403, 'insufficient_scope');
        self::assertSame('tok-' . self::CLIENT_TOKEN, $this->denied[1]->tokenId);
        self::assertSame((string) $this->owner->getId(), $this->denied[1]->userId);
    }

    public function testTheContextIsRequiredWellFormedAndAllowed(): void
    {
        $this->call('GET', '/me', context: null);
        $this->assertError(400, 'context_required');

        $this->call('GET', '/me', context: 'bad context!');
        $this->assertError(400, 'invalid_context');

        $this->call('GET', '/me', context: str_repeat('a', 129));
        $this->assertError(400, 'invalid_context');

        // Unknown and not a member: one answer.
        $this->call('GET', '/me', context: 'org:other');
        $this->assertError(403, 'context_forbidden');

        $this->call('GET', '/me', context: 'org:acme');
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'org:acme', 'label' => 'Org org:acme'], $this->json()['context']);
    }

    public function testATokenBoundToAContextNeverOpensAnother(): void
    {
        $this->clientToken(bound: 'org:acme');
        $this->call('GET', '/me', context: null);
        self::assertResponseIsSuccessful();
        self::assertSame('org:acme', $this->json()['context']['key']);

        $this->call('GET', '/me', context: 'org:acme');
        self::assertResponseIsSuccessful();

        $this->call('GET', '/me', context: 'personal');
        $this->assertError(403, 'context_forbidden');

        // The membership is checked again on every request.
        ClientApiTestHost::$contexts['owner@example.com'] = ['personal' => ['tenant-a']];
        $this->call('GET', '/me', context: null);
        $this->assertError(403, 'context_forbidden');
    }

    public function testThePersonsBudgetAnswers429WithRetryAfter(): void
    {
        ApiTestHost::$tokenLimitAccepts = false;
        $this->call('GET', '/me');
        $this->assertError(429, 'rate_limited');
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame('0', $this->client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testAPersonWhoMayNoLongerUseTheBoardsGetsModuleForbiddenNotASignOut(): void
    {
        $this->user('blocked@example.com', []);
        $this->clientToken(email: 'blocked@example.com');
        $this->call('GET', '/me');
        $this->assertError(403, 'module_forbidden');
        $response = $this->client->getResponse();
        self::assertSame('tasks', $this->json()['module'] ?? null);
        self::assertSame('Tasks is not available for this account.', $this->json()['message'] ?? null);
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('account_inactive', $this->denied[0]->reason);
    }

    public function testAnUnknownTokenIsStillA401WhateverThePersonMayDo(): void
    {
        // No oracle: the module refusal exists only behind a valid token.
        $this->call('GET', '/me', token: 'unknown-token');
        $this->assertError(401, 'invalid_token');
        self::assertArrayNotHasKey('module', $this->json());
    }

    public function testAnUnknownPathUnderThePrefixIsTheApisOwn404(): void
    {
        $this->call('GET', '/nothing-here');
        $this->assertError(404, 'not_found');
        $this->call('DELETE', '/me');
        $this->assertError(404, 'not_found');
    }

    public function testAnIdThatIsNotOneIsNotFoundAndLeavesATrace(): void
    {
        $this->call('GET', '/boards/' . str_repeat('b', 64));
        $this->assertError(404, 'not_found');
        self::assertSame('not_found', $this->denied[0]->reason);
        self::assertSame('tok-' . self::CLIENT_TOKEN, $this->denied[0]->tokenId);
    }
}
