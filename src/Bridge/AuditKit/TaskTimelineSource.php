<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\AuditKit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\AuditKitBundle\Timeline\TimelineCriteria;
use Nowo\AuditKitBundle\Timeline\TimelineCursor;
use Nowo\AuditKitBundle\Timeline\TimelineEvent;
use Nowo\AuditKitBundle\Timeline\TimelineSourceInterface;
use Nowo\AuditKitBundle\Timeline\TimelineSubject;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function date_default_timezone_get;
use function in_array;
use function max;
use function mb_strlen;
use function min;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function usort;

/**
 * Since 3.7.0 (Beacon spec 235 T003): the task changes ({@see TaskChangeHistory}) and comments ({@see TaskComment}) of
 * one task as an AuditKit 2.2 timeline source (name {@see self::NAME}; registered only when
 * `Nowo\AuditKitBundle\Timeline\TimelineSourceInterface` exists, tagged `nowo_audit_kit.timeline_source`).
 *
 * Subject type {@see self::SUBJECT_TASK} only (`id` = the task id); every other type — `user`, `scope`, `correlation` —
 * gives no entry, so the source never reads across tasks or tenants. A tenant-facing "one unit of work" view asks with
 * `TimelineCriteria(correlationId: …)` on the task subject.
 *
 * Visibility: the signed-in user (the security token) must be allowed the task board (`TaskBoardAccessCheckerInterface`),
 * the task's board must belong to the request's tenant (`TaskBoardTenantContext::owns()`, with tenancy) and the user must
 * see the task (`TaskBoardResourceAccessChecker::canViewTask()`) — the checks of the task page. Otherwise, without a
 * signed-in user, or for an unknown task: no entry (the same answer, never an oracle).
 *
 * Entries, newest first, ties by id descending: `history:<uuid>` with kind `task.<change type>` (`changes` =
 * `{<change type>: {from, to}}`, a description or a value over 500 characters as `{changed: true, from_len, to_len}`),
 * and `comment:<uuid>` with kind `comment.added` (no body: the payload has ids and the `deleted` / `edited` flags).
 * Both carry `actorId`, `channel` and `tokenLabel`; history rows `correlationId`. Comments have no correlation id: with a
 * correlation filter they give none. The payload holds the actor's name snapshot: treat it as personal data.
 *
 * Precision: both tables store whole seconds in the server's timezone. Cursors are compared there, and a cursor with a
 * fraction of a second (another source's) is treated as just after its whole second (as AuditKit's action log). Each
 * `occurredAt` is that whole second. Stateless (FrankenPHP worker mode).
 */
