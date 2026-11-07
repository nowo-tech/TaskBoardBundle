<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiKernel;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Throwable;

use function json_encode;

/**
 * Spec 213: what every API request goes through, and the way every answer leaves. Refusals share one shape.
 */
final class ApiPipelineTest extends ApiTestCase
{
    public function testAKnownTokenReadsItsIdentityWithTheRateHeaders(): void
    {
        $this->issueToken();

        $this->get('/api/tasks/v2/user');

        self::assertResponseIsSuccessful();
        self::assertSame(['user' => [
            'id'             => 'uuid-' . $this->owner->getId(),
            'username'       => 'owner@example.com',
            'email'          => 'owner@example.com',
            'color'          => null,
            'profilePicture' => null,
        ]], $this->json());
        $headers = $this->client->getResponse()->headers;
        self::assertSame('100', $headers->get('X-RateLimit-Limit'));
        self::assertSame('99', $headers->get('X-RateLimit-Remaining'));
        self::assertSame('1790000000', $headers->get('X-RateLimit-Reset'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertSame(1, ApiTestHost::$consumed);
    }

    public function testTheBearerFormWorksToo(): void
    {
        $this->issueToken();

        $this->get('/api/tasks/v2/user', 'Bearer ' . self::TOKEN);

        self::assertResponseIsSuccessful();
    }

    public function testTeamsAnswerInClickUpsShape(): void
    {
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->get('/api/tasks/v2/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['teams' => [
            ['id' => 'team-1', 'name' => 'Personal', 'color' => '#000000', 'avatar' => 'https://example.com/a.png', 'members' => []],
            ['id' => 'team-2', 'name' => 'Acme', 'color' => null, 'avatar' => null, 'members' => []],
        ]], $this->json());
    }

    public function testNoCredentialIsNotAFailedToken(): void
    {
        $this->get('/api/tasks/v2/user', null);

        $this->assertRefused(401, 'OAUTH_017');
        self::assertSame([], ApiTestHost::$failedIps);
    }

    public function testAnEmptyHeaderIsNoCredential(): void
    {
        $this->get('/api/tasks/v2/user', 'Bearer   ');

        $this->assertRefused(401, 'OAUTH_017');
    }

    public function testACredentialInTheQueryStringIsIgnored(): void
    {
        $this->issueToken();

        $this->get('/api/tasks/v2/user?token=' . self::TOKEN . '&access_token=' . self::TOKEN . '&Authorization=' . self::TOKEN, null);

        $this->assertRefused(401, 'OAUTH_017');
    }

    public function testAnUnknownTokenIsAFailedToken(): void
    {
        $this->get('/api/tasks/v2/user', 'bt_unknown');

        $this->assertRefused(401, 'OAUTH_019');
        self::assertSame(['127.0.0.1'], ApiTestHost::$failedIps);
        self::assertSame(0, ApiTestHost::$consumed);
    }

    public function testAnIpOverTheFailedTokenLimitIsRefusedEvenWithAGoodToken(): void
    {
        $this->issueToken();
        ApiTestHost::$ipLimitAccepts = false;

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(429, 'RATE_002');
        self::assertSame('1790000060', $this->client->getResponse()->headers->get('X-RateLimit-Reset'));
        self::assertSame(0, ApiTestHost::$consumed);
    }

