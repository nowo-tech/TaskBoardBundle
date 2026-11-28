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
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Stringable;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function array_slice;
use function count;
use function date_default_timezone_get;
use function in_array;
use function is_scalar;
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
 * Rows are read as arrays (nothing enters the host's identity map, audit ZDX-TB-I5); comments only while the comments
 * repository is wired (ZDX-TB-I4). At most {@see self::MAX_LIMIT} entries, so AuditKit's merger (500 + 1) sees its next
 * page (ZDX-TB-L1).
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

    /**
     * Most entries one call returns (audit ZDX-TB-L1): above AuditKit's merger page (500) plus the one it asks for to know
     * there is a next page.
     */
    public const MAX_LIMIT = 1000;

    private const HISTORY = 'history:';

    private const COMMENT = 'comment:';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private TaskBoardAccessCheckerInterface $access,
        private TaskBoardResourceAccessChecker $resourceAccess,
        private ?TaskBoardTenantContext $tenantContext = null,
        // Audit ZDX-TB-I4: without the comments repository (comments off) the timeline lists no comment either.
        private ?TaskCommentRepositoryInterface $commentRepository = null,
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
            $events = array_map(fn (array $row): TimelineEvent => $this->historyEvent($row, $task->getId()), $this->history($task, $criteria, $after, $limit, $types));
        }
        if ($comments && $criteria->correlationId === null && $this->commentRepository instanceof TaskCommentRepositoryInterface) {
            $events = [...$events, ...array_map(fn (array $row): TimelineEvent => $this->commentEvent($row, $task->getId()), $this->comments($task, $criteria, $after, $limit))];
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
     * The history rows as arrays (audit ZDX-TB-I5: nothing enters the host's identity map). With a correlation filter
     * the column is compared case-insensitively in SQL and exactly in PHP; rows the exact check drops never take a slot
     * (audit ZDX-TB-L1): the next keyset batch is read until `$limit` rows are kept or the task has no more.
     *
     * @param list<TaskChangeType> $types
     *
     * @return list<array<string, mixed>>
     */
    private function history(Task $task, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit, array $types): array
    {
        $kept = [];
        $last = null;
        do {
            $query = $this->select('h', TaskChangeHistory::class, ['h.changeType AS changeType', 'h.oldValue AS oldValue', 'h.newValue AS newValue', 'h.context AS context', 'h.cause AS cause', 'h.userName AS actorName', 'h.correlationId AS correlationId'], 'user', $task)
                ->andWhere('h.changeType IN (:types)')
                ->setParameter('types', array_map(static fn (TaskChangeType $type): string => $type->value, $types));
            if ($criteria->correlationId !== null) {
                // @igor-ignore - A query builder created by this call (select()), not a shared service.
                $query->andWhere('LOWER(h.correlationId) = LOWER(:correlation)')->setParameter('correlation', $criteria->correlationId);
            }
            $this->bound($query, 'h', self::HISTORY, $criteria, $after);
            if ($last !== null) {
                // @igor-ignore - A query builder created by this call (select()), not a shared service.
                $query->andWhere('(h.createdAt < :lastAt OR (h.createdAt = :lastAt AND h.id < :lastId))')
                    ->setParameter('lastAt', $last['createdAt'], 'datetime_immutable')
                    ->setParameter('lastId', $last['id']);
            }
            /** @var list<array<string, mixed>> $batch */
            // @igor-ignore - A query builder created by this call (select()), not a shared service.
            $batch = $query->setMaxResults($limit)->getQuery()->getArrayResult();
            foreach ($batch as $row) {
                if ($criteria->correlationId === null || $row['correlationId'] === $criteria->correlationId) {
                    $kept[] = $row;
                }
                $last = $row;
            }
        } while (count($batch) === $limit && count($kept) < $limit);

        return array_slice($kept, 0, $limit);
    }

    /**
     * The comments as arrays (audit ZDX-TB-I5).
     *
     * @return list<array<string, mixed>>
     */
    private function comments(Task $task, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit): array
    {
        $query = $this->select('c', TaskComment::class, ['c.authorName AS actorName', 'c.deletedAt AS deletedAt', 'c.editedAt AS editedAt'], 'author', $task);
        $this->bound($query, 'c', self::COMMENT, $criteria, $after);
        /** @var list<array<string, mixed>> $rows */
        $rows = $query->setMaxResults($limit)->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * The scalar columns of one table for the task, newest first. The actor is the user column's value: the association's
     * id, or the user reference itself with `database.user_reference: id`.
     *
     * @param class-string $class
     * @param list<string> $columns
     */
    private function select(string $alias, string $class, array $columns, string $userField, Task $task): QueryBuilder
    {
        $user = $this->entityManager->getClassMetadata($class)->hasAssociation($userField) ? 'IDENTITY(' . $alias . '.' . $userField . ')' : $alias . '.' . $userField;

        return $this->entityManager->createQueryBuilder()
            ->select([$alias . '.id AS id', $alias . '.createdAt AS createdAt', $alias . '.channel AS channel', $alias . '.actorLabel AS actorLabel', $user . ' AS actorId', ...$columns])
            ->from($class, $alias)
            ->andWhere($alias . '.task = :task')
            ->setParameter('task', $task->getId())
            ->orderBy($alias . '.createdAt', 'DESC')
            ->addOrderBy($alias . '.id', 'DESC');
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

    /**
     * @param array<string, mixed> $row
     */
    private function historyEvent(array $row, string $taskId): TimelineEvent
    {
        $type  = $this->text($row['changeType'] instanceof TaskChangeType ? $row['changeType']->value : $row['changeType']) ?? '';
        $old   = $this->text($row['oldValue']);
        $new   = $this->text($row['newValue']);
        $plain = $type !== TaskChangeType::Description->value && mb_strlen((string) $old) <= self::VALUE_MAX && mb_strlen((string) $new) <= self::VALUE_MAX;

        return new TimelineEvent(
            source: $this->name,
            id: self::HISTORY . $this->text($row['id']),
            occurredAt: $this->second($this->date($row['createdAt'])),
            kind: self::KIND_PREFIX . $type,
            actorId: $this->text($row['actorId']),
            channel: WriteChannel::tryFrom((string) $this->text($row['channel']))?->value,
            tokenLabel: $this->text($row['actorLabel']),
            correlationId: $this->text($row['correlationId']),
            changes: [$type => $plain ? ['from' => $old, 'to' => $new] : ['changed' => true, 'from_len' => mb_strlen((string) $old), 'to_len' => mb_strlen((string) $new)]],
            payload: ['task_id' => $taskId, 'actor_name' => $this->text($row['actorName']), 'context' => $this->text($row['context']), 'cause' => $this->text($row['cause'])],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function commentEvent(array $row, string $taskId): TimelineEvent
    {
        $id = (string) $this->text($row['id']);

        return new TimelineEvent(
            source: $this->name,
            id: self::COMMENT . $id,
            occurredAt: $this->second($this->date($row['createdAt'])),
            kind: self::KIND_COMMENT,
            actorId: $this->text($row['actorId']),
            channel: WriteChannel::tryFrom((string) $this->text($row['channel']))?->value,
            tokenLabel: $this->text($row['actorLabel']),
            payload: ['task_id' => $taskId, 'comment_id' => $id, 'actor_name' => $this->text($row['actorName']), 'deleted' => $row['deletedAt'] instanceof DateTimeImmutable, 'edited' => $row['editedAt'] instanceof DateTimeImmutable],
        );
    }

    /** A scalar or Stringable column value (a user reference with `database.user_reference: id`) as a string. */
    private function text(mixed $value): ?string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : null;
    }

    /** The `createdAt` column (hydrated as a `DateTimeImmutable`; the epoch for anything else). */
    private function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable('@0');
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
