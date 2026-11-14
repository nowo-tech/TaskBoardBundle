<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

use function array_chunk;
use function array_filter;
use function array_keys;
use function array_map;
use function in_array;
use function max;
use function min;
use function strval;

/**
 * Since 2.14.0 (Beacon spec 204): asks `calendar.day_annotation_provider` for a calendar page: one call for the viewer's
 * days, one query for the assignees of the page's tasks and one call for all their days, then the warnings of each task.
 * Without a provider it does nothing and the page is drawn as in 2.13. Stateless: everything lives in the call.
 *
 * @internal
 */
final readonly class CalendarDayAnnotator
{
    /** Task ids per query of the assignee lookup. */
    private const ID_CHUNK = 500;

    /**
     * Since 2.15.1 (REQ-SEC-006): prefix of the keys of the provider's answer. A PHP array casts a canonical integer
     * string key to an int (`'1'` and `1` are one key), so a map keyed by a host's user ids is not compared byte for
     * byte; a key that cannot be an integer never is.
     */
    private const KEY_PREFIX = "user\0";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?CalendarDayAnnotationProviderInterface $provider = null,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $users = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->provider instanceof CalendarDayAnnotationProviderInterface;
    }

    /**
     * @param list<Task> $tasks the tasks of the page
     */
    public function annotate(CalendarViewer $viewer, CalendarDateRange $range, array $tasks): ?CalendarAnnotations
    {
        if (!$this->provider instanceof CalendarDayAnnotationProviderInterface) {
            return null;
        }

        $days      = $this->provider->viewerDays($viewer, $range);
        $assignees = $this->assignees($tasks);
        $userIds   = [];
        foreach ($assignees as $ids) {
            foreach ($ids as $id) {
                $userIds[$id] = true;
            }
        }
        $asked  = array_map(strval(...), array_keys($userIds));
        $people = $this->exactly($userIds === [] ? [] : $this->provider->assigneeDays($viewer, $asked, $range), $asked);

        $warnings = [];
        // Only dated tasks are on a calendar.
        foreach (array_filter($tasks, static fn (Task $task): bool => $task->getStartAt() !== null || $task->getDueAt() !== null) as $task) {
            $first = (string) ($task->getStartAt() ?? $task->getDueAt())?->format('Y-m-d');
            $last  = (string) ($task->getDueAt() ?? $task->getStartAt())?->format('Y-m-d');
            // Within the page only: the provider was asked for these days.
            $from = max($first, $range->from);
            $to   = min(max($first, $last), $range->to);
            foreach ($assignees[$task->getId()] ?? [] as $userId) {
                $personal = $people[self::KEY_PREFIX . $userId] ?? null;
                if (!$personal instanceof CalendarDayAnnotations) {
                    continue;
                }
                for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
                    $annotation = $personal->for($day->format('Y-m-d'));
                    if ($annotation instanceof CalendarDayAnnotation && $annotation->nonWorking) {
                        $warnings[$task->getId()][] = new CalendarTaskWarning($userId, $annotation);
                    }
                }
            }
        }

        return new CalendarAnnotations($days, $warnings);
    }

    /**
     * Since 2.15.1 (REQ-SEC-006): the provider's answer keyed by the asked ids, byte for byte. A host that answers with
     * a key that is not exactly one of the ids asked (another spelling, another type, a look-alike) annotates nobody,
     * instead of being matched by PHP's array-key juggling. The ids asked are the bundle's own
     * (`TaskMember` of the page's tasks), so a key the bundle did not ask for is dropped whatever it holds.
     *
     * @param array<array-key, CalendarDayAnnotations> $people
     * @param list<string> $asked
     *
     * @return array<string, CalendarDayAnnotations>
     */
    private function exactly(array $people, array $asked): array
    {
        $exact = [];
        foreach ($people as $id => $annotations) {
            if (in_array((string) $id, $asked, true)) {
                $exact[self::KEY_PREFIX . $id] = $annotations;
            }
        }

        return $exact;
    }

    /**
     * The assignee ids of each task (one query per 500 tasks).
     *
     * @param list<Task> $tasks
     *
     * @return array<string, list<string>>
     */
    private function assignees(array $tasks): array
    {
        $assignees = [];
        foreach (array_chunk(array_map(static fn (Task $task): string => $task->getId(), $tasks), self::ID_CHUNK) as $chunk) {
            /** @var list<array{taskId: mixed, userId: mixed}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(m.task) AS taskId', $this->userValue('m.user') . ' AS userId')
                ->from(TaskMember::class, 'm')
                ->where('IDENTITY(m.task) IN (:tasks)')
                ->andWhere('m.memberRole = :role')
                ->setParameter('tasks', $chunk)
                ->setParameter('role', TaskMemberRole::Assignee)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $assignees[(string) $row['taskId']][] = (string) $row['userId'];
            }
        }

        return $assignees;
    }

    /** Since 2.15.0: the DQL of a user field's value (`IDENTITY()` for a relation, the column itself with user_reference: id). */
    private function userValue(string $path): string
    {
        return $this->users instanceof TaskBoardUsers ? $this->users->identity($path) : 'IDENTITY(' . $path . ')';
    }
}
