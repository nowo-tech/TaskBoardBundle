<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_filter;
use function implode;

/**
 * Since 2.25.0 (header search): a task or board as a {@see SearchHit}. Plain text only (the search box escapes it): the
 * title, the board and tenant names, and for tasks an excerpt of the description; the URL is the task or board page
 * (under its tenant with tenancy). Stateless.
 */
final readonly class SearchHitMapper
{
    /**
     * @param array{task?: array{name?: string}, board?: array{name?: string}} $routes `routes` (the task and board route names)
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private array $routes = [],
    ) {
    }

    /**
     * @param list<string> $words The query words (the excerpt is cut around the first one found)
     */
    public function task(Task $task, ?TaskBoardTenant $tenant, array $words): SearchHit
    {
        $board  = $task->getBoard();
        $number = $task->getNumber();

        return new SearchHit(
            SearchHit::KIND_TASK,
            $task->getId(),
            SearchText::line($task->getTitle()),
            $this->urls->generate($this->routes['task']['name'] ?? 'nowo_task_board_task', ['taskId' => $task->getId()] + $this->tenantParameter($tenant)),
            $this->join([$number !== null ? '#' . $number : null, SearchText::line($board->getName()), $tenant !== null ? SearchText::line($tenant->label) : null]),
            SearchText::excerpt($task->getDescription(), $words),
            $task->getUpdatedAt(),
            $board->getId(),
        );
    }

    public function board(TaskBoard $board, ?TaskBoardTenant $tenant): SearchHit
    {
        return new SearchHit(
            SearchHit::KIND_BOARD,
            $board->getId(),
            SearchText::line($board->getName()),
            $this->urls->generate($this->routes['board']['name'] ?? 'nowo_task_board_board', ['boardId' => $board->getId()] + $this->tenantParameter($tenant)),
            $tenant !== null ? SearchText::line($tenant->label) : '',
            SearchText::excerpt($board->getDescription(), []),
            $board->getUpdatedAt(),
        );
    }

    /**
     * @return array{tenantSlug?: string}
     */
    private function tenantParameter(?TaskBoardTenant $tenant): array
    {
        return $tenant !== null ? ['tenantSlug' => $tenant->slug] : [];
    }

    /**
     * @param list<string|null> $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
