<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;
use RuntimeException;

/**
 * The host's audit of API writes: it keeps every event, and can fail on demand.
 */
final class ApiAuditRecorder
{
    /** @var list<TaskApiWriteEvent> */
    public array $events = [];

    public bool $fail = false;

    public function __invoke(TaskApiWriteEvent $event): void
    {
        $this->events[] = $event;
        if ($this->fail) {
            throw new RuntimeException('audit store down');
        }
    }
}
