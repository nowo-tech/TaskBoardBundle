<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

use function count;
use function debug_backtrace;
use function is_a;
use function is_string;
use function str_starts_with;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * Since 2.28.0 (REQ-ARCH-001 rule 6 deprecation layer): the migration list of a host that runs without the private
 * entity manager (`database.private_entity_manager` unset or false, the 2.x default). Registered only in that mode:
 * the private manager never flushes the application's manager, so it needs no probe.
 *
 * Every TaskBoard write in that mode flushes `database.entity_manager`, which is the application's manager, and so
 * writes whatever the application left pending in it. In 3.0 TaskBoard writes only through its private manager and
 * those changes are no longer written by it. Right before each TaskBoard flush (Doctrine `preFlush`, before Doctrine
 * computes a change set) the shared nowo-tech/transaction-doctrine {@see HostFlushProbe} lists the application's entity
 * classes with pending work and triggers one `E_USER_DEPRECATED` per class and unit of work: "Your pending changes to
 * App\X were flushed by NowoTaskBoardBundle; 2.0 no longer does." (3.0 for TaskBoard). Flush them yourself.
 *
 * Which flush is TaskBoard's: the code that called `flush()` (past the entity manager and its decorators, Doctrine and
 * nowo-tech/transaction-doctrine's runners) is a class of `Nowo\TaskBoardBundle\`. One listener covers every TaskBoard
 * flush (services, repositories, sync, import, commands) without a call at each of them. A flush the application runs
 * itself is never reported, nor one an application listener runs inside a TaskBoard event.
 *
 * Read-only: it never flushes, clears, closes, resets or detaches anything (REQ-RUNTIME-003), and the shared probe
 * never throws (transaction-doctrine 1.9.1: a failed inspection or a deprecation handler that throws is logged or
 * swallowed), so a notice never stops a write. Its state (the classes already reported) is emptied at the start of
 * every main request, at every request, message and command boundary of the bundle and at `kernel.reset`, so a
 * FrankenPHP worker with kernel reset off reports each unit of work afresh. The shared probe is reset by
 * transaction-doctrine's own boundary listener when its bundle is registered; without it this service keeps a probe of
 * its own and resets it with itself.
 *
 * @internal
 */
final class TaskBoardHostFlushProbe implements ResetInterface
{
    /** The bundle name the deprecations show. */
    public const BUNDLE = 'NowoTaskBoardBundle';

    /** The namespaces of the TaskBoard entities: never reported (see {@see TaskBoardManagerRegistry::ENTITY_PATTERN}). */
    public const ENTITY_NAMESPACES = [
        'Nowo\\TaskBoardBundle\\Entity',
        'Nowo\\TaskBoardBundle\\Api\\Entity',
        'Nowo\\TaskBoardBundle\\Number\\Entity',
        'Nowo\\TaskBoardBundle\\Question\\Entity',
        'Nowo\\TaskBoardBundle\\Recurrence\\Entity',
        'Nowo\\TaskBoardBundle\\Sync\\Entity',
        'Nowo\\TaskBoardBundle\\Template\\Entity',
    ];

    /** The frames read to find the caller of `flush()` (the listener, the event manager, Doctrine, decorators, runners). */
    private const FRAMES = 48;

    /** Namespaces of the code between the caller and the flush: Doctrine and the shared transaction runners. */
    private const PASS_THROUGH = ['Doctrine\\', 'Symfony\\Bridge\\Doctrine\\', 'Nowo\\TransactionDoctrine\\'];

    private readonly HostFlushProbe $probe;

    /** Whether {@see self::$probe} is this service's own (no transaction-doctrine bundle): then reset with it. */
    private readonly bool $ownProbe;

    /**
     * @param HostFlushProbe|null $probe the shared probe (`nowo_transaction_doctrine.host_flush_probe`); null = a probe
     *                                   of this service's own, reset with it
     */
    public function __construct(?HostFlushProbe $probe = null)
    {
        $this->ownProbe = !$probe instanceof HostFlushProbe;
        $this->probe    = $probe ?? new HostFlushProbe();
    }

    /**
     * Doctrine `preFlush` of the application's managers: a TaskBoard flush reports the application's pending changes it
     * is about to write.
     */
    public function preFlush(PreFlushEventArgs $args): void
    {
        if ($this->calledByTaskBoard(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::FRAMES))) {
            $this->beforeFlush($args->getObjectManager());
        }
    }

    /**
     * Reports the application's pending changes the next flush of the manager writes on TaskBoard's behalf (read-only).
     *
     * @return list<class-string> the application's entity classes with pending work
     */
    public function beforeFlush(EntityManagerInterface $entityManager): array
    {
        if ($entityManager instanceof TaskBoardEntityManager) {
            return [];
        }

        return $this->probe->beforeHostFlush($entityManager, self::BUNDLE, self::ENTITY_NAMESPACES);
    }

    /**
     * Whether the code that called `flush()` is TaskBoard's.
     *
     * @param list<array{function: string, class?: class-string}> $frames a backtrace, innermost frame first
     */
    public function calledByTaskBoard(array $frames): bool
    {
        $count = count($frames);
        for ($index = 0; $index < $count; ++$index) {
            if ($frames[$index]['function'] === 'flush' && $this->isEntityManager($frames[$index]['class'] ?? null)) {
                break;
            }
        }
        // The caller of flush(): past the decorators of the manager, Doctrine (wrapInTransaction()) and the shared runners.
        for (++$index; $index < $count; ++$index) {
            $class = $frames[$index]['class'] ?? null;
            if (!is_string($class)) {
                return false;
            }
            if (!$this->isEntityManager($class) && !$this->passesThrough($class)) {
                // The bundle's own test suite plays the application (autoload-dev only, never installed by a host).
                return str_starts_with($class, 'Nowo\\TaskBoardBundle\\') && !str_starts_with($class, 'Nowo\\TaskBoardBundle\\Tests\\');
            }
        }

        return false;
    }

    /** A new unit of work (request, message, command) starts: nothing reported yet. */
    public function reset(): void
    {
        if ($this->ownProbe) {
            $this->probe->reset();
        }
    }

    /**
     * A main request starts: what an earlier request reported cannot suppress this request's notices, also when its
     * `kernel.terminate` never ran. Sub-requests keep the state of their main request.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    private function isEntityManager(?string $class): bool
    {
        return is_string($class) && is_a($class, EntityManagerInterface::class, true);
    }

    private function passesThrough(string $class): bool
    {
        foreach (self::PASS_THROUGH as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