final readonly class TaskTimelineSource implements TimelineSourceInterface
{
    public const NAME = 'task_board';

    public const SUBJECT_TASK = 'task';

    public const KIND_COMMENT = 'comment.added';

    /** Prefix of the history kinds (`task.title`, `task.column`, ...). */
    public const KIND_PREFIX = 'task.';

    /** Longest value given as `{from, to}` (characters); longer: `{changed, from_len, to_len}`. */
    public const VALUE_MAX = 500;

    /** Most entries one call returns. */
    public const MAX_LIMIT = 500;

    private const HISTORY = 'history:';

    private const COMMENT = 'comment:';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private TaskBoardAccessCheckerInterface $access,
        private TaskBoardResourceAccessChecker $resourceAccess,
        private ?TaskBoardTenantContext $tenantContext = null,
        private string $name = self::NAME,
    ) {
    }

    public function page(TimelineSubject $subject, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit): array
    {
        $limit = min(max($limit, 0), self::MAX_LIMIT);
        $task  = $subject->type === self::SUBJECT_TASK && $limit > 0 ? $this->visibleTask($subject->id) : null;
        if (!$task instanceof Task) {
            return [];
        }
        [$types, $comments] = $this->kinds($criteria->kinds);
        $events             = [];
        if ($types !== []) {
            $events = array_map($this->historyEvent(...), $this->history($task, $criteria, $after, $limit, $types));
        }
        if ($comments && $criteria->correlationId === null) {
            $events = [...$events, ...array_map($this->commentEvent(...), $this->comments($task, $criteria, $after, $limit))];
        }
        usort($events, static fn (TimelineEvent $a, TimelineEvent $b): int => [$b->occurredAt, $b->id] <=> [$a->occurredAt, $a->id]);

        return array_slice($events, 0, $limit);
    }

    /** The task when the signed-in user may see it now (else null, whatever the reason). */
    private function visibleTask(string $id): ?Task
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface || strlen($id) > 64 || !$this->access->canAccess($user)) {
            return null;
        }
        $task = $this->entityManager->find(Task::class, $id);
        if (!$task instanceof Task || $this->tenantContext?->owns($task->getBoard(), $user) === false || !$this->resourceAccess->canViewTask($user, $task)) {
            return null;
        }

        return $task;
    }

    /**
     * The change types and whether comments are asked for (`kinds` null: all; `[]`: none).
     *
     * @param list<string>|null $kinds
     *
     * @return array{0: list<TaskChangeType>, 1: bool}
     */
    private function kinds(?array $kinds): array
    {
        if ($kinds === null) {
            return [TaskChangeType::cases(), true];
        }
        $types = [];
        foreach (TaskChangeType::cases() as $type) {
            if (in_array(self::KIND_PREFIX . $type->value, $kinds, true)) {
                $types[] = $type;
            }
        }

        return [$types, in_array(self::KIND_COMMENT, $kinds, true)];
    }

    /**
     * @param list<TaskChangeType> $types
     *
     * @return list<TaskChangeHistory>
     */
    private function history(Task $task, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit, array $types): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(TaskChangeHistory::class, 'h')
            ->andWhere('h.task = :task')
            ->setParameter('task', $task->getId())
            ->andWhere('h.changeType IN (:types)')
            ->setParameter('types', array_map(static fn (TaskChangeType $type): string => $type->value, $types));
        if ($criteria->correlationId !== null) {
            $query->andWhere('h.correlationId = :correlation')->setParameter('correlation', $criteria->correlationId);
        }
        $this->bound($query, 'h', self::HISTORY, $criteria, $after);
        /** @var list<TaskChangeHistory> $rows */
        $rows = $query->orderBy('h.createdAt', 'DESC')->addOrderBy('h.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

        // Defence in depth: a case-insensitive collation never widens a correlation filter.
        return $criteria->correlationId === null ? $rows : array_values(array_filter($rows, static fn (TaskChangeHistory $row): bool => $row->getCorrelationId() === $criteria->correlationId));
    }

    /**
     * @return list<TaskComment>
     */
    private function comments(Task $task, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(TaskComment::class, 'c')
            ->andWhere('c.task = :task')
            ->setParameter('task', $task->getId());
        $this->bound($query, 'c', self::COMMENT, $criteria, $after);
        /** @var list<TaskComment> $rows */
        $rows = $query->orderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

        return $rows;
    }

    /**
     * The date range and the keyset bound of one table (see {@see TimelineCursor::boundFor()}): this source orders by
     * `createdAt` descending, then by its prefixed id descending.
     */
    private function bound(QueryBuilder $query, string $alias, string $prefix, TimelineCriteria $criteria, ?TimelineCursor $after): void
    {
        if ($criteria->since instanceof DateTimeImmutable) {
            $query->andWhere($alias . '.createdAt >= :since')->setParameter('since', $this->local($criteria->since), 'datetime_immutable');
        }
        if ($criteria->until instanceof DateTimeImmutable) {
            $query->andWhere($alias . '.createdAt <= :until')->setParameter('until', $this->local($criteria->until), 'datetime_immutable');
        }
        if (!$after instanceof TimelineCursor) {
            return;
        }
        $local    = $after->occurredAt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $second   = $this->second($local);
        $fraction = $local->format('u') !== '000000';
        $query->setParameter('at', $second, 'datetime_immutable');
        $bound = $after->boundFor($this->name);
        // A cursor between two whole seconds sees this source's rows of its second as older; so does a later source name.
        if ($fraction || $bound === TimelineCursor::SAME_INSTANT) {
            $query->andWhere($alias . '.createdAt <= :at');

            return;
        }
        if ($bound === TimelineCursor::OLDER) {
            $query->andWhere($alias . '.createdAt < :at');

            return;
        }
        // AFTER_ID: at the cursor's second, the entries whose prefixed id sorts before the cursor's id.
        $order = strcmp(substr($after->id, 0, strlen($prefix)), $prefix);
        if (str_starts_with($after->id, $prefix)) {
            $query->andWhere('(' . $alias . '.createdAt < :at OR (' . $alias . '.createdAt = :at AND ' . $alias . '.id < :afterId))')
                ->setParameter('afterId', substr($after->id, strlen($prefix)));

            return;
        }
        // Another prefix: every row of this table at that second sorts before (cursor prefix after ours) or after it.
        $query->andWhere($alias . '.createdAt ' . ($order > 0 ? '<=' : '<') . ' :at');
    }

    private function historyEvent(TaskChangeHistory $row): TimelineEvent
    {
        $type  = $row->getChangeType()->value;
        $user  = $row->getUser();
        $old   = $row->getOldValue();
        $new   = $row->getNewValue();
        $plain = $row->getChangeType() !== TaskChangeType::Description && mb_strlen((string) $old) <= self::VALUE_MAX && mb_strlen((string) $new) <= self::VALUE_MAX;

        return new TimelineEvent(
            source: $this->name,
            id: self::HISTORY . $row->getId(),
            occurredAt: $this->second($row->getCreatedAt()),
            kind: self::KIND_PREFIX . $type,
            actorId: $user === null ? null : SyncUser::id($user),
            channel: $row->getChannel()?->value,
            tokenLabel: $row->getActorLabel(),
            correlationId: $row->getCorrelationId(),
            changes: [$type => $plain ? ['from' => $old, 'to' => $new] : ['changed' => true, 'from_len' => mb_strlen((string) $old), 'to_len' => mb_strlen((string) $new)]],
            payload: ['task_id' => $row->getTask()->getId(), 'actor_name' => $row->getUserName(), 'context' => $row->getContext(), 'cause' => $row->getCause()],
        );
    }

    private function commentEvent(TaskComment $comment): TimelineEvent
    {
        $author = $comment->getAuthor();

        return new TimelineEvent(
            source: $this->name,
            id: self::COMMENT . $comment->getId(),
            occurredAt: $this->second($comment->getCreatedAt()),
            kind: self::KIND_COMMENT,
            actorId: $author === null ? null : SyncUser::id($author),
            channel: $comment->getChannel()?->value,
            tokenLabel: $comment->getActorLabel(),
            payload: ['task_id' => $comment->getTask()->getId(), 'comment_id' => $comment->getId(), 'actor_name' => $comment->getAuthorName(), 'deleted' => $comment->isDeleted(), 'edited' => $comment->getEditedAt() instanceof DateTimeImmutable],
        );
    }

    /** A bound of the criteria in the server's timezone (where the rows are stored). */
    private function local(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /** The whole second of an instant, in the server's timezone (the precision of the rows). */
    private function second(DateTimeImmutable $at): DateTimeImmutable
    {
        $local = $this->local($at);

        return new DateTimeImmutable($local->format('Y-m-d H:i:s'), $local->getTimezone());
    }
}
