<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Audit IC-I3: events pass straight on outside a deferred call, wait for its end inside, and are dropped when it fails.
 */
final class TaskEventDeferralTest extends TestCase
{
    public function testEventsWaitForTheOutermostCallAndAreDroppedOnFailure(): void
    {
        $inner = new EventDispatcher();
        $seen  = [];
        $inner->addListener('e', static function (stdClass $event) use (&$seen): void {
            $seen[] = $event->name;
        });
        $deferral = new TaskEventDeferral($inner);
        $event    = new stdClass();

        $event->name = 'direct';
        $deferral->dispatch($event, 'e');
        self::assertSame(['direct'], $seen);

        $result = $deferral->defer(static function () use ($deferral, &$seen): int {
            $deferral->dispatch((object) ['name' => 'a'], 'e');
            $deferral->defer(static fn () => $deferral->dispatch((object) ['name' => 'b'], 'e'));
            self::assertSame(['direct'], $seen, 'held until the outermost call returns');

            return 7;
        });
        self::assertSame(7, $result);
        self::assertSame(['direct', 'a', 'b'], $seen);

        try {
            $deferral->defer(static function () use ($deferral): void {
                $deferral->dispatch((object) ['name' => 'lost'], 'e');
                $deferral->defer(static fn () => throw new RuntimeException('inner'));
            });
            self::fail('The failure reaches the caller.');
        } catch (RuntimeException $exception) {
            self::assertSame('inner', $exception->getMessage());
        }
        self::assertSame(['direct', 'a', 'b'], $seen, 'dropped');

        try {
            $deferral->defer(static function () use ($deferral): void {
                $deferral->dispatch((object) ['name' => 'lost too'], 'e');
                throw new RuntimeException('outer');
            });
        } catch (RuntimeException) {
        }
        $deferral->dispatch((object) ['name' => 'after'], 'e');
        self::assertSame(['direct', 'a', 'b', 'after'], $seen);

        $deferral->reset();
        $deferral->dispatch((object) ['name' => 'reset'], 'e');
        self::assertSame('reset', $seen[4]);
    }

    public function testADeferredWriteThatCommittedLogsAListenerFailureInsteadOfThrowing(): void
    {
        $inner = new EventDispatcher();
        $seen  = [];
        $inner->addListener('e', static function (stdClass $event) use (&$seen): void {
            $seen[] = $event->name;
            if ($event->name === 'bad') {
                throw new RuntimeException('listener bad');
            }
        });
        $logged = [];
        $logger = new class($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = $level . ': ' . $context['event'];
            }
        };
        $deferral = new TaskEventDeferral($inner, $logger);

        // Audit JY-I6: a copy or an occurrence (defer(..., false)) returns its result; every held event is sent.
        $result = $deferral->defer(static function () use ($deferral): int {
            $deferral->dispatch((object) ['name' => 'bad'], 'e');
            $deferral->defer(static fn (): object => $deferral->dispatch((object) ['name' => 'ok'], 'e'));

            return 3;
        }, false);
        self::assertSame(3, $result);
        self::assertSame(['bad', 'ok'], $seen);
        self::assertSame(['error: e'], $logged);

        // The default still rethrows.
        try {
            $deferral->defer(static fn (): object => $deferral->dispatch((object) ['name' => 'bad'], 'e'));
            self::fail('Rethrown');
        } catch (RuntimeException $failure) {
            self::assertSame('listener bad', $failure->getMessage());
        }
        self::assertSame(['error: e'], $logged);
    }
}
