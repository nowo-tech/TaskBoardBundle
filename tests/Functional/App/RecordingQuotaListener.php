<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;

use function in_array;

/** Since 2.0.0: a host's plan limits: refuses the actions a test names and records what it was asked. */
final class RecordingQuotaListener
{
    /** @var list<string> */
    public array $deny = [];

    /** @var list<TaskBoardQuotaCheckEvent> */
    public array $asked = [];

    public function __invoke(TaskBoardQuotaCheckEvent $event): void
    {
        $this->asked[] = $event;
        if (in_array($event->action, $this->deny, true)) {
            $event->deny('Your plan is full.');
        }
    }
}
