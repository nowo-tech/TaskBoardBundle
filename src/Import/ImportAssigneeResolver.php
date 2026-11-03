<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Support\UserLabel;

use function count;
use function in_array;
use function mb_strtolower;
use function preg_replace;
use function sprintf;
use function trim;

/**
 * Since 1.22: the users a row assigns, for one import run — by e-mail through {@see TaskImportUserResolverInterface}
 * (and, with an assignee provider, only if {@see TaskAssigneeProviderInterface::canBeAssigned()}),
 * by name among the board's assignable users ({@see TaskAssigneeProviderInterface}) when exactly one has that display
 * name ({@see UserLabel::of()}, case-insensitive). A name that matches nobody or several people, or any name without
 * an assignee provider, is a warning (once per name) and assigns no one.
 */
final class ImportAssigneeResolver
{
    /** @var array<string, list<object>>|null display name (normalized) → users, built on the first name */
    private ?array $byName = null;

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> unresolved name → rows */
    private array $unresolvedNames = [];

    public function __construct(
        private readonly TaskImportUserResolverInterface $userResolver,
        private readonly ?TaskAssigneeProviderInterface $assigneeProvider,
        private readonly TaskBoard $board,
    ) {
    }

    /**
     * @return list<object>
     */
    public function resolve(ImportedTaskDto $row): array
    {
        $users = [];
        if ($row->assigneeEmail !== null) {
            // Audit DL-L3: an e-mail assigns only someone the board's assignee provider accepts, and "unknown" and
            // "not assignable here" read the same (no e-mail existence oracle).
            $user = $this->userResolver->resolveByEmail($row->assigneeEmail);
            if ($user !== null && (!$this->assigneeProvider instanceof TaskAssigneeProviderInterface || $this->assigneeProvider->canBeAssigned($this->board, $user))) {
                $users[] = $user;
            } else {
                $this->warnings[] = sprintf('Assignee "%s" for "%s" cannot be assigned on this board.', $row->assigneeEmail, $row->title);
            }
        }
        foreach ($row->assigneeNames as $name) {
            $matches = $this->usersNamed($name);
            if (count($matches) === 1) {
                if (!in_array($matches[0], $users, true)) {
                    $users[] = $matches[0];
                }
            } else {
                $this->unresolvedNames[$name] = ($this->unresolvedNames[$name] ?? 0) + 1;
            }
        }

        return $users;
    }

    /**
     * The warnings of the run: unresolved e-mails per row, unresolved names once each.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = $this->warnings;
        foreach ($this->unresolvedNames as $name => $rows) {
            $warnings[] = sprintf(
                $this->assigneeProvider instanceof TaskAssigneeProviderInterface
                    ? 'Assignee "%s" (%d row(s)) does not match exactly one assignable user by display name; those tasks keep their assignees.'
                    : 'Assignee "%s" (%d row(s)) is given by name and no assignee provider is configured to resolve names; those tasks keep their assignees.',
                $name,
                $rows,
            );
        }

        return $warnings;
    }

    /**
     * @return list<object>
     */
    private function usersNamed(string $name): array
    {
        if (!$this->assigneeProvider instanceof TaskAssigneeProviderInterface) {
            return [];
        }
        if ($this->byName === null) {
            $this->byName = [];
            foreach ($this->assigneeProvider->assignableUsers($this->board) as $user) {
                $this->byName[$this->normalize(UserLabel::of($user))][] = $user;
            }
        }

        return $this->byName[$this->normalize($name)] ?? [];
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
