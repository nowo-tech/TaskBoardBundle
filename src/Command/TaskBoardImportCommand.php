<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Command;

use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;
use function is_readable;
use function sprintf;

#[AsCommand(
    name: 'nowo:task-board:import',
    description: 'Import tasks into a board from ClickUp, Jira, Trello, or other supported exports.',
)]
final class TaskBoardImportCommand extends Command
{
    public function __construct(
        private readonly TaskBoardRepositoryInterface $boardRepository,
        private readonly TaskImportOrchestrator $importOrchestrator,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('board-id', InputArgument::REQUIRED, 'Target board UUID')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to CSV or JSON export')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Import source key', TaskImportSource::ClickUpCsv->value)
            ->addOption('no-create-columns', null, InputOption::VALUE_NONE, 'Do not create missing status columns')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Import tasks even if external id already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $board = $this->boardRepository->findById((string) $input->getArgument('board-id'));
        if (!$board instanceof \Nowo\TaskBoardBundle\Entity\TaskBoard) {
            $io->error('Board not found.');

            return Command::FAILURE;
        }

        $filePath = (string) $input->getArgument('file');
        if (!is_readable($filePath)) {
            $io->error(sprintf('File "%s" is not readable.', $filePath));

            return Command::FAILURE;
        }

        $source = TaskImportSource::tryFrom((string) $input->getOption('source'));
        if (!$source instanceof TaskImportSource) {
            $io->error('Invalid --source value.');

            return Command::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            $io->error('No authenticated user found. Run the command as a logged-in user or provide a security token.');

            return Command::FAILURE;
        }

        $result = $this->importOrchestrator->import(
            board: $board,
            source: $source,
            content: (string) file_get_contents($filePath),
            filename: basename($filePath),
            actor: $actor,
            options: new TaskImportOptions(
                createMissingColumns: !$input->getOption('no-create-columns'),
                skipExisting: !$input->getOption('force'),
            ),
        );

        foreach ($result->errors as $error) {
            $io->error($error);
        }

        foreach ($result->warnings as $warning) {
            $io->warning($warning);
        }

        if ($result->hasErrors()) {
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported %d task(s), skipped %d, created %d column(s).',
            $result->created,
            $result->skipped,
            $result->columnsCreated,
        ));

        return Command::SUCCESS;
    }

    private function resolveActor(): ?object
    {
        $token = $this->tokenStorage->getToken();
        $user  = $token?->getUser();

        return is_object($user) ? $user : null;
    }
}
