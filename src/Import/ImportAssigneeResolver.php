<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Support\UserLabel;

use function array_keys;
use function array_slice;
use function count;
use function in_array;
use function max;
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

    /** Since 1.23 (audit EG-L6): the most assignees one row resolves; the rest is dropped with one warning. */
    public const MAX_ASSIGNEES_PER_ROW = 50;

    /** Since 1.23: the unresolved e-mails warned one by one; the others are only counted. */
    private const LISTED_UNRESOLVED = 10;

    /** @var list<string> */
    private array $warnings = [];

    private int $unresolvedEmails = 0;

    private int $rowsOverCap = 0;

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
        $users  = [];
        $emails = $row->assigneeEmail !== null ? [$row->assigneeEmail => true] : [];
        foreach ($row->assigneeEmails as $email) {
            // Since 1.23: every e-mail of the row, each once (keyed: no quadratic search).
            $emails[$email] = true;
        }
        $names = $row->assigneeNames;
        if (count($emails) + count($names) > self::MAX_ASSIGNEES_PER_ROW) {
            // Audit EG-L6: a row resolves at most MAX_ASSIGNEES_PER_ROW assignees.
            // @igor-ignore - Built with `new` per import or sync page and excluded from the container: its counters end with it.
            ++$this->rowsOverCap;
            $emails = array_slice($emails, 0, self::MAX_ASSIGNEES_PER_ROW, true);
            $names  = array_slice($names, 0, max(0, self::MAX_ASSIGNEES_PER_ROW - count($emails)));
        }
        foreach (array_keys($emails) as $email) {
            // Audit DL-L3: an e-mail assigns only someone the board's assignee provider accepts, and "unknown" and
            // "not assignable here" read the same (no e-mail existence oracle).
            $user = $this->userByEmail((string) $email);
            if ($user !== null && !in_array($user, $users, true)) {
                $users[] = $user;
            // @igor-ignore - Built with `new` per import or sync page and excluded from the container: its counters end with it.
            } elseif ($user === null && ++$this->unresolvedEmails <= self::LISTED_UNRESOLVED) {
                // @igor-ignore - Built with `new` per import or sync page and excluded from the container: its counters end with it.
                $this->warnings[] = sprintf('Assignee "%s" for "%s" cannot be assigned on this board.', $email, $row->title);
            }
        }
        foreach ($names as $name) {
            $matches = $this->usersNamed($name);
            if (count($matches) === 1) {
                if (!in_array($matches[0], $users, true)) {
                    $users[] = $matches[0];
                }
            } else {
                // @igor-ignore - Built with `new` per import or sync page and excluded from the container: its counters end with it.
                $this->unresolvedNames[$name] = ($this->unresolvedNames[$name] ?? 0) + 1;
            }
        }

        return $users;
    }

    /**
     * Since 1.23: the user of an e-mail who may be assigned on the board (the board-aware resolver when the host
     * implements it), null otherwise — the same answer for "unknown" and "not assignable here".
     */
    public function userByEmail(string $email): ?object
    {
        $user = $this->userResolver instanceof BoardAwareTaskImportUserResolverInterface
            ? $this->userResolver->resolveByEmailForBoard($email, $this->board)
            : $this->userResolver->resolveByEmail($email);

        return $user !== null && (!$this->assigneeProvider instanceof TaskAssigneeProviderInterface || $this->assigneeProvider->canBeAssigned($this->board, $user)) ? $user : null;
    }

    /**
     * The warnings of the run: unresolved e-mails per row, unresolved names once each.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = $this->warnings;
        if ($this->unresolvedEmails > self::LISTED_UNRESOLVED) {
            $warnings[] = sprintf('%d more assignee e-mail(s) cannot be assigned on this board.', $this->unresolvedEmails - self::LISTED_UNRESOLVED);
        }
        if ($this->rowsOverCap > 0) {
            $warnings[] = sprintf('%d row(s) name more than %d assignees; only the first %d were read.', $this->rowsOverCap, self::MAX_ASSIGNEES_PER_ROW, self::MAX_ASSIGNEES_PER_ROW);
        }
        if (count($this->unresolvedNames) > self::LISTED_UNRESOLVED) {
            $warnings[] = sprintf('%d more assignee name(s) were not resolved; those tasks keep their assignees.', count($this->unresolvedNames) - self::LISTED_UNRESOLVED);
        }
        foreach (array_slice($this->unresolvedNames, 0, self::LISTED_UNRESOLVED, true) as $name => $rows) {
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
            // @igor-ignore - Built with `new` per import or sync page and excluded from the container: its counters end with it.
            $this->byName = [];
            foreach ($this->assigneeProvider->assignableUsers($this->board) as $user) {
                // @igor-ignore - Built with `new` per import or sync page and excluded from the container: its counters end with it.
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
