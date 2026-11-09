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
use function trim;

/**
 * Since 2.9.0: gives the columns of existing boards that have no icon the icon of their `board.default_columns` entry, matched
 * by exact name ({@see DefaultColumnIconFiller}). Fill only and idempotent: an icon a column has is never replaced. Every active
 * board by default; `--tenant` keeps one tenant's boards (an empty one is refused), `--board` one board (of that tenant, when
 * both are given; an archived one only with `--include-archived`).
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
            ->addOption('include-archived', null, InputOption::VALUE_NONE, 'With --board: fill that board even when it is archived (skipped otherwise)')
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

        // Since 2.9.0 (audit JM-I1): an empty --tenant (e.g. an unset shell variable) is refused, not read as "every tenant".
        $tenant = $input->getOption('tenant');
        if ($tenant !== null && (!is_string($tenant) || trim($tenant) === '')) {
            $io->error('--tenant is empty: give a tenant key, or leave the option out to fill every board.');

            return Command::INVALID;
        }

        $boards = $this->boards($input->getOption('board'), is_string($tenant) ? $tenant : null);
        if ($boards === null) {
            $io->error('Board not found.');

            return Command::FAILURE;
        }
        // Since 2.9.0 (audit JM-I2): an archived board is skipped, as the tenant and all-boards runs skip it, unless asked for.
        if (count($boards) === 1 && $boards[0]->isArchived() && $input->getOption('include-archived') !== true) {
            $io->warning('The board is archived: nothing was changed. Add --include-archived to fill it anyway.');

            return Command::SUCCESS;
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
    private function boards(mixed $boardId, ?string $tenant): ?array
    {
        if (is_string($boardId) && $boardId !== '') {
            $board = $this->boards->findById($boardId);

            return $board instanceof TaskBoard && ($tenant === null || $board->getTenantKey() === $tenant) ? [$board] : null;
        }

        return $tenant !== null ? $this->boards->findActiveByTenant($tenant) : $this->boards->findAllActive();
    }
}
