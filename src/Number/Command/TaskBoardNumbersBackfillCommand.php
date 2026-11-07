<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number\Command;

use Nowo\TaskBoardBundle\Number\TaskNumberBackfiller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function max;
use function sprintf;

/**
 * Since 2.1.0 (spec 214, FR-007): gives every task without a number one. Safe to run again and from a schedule: it does nothing
 * once none is left. Exists only with `task_numbers.enabled`.
 */
#[AsCommand(
    name: 'nowo:task-board:numbers-backfill',
    description: 'Number the tasks that have no number yet, per scope in creation order. Resumable; a no-op once none is left.',
)]
final class TaskBoardNumbersBackfillCommand extends Command
{
    private const DEFAULT_BATCH = 500;

    public function __construct(
        private readonly TaskNumberBackfiller $backfiller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Tasks per transaction', (string) self::DEFAULT_BATCH)
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches (0: until none is left)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $batch      = max(1, (int) $input->getOption('batch'));
        $maxBatches = max(0, (int) $input->getOption('max-batches'));
        $total      = 0;
        $batches    = 0;

        do {
            $done = $this->backfiller->runBatch($batch);
            $total += $done;
            ++$batches;
        } while ($done > 0 && ($maxBatches === 0 || $batches < $maxBatches));

        $io->success(sprintf('%d tasks numbered, %d left.', $total, $this->backfiller->remaining()));

        return Command::SUCCESS;
    }
}
