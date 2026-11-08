<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Command;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Since 2.2.0 (spec 213): deletes the kept answers of expired idempotency keys (they hold task text). Claims also purge,
 * so this is for an API that receives no writes for a while.
 */
#[AsCommand(name: 'nowo:task-board:api:purge-idempotency', description: 'Delete the expired idempotency answers of the Task API')]
final class TaskApiPurgeIdempotencyCommand extends Command
{
    public function __construct(
        private readonly TaskApiIdempotencyStoreInterface $store,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->store->purgeExpired($this->clock->now()) . ' expired answers deleted.');

        return Command::SUCCESS;
    }
}
