<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function mb_strtoupper;
use function mb_substr;
use function preg_split;
use function sort;
use function str_contains;
use function strstr;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Since 2.26.0 (header search): the assignees of the shown tasks, in **one** query for all of them (no query per row),
 * as initials in alphabetical order: the task page shows a task's assignees to whoever may view the task, so a search row the viewer may see
 * may show them too; only initials leave this class (a label may be an e-mail address). The column and the done flag
 * come from the task itself (the search query fetch-joins the column). Stateless (FrankenPHP worker mode).
 */
final readonly class TaskSearchExtras
{
    /** The most assignees a row names; the rest is counted ("+2"). */
    public const MAX_ASSIGNEES = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?TaskBoardUsers $users = null,
    ) {
    }

    /**
     * The assignee initials of each task, by task id (tasks without an assignee are left out).
     *
     * @param list<Task> $tasks
     *
     * @return array<string, list<string>>
     */
    public function assigneeInitials(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(TaskMember::class, 'm')
            ->where('m.task IN (:tasks)')
            ->andWhere('m.memberRole = :role')
            ->setParameter('tasks', array_map(static fn (Task $task): string => $task->getId(), $tasks))
            ->setParameter('role', TaskMemberRole::Assignee)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC');
        if ($this->users instanceof TaskBoardUsers) {
            $this->users->eager($qb, 'm.user', 'searchAssignee');
        } else {
            $qb->leftJoin('m.user', 'searchAssignee')->addSelect('searchAssignee');
        }
        $names = [];
        foreach ($qb->getQuery()->getResult() as $member) {
            if ($member instanceof TaskMember) {
                $user                                 = $this->users?->resolve($member->getUser()) ?? $member->getUser();
                $names[$member->getTask()->getId()][] = self::initials(UserLabel::of($user));
            }
        }

        return array_map(static function (array $initials): array {
            // Alphabetical: the same row reads the same on every search, whatever order the members were added in.
            sort($initials);
            $shown = array_slice($initials, 0, self::MAX_ASSIGNEES);
            if (count($initials) > self::MAX_ASSIGNEES) {
                $shown[] = '+' . (count($initials) - self::MAX_ASSIGNEES);
            }

            return $shown;
        }, $names);
    }

    /** Up to two initials of a label: the first letters of its first two words (an e-mail address: of its local part). */
    public static function initials(string $label): string
    {
        $name  = str_contains($label, '@') ? (string) strstr($label, '@', true) : $label;
        $words = preg_split('/[\s._-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = array_slice($words, 0, 2);

        return $first === [] ? '?' : mb_strtoupper(implode('', array_map(static fn (string $word): string => mb_substr($word, 0, 1), $first)));
    }
}
