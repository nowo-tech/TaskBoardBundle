<?php

declare(strict_types=1);

/*
 * Since 2.29.0 (REQ-RUNTIME-003): one of two processes adjusting a task's tracked total through the host port, on the
 * functional kernel (TASK_BOARD_TEST_DATABASE_URL). argv: task id, adjustments, seconds each. A transient lock
 * conflict is retried, as a host does.
 */

use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TransactionContract\TransientStorageFailure;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $taskId, $times, $seconds] = $argv;
$kernel                              = new FunctionalKernel();
$kernel->boot();
$commands = $kernel->getContainer()->get(TaskCommandsInterface::class);
assert($commands instanceof TaskCommandsInterface);
for ($index = 0; $index < (int) $times; ++$index) {
    for ($attempt = 0;; ++$attempt) {
        try {
            $commands->adjustTotalTime($taskId, (int) $seconds);

            break;
        } catch (TransientStorageFailure $failure) {
            if ($attempt > 20) {
                throw $failure;
            }
        }
    }
}
$kernel->shutdown();
echo "done\n";
