<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientAuthenticator;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskClientApiDeniedEvent;
use Nowo\TaskBoardBundle\Event\TaskClientLoggedOutEvent;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;

/**
 * Since 3.7.0 (Beacon Desktop BDA13-L2): `POST logout` revokes the presented token through the host's revoker, and only
 * it; it is idempotent, never an oracle, and a revoked token fails every later call.
 */
final class ClientApiLogoutTest extends ClientApiTestCase
{
    protected const CLIENT_CONFIG = parent::CLIENT_CONFIG + ['token_revoker' => 'app.client_host'];

    /** @var list<TaskClientLoggedOutEvent> */
    private array $loggedOut = [];

    /** @var list<TaskClientApiDeniedEvent> */
    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loggedOut = [];
        $this->denied    = [];
        $events          = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(TaskBoardEvents::CLIENT_API_LOGGED_OUT, function (TaskClientLoggedOutEvent $event): void {
            $this->loggedOut[] = $event;
        });
        $events->addListener(TaskBoardEvents::CLIENT_API_DENIED, function (TaskClientApiDeniedEvent $event): void {
            $this->denied[] = $event;
        });
    }

    public function testLogoutRevokesThePresentedTokenOnlyAndTheTokenFailsEveryLaterCall(): void
    {
        $this->clientToken(token: 'desk_other_device');
        $this->call('GET', '/me');
        self::assertTrue($this->json()['features']['serverLogout']);

        $this->call('POST', '/logout', context: null);
        self::assertResponseStatusCodeSame(204);
        $response = $this->client->getResponse();
        self::assertSame('', $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame([[self::CLIENT_TOKEN, 'tok-' . self::CLIENT_TOKEN, null]], ClientApiTestHost::$revoked);
        self::assertCount(1, $this->loggedOut);
        self::assertSame('tok-' . self::CLIENT_TOKEN, $this->loggedOut[0]->tokenId);
        self::assertSame((string) $this->owner->getId(), $this->loggedOut[0]->userId);
        self::assertTrue($this->loggedOut[0]->revoked);
        self::assertSame('127.0.0.0/24', $this->loggedOut[0]->ipPrefix);

        foreach ([['GET', '/me'], ['GET', '/boards'], ['POST', '/tasks']] as [$method, $path]) {
            $this->call($method, $path, $method === 'POST' ? ['boardId' => 'x', 'title' => 'No'] : null, ['HTTP_IDEMPOTENCY_KEY' => 'k1']);
            $this->assertError(401, 'invalid_token');
        }

        // The person's other device keeps its own token.
        $this->call('GET', '/me', token: 'desk_other_device');
        self::assertResponseIsSuccessful();
    }

    public function testLogoutIsIdempotentAndNeverAnOracle(): void
    {
        $this->call('POST', '/logout');
        self::assertResponseStatusCodeSame(204);
        $consumed = ApiTestHost::$consumed;

        foreach ([self::CLIENT_TOKEN, 'never-issued'] as $token) {
            $this->call('POST', '/logout', token: $token);
            self::assertResponseStatusCodeSame(204);
            self::assertSame('', $this->client->getResponse()->getContent());
            self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        }
        self::assertCount(1, ClientApiTestHost::$revoked, 'a refused token is never revoked');
        self::assertCount(1, $this->loggedOut, 'nothing to name, no logout event');
        // Audit ZDX-TB-L2: every refused token is reported and counted against the IP, so probing is visible.
        self::assertSame([TaskClientAuthenticator::REASON_LOGOUT_UNKNOWN_TOKEN, TaskClientAuthenticator::REASON_LOGOUT_UNKNOWN_TOKEN], array_map(static fn (TaskClientApiDeniedEvent $event): string => $event->reason, $this->denied));
        self::assertNull($this->denied[0]->tokenId);
        self::assertSame(['127.0.0.1', '127.0.0.1'], ApiTestHost::$failedIps);
        self::assertSame($consumed, ApiTestHost::$consumed, 'a refused token spends no token budget');
        self::assertSame(['logout:tok-' . self::CLIENT_TOKEN], ApiTestHost::$consumedTokenIds, 'the logout budget is the token\'s own key');
    }

    public function testWithoutABearerTokenItIs401(): void
    {
        $this->call('POST', '/logout', token: null);
        $this->assertError(401, 'invalid_token');
        self::assertSame([], ClientApiTestHost::$revoked);
        self::assertSame(['invalid_token'], array_map(static fn (TaskClientApiDeniedEvent $event): string => $event->reason, $this->denied));
    }

    public function testATokenMayAlwaysBeGivenUpWhateverItsScopesContextOrModule(): void
    {
        $this->clientToken([]);
        $this->call('POST', '/logout', context: 'not a context!');
        self::assertResponseStatusCodeSame(204);

        $this->clientToken([TaskClientPrincipal::SCOPE_READ], token: 'desk_forbidden');
        ClientApiTestHost::$tokens['desk_forbidden']['forbidden'] = true;
        $this->call('POST', '/logout', token: 'desk_forbidden');
        self::assertResponseStatusCodeSame(204);

        $this->user('blocked@example.com', []);
        $this->clientToken(email: 'blocked@example.com', token: 'desk_blocked');
        $this->call('POST', '/logout', token: 'desk_blocked');
        self::assertResponseStatusCodeSame(204);

        self::assertSame([self::CLIENT_TOKEN, 'desk_forbidden', 'desk_blocked'], array_map(static fn (array $revoked): string => $revoked[0], ClientApiTestHost::$revoked));
        self::assertSame([], $this->denied);
    }

    public function testAFailingRevokerIsAnErrorAndTheTokenStaysValid(): void
    {
        ClientApiTestHost::$revokeFails = true;
        $this->call('POST', '/logout');
        $this->assertError(500, 'server_error');
        self::assertSame([], $this->loggedOut);

        ClientApiTestHost::$revokeFails = false;
        $this->call('GET', '/me');
        self::assertResponseIsSuccessful();
    }

    public function testAnIpOverItsFailedTokenBudgetGets429BeforeTheResolver(): void
    {
        ApiTestHost::$ipLimitAccepts = false;
        $this->call('POST', '/logout');
        $this->assertError(429, 'rate_limited');
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], ClientApiTestHost::$resolved, 'the resolver is not asked');
        self::assertSame([], ClientApiTestHost::$revoked);
        self::assertSame(['rate_limited'], array_map(static fn (TaskClientApiDeniedEvent $event): string => $event->reason, $this->denied));
    }

    public function testATokenOverItsLogoutBudgetGets429AndIsNotRevokedYet(): void
    {
        ApiTestHost::$tokenLimitAccepts = false;
        $this->call('POST', '/logout');
        $this->assertError(429, 'rate_limited');
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame([], ClientApiTestHost::$revoked);
        self::assertSame([], $this->loggedOut);

        ApiTestHost::$tokenLimitAccepts = true;
        $this->call('POST', '/logout');
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, ClientApiTestHost::$revoked);
    }

    public function testOtherMethodsOnTheRouteAreNotFound(): void
    {
        $this->call('GET', '/logout');
        $this->assertError(404, 'not_found');
        self::assertSame([], ClientApiTestHost::$revoked);
    }
}