    public function testATokenOverItsLimitIs429WithTheHeaders(): void
    {
        $this->issueToken();
        ApiTestHost::$tokenLimitAccepts = false;

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(429, 'RATE_001');
        self::assertSame('0', $this->client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testTheInstanceSwitchAnswers404(): void
    {
        $this->issueToken();
        ApiTestHost::$apiDisabled = true;

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(404, 'API_001');
        self::assertSame([], ApiTestHost::$failedIps);
    }

    public function testAUserTheAccessCheckerRefusesIsNotLetIn(): void
    {
        $this->user('guest@example.com', ['ROLE_GUEST']);
        $this->issueToken(email: 'guest@example.com');

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(401, 'OAUTH_019');
        self::assertSame(0, ApiTestHost::$consumed);
    }

    public function testAPrincipalTheFirewallDoesNotStandBehindFailsClosed(): void
    {
        $other = $this->user('other@example.com');
        $this->issueToken();
        ApiTestHost::$forcedUser = $other;

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(500, 'SERVER_001');
        self::assertCount(1, array_filter($this->logger()->records, static fn (array $record): bool => $record['level'] === 'error' && str_contains((string) $record['message'], 'security user')));
    }

    public function testAPlanWithoutTheModuleIsRefusedOnIdentity(): void
    {
        $this->issueToken();
        ApiTestHost::$planAllows = false;

        foreach (['user', 'team'] as $path) {
            $this->get('/api/tasks/v2/' . $path);
            $this->assertRefused(403, 'PLAN_001');
        }
    }

    public function testAPersonTheHostDoesNotProfileIsNotFound(): void
    {
        $this->issueToken();

        ApiTestHost::$profilesEmpty = true;

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testUnknownPathsAndMethodsUnderThePrefixAnswerInTheSameShape(): void
    {
        $this->issueToken();

        $this->get('/api/tasks/v2/nothing');
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks');
        $this->assertRefused(404, 'ACCESS_001');
        $this->client->request('POST', '/api/tasks/v2/user', [], [], ['HTTP_AUTHORIZATION' => self::TOKEN]);
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testAPathOutsideThePrefixIsNotTheApis(): void
    {
        $this->get('/api/tasksandmore');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @return iterable<string, array{0: Throwable, 1: int, 2: string}>
     */
    public static function failures(): iterable
    {
        yield 'a refusal' => [new TaskApiRefusal(409, 'LIST_001', 'Last column'), 409, 'LIST_001'];
        yield 'access denied' => [new AccessDeniedException(), 403, 'ACCESS_002'];
        yield 'bad credentials' => [new BadCredentialsException(), 401, 'OAUTH_019'];
        yield 'any other HTTP error' => [new ServiceUnavailableHttpException(), 500, 'SERVER_001'];
        yield 'a bad request' => [new BadRequestException('secret detail'), 500, 'SERVER_001'];
        yield 'a crash' => [new RuntimeException('secret detail'), 500, 'SERVER_001'];
    }

    #[DataProvider('failures')]
    public function testAFailureInAControllerLeavesAsARefusalWithoutItsMessage(Throwable $failure, int $status, string $ecode): void
    {
        $this->issueToken();
        $this->withListener(KernelEvents::CONTROLLER, static function () use ($failure): void {
            throw $failure;
        });

        $this->get('/api/tasks/v2/user');

        $this->assertRefused($status, $ecode);
        self::assertStringNotContainsString('secret detail', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCredentialReachesNoLogNoAnswerAndNoSession(): void
    {
        $this->issueToken();
        $this->get('/api/tasks/v2/user');
        $good = (string) $this->client->getResponse()->getContent();
        $this->get('/api/tasks/v2/user', 'bt_unknown_secret_value');
        $bad = (string) $this->client->getResponse()->getContent();

        $logged = (string) json_encode($this->logger()->records);
        foreach ([self::TOKEN, 'bt_unknown_secret_value'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
            self::assertStringNotContainsString($secret, $good);
            self::assertStringNotContainsString($secret, $bad);
        }
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'stateless');
    }

    public function testASubRequestDoesNotEnterThePipeline(): void
    {
        $this->issueToken();
        $kernel = self::$kernel;
        self::assertInstanceOf(ApiKernel::class, $kernel);
        $request = Request::create('/api/tasks/v2/user');
        $request->headers->set('Authorization', self::TOKEN);

        $this->withListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event) use ($kernel): void {
            if ($event->isMainRequest()) {
                // An API route without the pipeline has no principal, and the error is the host's page.
                $response = $kernel->handle(Request::create('/api/tasks/v2/user'), $kernel::SUB_REQUEST);
                self::assertSame(500, $response->getStatusCode());
                self::assertFalse($response->headers->has('X-RateLimit-Limit'));
            }
        });

        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, ApiTestHost::$consumed);
    }

    private function withListener(string $event, callable $listener): void
    {
        $dispatcher = $this->service('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener($event, $listener);
    }

    private function service(string $id): mixed
    {
        return self::getContainer()->get($id);
    }
}
