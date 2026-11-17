<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiCommitWatermark;
use Nowo\TaskBoardBundle\Api\Security\TaskApiConflictResponseFormatter;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Doctrine\LockConflict;
use Nowo\TaskBoardBundle\Doctrine\LockConflictChain;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

use function json_decode;

/**
 * Since 2.22.0 (owner decision, transaction-doctrine 1.3): a database lock conflict anywhere in a Task API failure's chain
 * answers the shared policy's status (409 by default, was 500 `SERVER_001`) with `Retry-After` and `CONFLICT_001`; after a
 * partial commit `CONFLICT_002` without `Retry-After`; the policy's 503 when the host keeps it.
 */
final class TaskApiLockConflictTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function conflicts(): iterable
    {
        yield 'deadlock' => [new DeadlockException(self::driver('40001'), null), '1'];
        yield 'lock wait timeout, wrapped by the host' => [new RuntimeException('host', 0, new LockWaitTimeoutException(self::driver('HY000'), null)), '1'];
        yield 'PostgreSQL lock_not_available' => [new \Doctrine\DBAL\Exception\DriverException(self::driver('55P03'), null), '1'];
        yield 'a transient storage failure with its hint' => [new TransientStorageFailure(4), '4'];
        yield 'a hint below one second' => [new TransientStorageFailure(0), '1'];
        yield 'a write unit that gave up' => [new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 2, new RuntimeException('x')), '2'];
        yield 'a host exception marked as a lock conflict' => [new class('busy') extends RuntimeException implements LockConflictExceptionInterface {}, '1'];
    }

    #[DataProvider('conflicts')]
    public function testALockConflictIs409WithRetryAfter(Throwable $failure, string $retryAfter): void
    {
        $logger   = new RecordingLogger();
        $response = $this->answer($this->listener(null, $logger), $failure);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
        self::assertSame(['err' => TaskApiRefusal::CONFLICT_MESSAGE, 'ECODE' => 'CONFLICT_001'], json_decode((string) $response->getContent(), true));
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
        self::assertTrue(LockConflictChain::isConflict($failure));
    }

    public function testAConflictAfterAPartialCommitHasNoRetryAfter(): void
    {
        $failure  = new RuntimeException('wrapped', 0, new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true));
        $response = $this->answer($this->listener(), $failure);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(['err' => TaskApiRefusal::CONFLICT_PARTIAL_MESSAGE, 'ECODE' => 'CONFLICT_002'], json_decode((string) $response->getContent(), true));
    }

    public function testTheHostPolicyKeeps503(): void
    {
        $response = $this->answer($this->listener(new ConflictResponsePolicy(503, 5)), new DeadlockException(self::driver('40001'), null));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
    }

    public function testAnyOtherFailureStaysServer001(): void
    {
        $failure  = new RuntimeException('boom', 0, new \Doctrine\DBAL\Exception\DriverException(self::driver('23000'), null));
        $response = $this->answer($this->listener(), $failure);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('SERVER_001', json_decode((string) $response->getContent(), true)['ECODE'] ?? null);
        self::assertFalse(LockConflictChain::isConflict($failure));
        self::assertFalse(LockConflictChain::committed($failure));
        self::assertNull(LockConflictChain::retryAfterHint($failure));
        self::assertNull(LockConflictChain::retryAfterHint(new TransientStorageFailure()));
    }

    public function testMariaDbSnapshotConflictsAreLockConflicts(): void
    {
        $failure = new \Doctrine\DBAL\Exception\DriverException(self::driver('HY000', 1020), null);

        self::assertTrue(LockConflict::is($failure));
        self::assertTrue(LockConflictChain::isConflict($failure));
        self::assertFalse(LockConflictChain::committed($failure));
        $response = $this->answer($this->listener(), $failure);
        self::assertSame('CONFLICT_001', json_decode((string) $response->getContent(), true)['ECODE'] ?? null);
    }

    public function testALostSavepointAloneMayHaveCommitted(): void
    {
        $lost = new \Doctrine\DBAL\Exception\DriverException(self::driver('42000', 1305, 'SAVEPOINT DOCTRINE_2 does not exist'), null);

        self::assertTrue(LockConflictChain::committed($lost));
        $response = $this->answer($this->listener(), $lost);
        self::assertSame('CONFLICT_002', json_decode((string) $response->getContent(), true)['ECODE'] ?? null);
        self::assertFalse($response->headers->has('Retry-After'));
        // Behind a deadlock the server rolled the transaction back: the same request may be sent again.
        $behindDeadlock = new \Doctrine\DBAL\Exception\DriverException(self::driver('42000', 1305, 'SAVEPOINT DOCTRINE_2 does not exist', new DeadlockException(self::driver('40001'), null)), null);
        self::assertFalse(LockConflictChain::committed($behindDeadlock));
        self::assertFalse(LockConflictChain::committed(new LockWaitTimeoutException(self::driver('HY000'), null)));
        self::assertFalse(LockConflict::isLostSavepoint(new \Doctrine\DBAL\Exception\DriverException(self::driver('42000', 1305, 'FUNCTION x does not exist'), null)));
    }

    public function testACommitOfThisRequestMakesItConflict002(): void
    {
        $ledger    = new CommitLedger();
        $watermark = new TaskApiCommitWatermark($ledger);
        $request   = new Request(attributes: [TaskApiRequestListener::ROUTE_FLAG => true]);
        $kernel    = $this->createStub(HttpKernelInterface::class);
        self::assertSame([KernelEvents::CONTROLLER => ['onController', -16]], TaskApiCommitWatermark::getSubscribedEvents());

        self::assertFalse($watermark->committedSince($request), 'no mark yet');
        $ledger->record();
        $watermark->onController(new ControllerEvent($kernel, static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($watermark->committedSince($request), 'the writes before the controller do not count');
        $ledger->record();
        self::assertTrue($watermark->committedSince($request));

        // Off the API and in a sub-request no mark is taken.
        $other = new Request();
        $watermark->onController(new ControllerEvent($kernel, static fn () => null, $other, HttpKernelInterface::MAIN_REQUEST));
        $sub = new Request(attributes: [TaskApiRequestListener::ROUTE_FLAG => true]);
        $watermark->onController(new ControllerEvent($kernel, static fn () => null, $sub, HttpKernelInterface::SUB_REQUEST));
        self::assertFalse($watermark->committedSince($other));
        self::assertFalse($watermark->committedSince($sub));

        $listener = new TaskApiRequestListener(
            $this->createStub(TaskApiAuthenticatorInterface::class),
            $this->createStub(TaskApiRateLimiterInterface::class),
            $this->createStub(TaskBoardAccessCheckerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            '/api',
            new RecordingLogger(),
            null,
            $watermark,
        );
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new DeadlockException(self::driver('40001'), null));
        $listener->onException($event);
        self::assertSame('CONFLICT_002', json_decode((string) $event->getResponse()?->getContent(), true)['ECODE'] ?? null);
        self::assertTrue($request->attributes->getBoolean(TaskApiRequestListener::PARTIAL_COMMIT));

        $formatted = (new TaskApiConflictResponseFormatter($watermark))->format($request, $this->context(409, false));
        self::assertSame('CONFLICT_002', json_decode((string) $formatted?->getContent(), true)['ECODE'] ?? null);
    }

    public function testTheFormatterAnswersTheApiRoutesInTheirShape(): void
    {
        $formatter = new TaskApiConflictResponseFormatter();
        $api       = new Request(attributes: [TaskApiRequestListener::ROUTE_FLAG => true]);

        $response = $formatter->format($api, $this->context(409, false));
        self::assertSame(409, $response?->getStatusCode());
        self::assertSame(['err' => TaskApiRefusal::CONFLICT_MESSAGE, 'ECODE' => 'CONFLICT_001'], json_decode((string) $response->getContent(), true));

        $partial = $formatter->format($api, $this->context(503, true));
        self::assertSame(503, $partial?->getStatusCode());
        self::assertSame(['err' => TaskApiRefusal::CONFLICT_PARTIAL_MESSAGE, 'ECODE' => 'CONFLICT_002'], json_decode((string) $partial->getContent(), true));

        // The UI and every other route fall through to the responder's own answer.
        self::assertNull($formatter->format(new Request(attributes: ['_route' => 'nowo_task_board_task_move']), $this->context(409, false)));
    }

    private function context(int $status, bool $committed): ConflictResponseContext
    {
        return new ConflictResponseContext($status, $committed ? null : 1, 'concurrency_conflict', 'Retry later.', 3, new DeadlockException(self::driver('40001'), null), $committed);
    }

    private function listener(?ConflictResponsePolicy $policy = null, ?RecordingLogger $logger = null): TaskApiRequestListener
    {
        return new TaskApiRequestListener(
            $this->createStub(TaskApiAuthenticatorInterface::class),
            $this->createStub(TaskApiRateLimiterInterface::class),
            $this->createStub(TaskBoardAccessCheckerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            '/api',
            $logger ?? new RecordingLogger(),
            $policy,
        );
    }

    private function answer(TaskApiRequestListener $listener, Throwable $failure): Response
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: [TaskApiRequestListener::ROUTE_FLAG => true]), HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);

        return $response;
    }

    private static function driver(string $sqlState, int $code = 0, string $message = 'driver', ?Throwable $previous = null): DriverException
    {
        return new class($message, $sqlState, $code, $previous) extends RuntimeException implements DriverException {
            public function __construct(string $message, private readonly string $sqlState, int $code, ?Throwable $previous)
            {
                parent::__construct($message, $code, $previous);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}
