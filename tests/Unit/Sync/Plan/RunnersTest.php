<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync\Plan;

use Nowo\TaskBoardBundle\Sync\Plan\MessengerPlanRunner;
use Nowo\TaskBoardBundle\Sync\Plan\PlanRunRequest;
use Nowo\TaskBoardBundle\Sync\Plan\RunImportPlanSlice;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Since 3.2.0 (CU-2, Q-CU-9): the optional Messenger runner queues ids only, with a delay when the plan is due later.
 */
final class RunnersTest extends TestCase
{
    public function testMessengerRunnerQueuesIdsWithADelay(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = new Envelope($message, $stamps);
            }
        };
        $runner = new MessengerPlanRunner($bus);
        $runner->dispatch(new PlanRunRequest('p1', 'u1'));
        $runner->dispatch(new PlanRunRequest('p1', null, 30));

        self::assertFalse($runner->advancesOnPoll());
        $message = $bus->sent[0]->getMessage();
        self::assertInstanceOf(RunImportPlanSlice::class, $message);
        self::assertSame(['p1', 'u1'], [$message->planId, $message->actorId]);
        self::assertNull($bus->sent[0]->last(DelayStamp::class));
        self::assertSame(30_000, $bus->sent[1]->last(DelayStamp::class)?->getDelay());
    }
}
