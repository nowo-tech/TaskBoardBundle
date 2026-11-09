<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Command;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Service\DefaultColumnIconFiller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_string;
use function sprintf;

/**
 * Since 2.9.0: gives the columns of existing boards that have no icon the icon of their `board.default_columns` entry, matched
 * by exact name ({@see DefaultColumnIconFiller}). Fill only and idempotent: an icon a column has is never replaced. Every active
 * board by default; `--tenant` keeps one tenant's boards, `--board` one board (of that tenant, when both are given).
 */
#[AsCommand(
    name: 'nowo:task-board:apply-default-column-icons',
    description: 'Give the columns without an icon the icon of their board.default_columns entry (matched by exact name). Fill only; safe to run again.',
)]
final class TaskBoardApplyDefaultColumnIconsCommand extends Command
{
    public function __construct(
        private readonly TaskBoardRepositoryInterface $boards,
        private readonly DefaultColumnIconFiller $filler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('board', null, InputOption::VALUE_REQUIRED, 'Only this board (its id)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Only the boards of this tenant key')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run') === true;
        if (!$this->filler->hasIcons()) {
            $io->note('No board.default_columns entry has an icon: nothing to apply.');

            return Command::SUCCESS;
        }

        $boards = $this->boards($input->getOption('board'), $input->getOption('tenant'));
        if ($boards === null) {
            $io->error('Board not found.');

            return Command::FAILURE;
        }

        $rows    = [];
        $touched = 0;
        foreach ($boards as $board) {
            $filled = $this->filler->fill($board, $dryRun);
            if ($filled !== []) {
                ++$touched;
            }
            foreach ($filled as $change) {
                $rows[] = [$board->getId(), $board->getTenantKey() ?? '-', $board->getName(), $change['column']->getName(), $change['icon']];
            }
        }

        if ($rows !== []) {
            $io->table(['Board', 'Tenant', 'Board name', 'Column', 'Icon'], $rows);
        }
        $io->success(sprintf(
            $dryRun ? 'Dry run: %d column(s) on %d of %d board(s) would get an icon. Nothing was changed.' : '%d column(s) on %d of %d board(s) got an icon.',
            count($rows),
            $touched,
            count($boards),
        ));

        return Command::SUCCESS;
    }

    /**
     * The boards to fill; null when `--board` names no board (or one of another tenant than `--tenant`).
     *
     * @return list<TaskBoard>|null
     */
    private function boards(mixed $boardId, mixed $tenant): ?array
    {
        $tenant = is_string($tenant) && $tenant !== '' ? $tenant : null;
        if (is_string($boardId) && $boardId !== '') {
            $board = $this->boards->findById($boardId);

            return $board instanceof TaskBoard && ($tenant === null || $board->getTenantKey() === $tenant) ? [$board] : null;
        }

        return $tenant !== null ? $this->boards->findActiveByTenant($tenant) : $this->boards->findAllActive();
    }
}
