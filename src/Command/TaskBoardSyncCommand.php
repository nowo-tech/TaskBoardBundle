<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Command;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.23: syncs a linked board with its provider, to completion (resuming a stored cursor unless --restart).
 * Exit codes: 0 succeeded, 1 failed, 2 invalid (not found, not linked, no actor), 3 paused or rate-limited
 * (resumable), 4 refused by a limit, 5 busy. Never prints a token or a URL.
 */
#[AsCommand(
    name: 'nowo:task-board:sync',
    description: 'Sync a board linked to a task provider (ClickUp…): create, update, archive and restore its tasks.',
)]
final class TaskBoardSyncCommand extends Command
{
    public const EXIT_INVALID = 2;
    public const EXIT_PAUSED  = 3;
    public const EXIT_REFUSED = 4;
    public const EXIT_BUSY    = 5;

    public function __construct(
        private readonly TaskBoardRepositoryInterface $boards,
        private readonly TaskBoardSyncService $sync,
        private readonly SyncStore $store,
        private readonly SyncActorResolverInterface $actors,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('boardId', InputArgument::REQUIRED, 'Board UUID')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Read every remote task, not only those updated since the last successful sync')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute everything, write nothing (no files are downloaded)')
            ->addOption('allow-mass-archive', null, InputOption::VALUE_NONE, 'Archive even when the provider no longer lists most linked tasks')
            ->addOption('time-entries', null, InputOption::VALUE_NONE, 'Also read tracked time and hand it to the host\'s sink (sync.time_entries.sink)')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Ignore a stored cursor and start a new run')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $board = $this->boards->findById((string) $input->getArgument('boardId'));
        if (!$board instanceof TaskBoard) {
            $io->error('Board not found.');

            return self::EXIT_INVALID;
        }
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection) {
            $io->error('Board is not linked to a provider.');

            return self::EXIT_INVALID;
        }
        $actor = $this->actors->actorFor($board);
        if ($actor === null) {
            $io->error('No user to run the sync as (the user who linked the board is gone, disabled or erased).');

            return self::EXIT_INVALID;
        }
        if ($input->getOption('restart') === true) {
            $connection->setCursor(null);
            $this->store->entityManager()->flush();
        }

        $options = new SyncOptions(
            actor: $actor,
            trigger: SyncTrigger::Console,
            full: $input->getOption('full') === true,
            dryRun: $input->getOption('dry-run') === true,
            allowMassArchive: $input->getOption('allow-mass-archive') === true,
            timeEntries: $input->getOption('time-entries') === true,
        );
        do {
            $report = $this->sync->runLinked($board, $options);
            $board  = $this->boards->findById($report->boardId) ?? $board;
        } while ($report->status === SyncStatus::Paused);

        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->print($io, $report, substr($connection->getConnectionRef(), -4));
        }

        return match ($report->status) {
            SyncStatus::Succeeded, SyncStatus::Cancelled => Command::SUCCESS,
            SyncStatus::RateLimited                      => self::EXIT_PAUSED,
            SyncStatus::Refused                          => self::EXIT_REFUSED,
            SyncStatus::Busy                             => self::EXIT_BUSY,
            default                                      => Command::FAILURE,
        };
    }

    private function print(SymfonyStyle $io, SyncReport $report, string $refTail): void
    {
        $io->title(sprintf('Sync %s (%s%s) — connection …%s', $report->status->value, $report->mode->value, $report->dryRun ? ', dry run' : '', $refTail));
        $io->table(['Created', 'Updated', 'Unchanged', 'Archived', 'Restored', 'Skipped', 'Deleted here', 'Conflicts', 'Files copied', 'Files reused', 'Files refused'], [[
            $report->created, $report->updated, $report->unchanged, $report->archived, $report->restored, $report->skipped(),
            $report->skips->locallyDeleted, count($report->conflicts), $report->files->copied, $report->files->reused, $report->files->refused,
        ]]);
        foreach ($report->conflicts as $conflict) {
            $io->writeln(sprintf(' ! conflict on "%s" (%s): %s', $conflict->taskTitle, $conflict->externalId, $conflict->field));
        }
        foreach ($report->warnings as $warning) {
            $io->warning($this->message($warning->messageKey(), $warning->params, $warning->code));
        }
        foreach ($report->errors as $error) {
            $io->error($this->message($error->messageKey(), $error->params, $error->code));
        }
        if ($report->refusalCode !== null) {
            $io->error($this->message('task_board.sync.refusal.' . $report->refusalCode, [], $report->refusalCode));
        }
        if ($report->retryAfterSeconds !== null) {
            $io->note(sprintf('The provider asked to wait %d s; run the command again to resume.', $report->retryAfterSeconds));
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function message(string $key, array $params, string $code): string
    {
        $text = $this->translator?->trans($key, $params, 'NowoTaskBoardBundle', 'en');

        return $text === null || $text === $key ? $code : $text;
    }
}
