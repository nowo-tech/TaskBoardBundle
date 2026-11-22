<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Doctrine\LockConflict;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\TaskBoardAccessDeniedException;
use Nowo\TaskBoardBundle\Exception\TaskBoardQuotaRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\User\TaskBoardUserLocator;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Nowo\TaskBoardBundle\View\TaskView;
use Nowo\TaskBoardBundle\View\Views;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function array_diff;
use function array_splice;
use function array_unique;
use function array_values;
use function count;
use function is_scalar;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function trim;

/**
 * Since 2.29.0: the bundle's {@see TaskCommandsInterface}, a thin layer over the services the pages use. Each call: the
 * user looked up again by id, the resources loaded by exact id, the page's access rule, then one write unit (transaction
 * or savepoint, events held until the commit, row lock on the task where edits race).
 *
 * Stateless (FrankenPHP worker mode with kernel reset off): every dependency is a stateless service or one the bundle
 * resets at each boundary; a failed unit resets a closed manager through the registry and reloads the task it touched.
 *
 * Since 2.30.2 (audit ZD-L1): the input goes through the same {@see TaskConstraints} as the page forms (http(s) link
 * URLs, hex task and column colours, at most {@see TaskConstraints::TAGS_MAX} tags, comment / note / label / external id
 * lengths) before anything is written; a value the forms refuse is an {@see InvalidArgumentException}.
 */
final readonly class TaskCommands implements TaskCommandsInterface
{
    /** Rows per batch of {@see self::clearTimeEntrySnapshotsForUser()}. */
    public const BATCH = 500;

    private ValidatorInterface $validator;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardIdAccessChecker $access,
        private TaskBoardResourceAccessChecker $resources,
        private TaskManager $tasks,
        private TaskMemberAssigner $members,
        private TaskCommentManagerInterface $comments,
        private TaskCommentPolicy $commentPolicy,
        private TaskLinkAttacher $links,
        private TaskDependencyManager $dependencies,
        private TaskTimeLoggerInterface $time,
        private BoardColumnManagerInterface $columns,
        private TaskBoardEraserInterface $eraser,
        private TaskBoardUserLocator $users,
        private TaskEventDeferral $events,
        private TaskBoardQuota $quota,
        private ?ManagerRegistry $managerRegistry = null,
        private TaskBoardUsers $userReferences = new TaskBoardUsers(),
        // The fixed lock order of the bundle (columns, then tasks): a column a write enters is locked before the task.
        private ?OrderedRowLockInterface $rowLock = null,
        // Since 2.30.2 (audit ZD-L1): checks the input against TaskConstraints; a standalone validator when none is given.
        ?ValidatorInterface $validator = null,
    ) {
        $this->validator = $validator ?? Validation::createValidator();
    }

    public function createTask(UserRef $creator, string $boardId, TaskFormData $data, ?string $parentId = null, bool $completed = false): TaskView
    {
        $actor = $this->actor($creator);
        $board = $this->reachableBoard($actor, $boardId);
        if (!$this->resources->canEditBoard($actor, $board)) {
            throw new TaskBoardAccessDeniedException();
        }
        $parent = null;
        if ($parentId !== null) {
            $parent = $this->editableTask($actor, $parentId);
            if ($parent->getBoard()->getId() !== $board->getId()) {
                throw new InvalidArgumentException('TaskBoard: the parent task is on another board.');
            }
        }
        $this->assertTaskData($data);
        // The host's plan (QUOTA_CHECK), as the board page asks before a new task.
        $plan = $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, $board->getTenantKey(), $board, 1, $actor);
        if ($plan !== null) {
            throw new TaskBoardQuotaRefusedException($plan);
        }
        $refusal = $this->tasks->activeTaskRefusal($board, $completed ? 0 : 1);
        if ($refusal instanceof LimitRefusal) {
            throw new TaskReopenRefusedException($refusal);
        }

        return $this->unit(function () use ($board, $data, $actor, $parent, $completed): TaskView {
            $task = $this->tasks->create($board, $data, $actor, $parent);
            if ($completed) {
                $this->tasks->complete($task, $actor);
            }

            return Views::task($task);
        });
    }

    public function updateTask(UserRef $actor, string $taskId, TaskFormData $data, ?int $expectedRevision = null): TaskView
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);
        $this->assertTaskData($data);

        return $this->unit(function () use ($task, $data, $user, $expectedRevision): TaskView {
            $this->lockColumn($data->columnId !== $task->getColumn()?->getId() ? $data->columnId : null);
            $this->lock($task, $expectedRevision);
            $this->tasks->update($task, $data, $user);

            return Views::task($task);
        }, $task);
    }

    public function moveTask(UserRef $actor, string $taskId, ?string $columnId, int $position, ?int $expectedRevision = null): TaskView
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);

        return $this->unit(function () use ($task, $columnId, $position, $user, $expectedRevision): TaskView {
            $this->lockColumn($columnId);
            $this->lock($task, $expectedRevision);
            $this->tasks->move($task, $columnId, max(0, $position), $user);

            return Views::task($task);
        }, $task);
    }

    public function completeTask(UserRef $actor, string $taskId): TaskView
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);

        // TaskManager locks the done column and the task in the bundle's fixed order itself.
        return $this->unit(function () use ($task, $user): TaskView {
            $this->tasks->complete($task, $user);

            return Views::task($task);
        }, $task);
    }

    public function archiveTask(UserRef $actor, string $taskId): TaskView
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);

        return $this->unit(function () use ($task, $user): TaskView {
            $this->lock($task, null);
            $this->tasks->archive($task, $user);

            return Views::task($task);
        }, $task);
    }

    public function restoreTask(UserRef $actor, string $taskId): TaskView
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);

        return $this->unit(function () use ($task, $user): TaskView {
            $this->lock($task, null);
            $this->tasks->restore($task, $user);

            return Views::task($task);
        }, $task);
    }

    public function deleteTask(UserRef $actor, string $taskId): int
    {
        $user = $this->actor($actor);
        $task = $this->reachableTask($user, $taskId);
        if (!$this->resources->canManageBoard($user, $task->getBoard())) {
            throw new TaskBoardAccessDeniedException();
        }

        return $this->unit(fn (): int => $this->eraser->eraseTask($task, $user));
    }

    public function addMember(UserRef $actor, string $taskId, UserRef $member, TaskMemberRole $role = TaskMemberRole::Assignee): string
    {
        $user   = $this->actor($actor);
        $task   = $this->editableTask($user, $taskId);
        $target = $this->users->find($member) ?? throw new TaskBoardAccessDeniedException();

        return $this->unit(function () use ($task, $target, $role, $user): string {
            $this->lock($task, null);

            return $this->members->assign($task, new TaskMemberFormData($target, $role), $user)->getId();
        }, $task);
    }

    public function removeMember(UserRef $actor, string $taskId, string $memberId): bool
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);

        return $this->unit(function () use ($task, $memberId, $user): bool {
            $this->lock($task, null);

            return $this->members->unassign($task, $memberId, $user);
        }, $task);
    }

    public function addComment(UserRef $author, string $taskId, string $body, array $mentionIds = []): string
    {
        $user = $this->actor($author);
        $task = $this->reachableTask($user, $taskId);
        if (!$this->resources->canViewTask($user, $task) || !$this->commentPolicy->mayComment($user, $task)) {
            throw new TaskBoardAccessDeniedException();
        }
        if (trim($body) === '') {
            throw new InvalidArgumentException('TaskBoard: a comment cannot be blank.');
        }
        $this->check($body, TaskConstraints::commentBody(), 'TaskBoard: a comment is too long.');

        // @igor-ignore - Call on a stateless bundle service / a Doctrine entity loaded for this call; nothing is kept on the service.
        return $this->unit(fn (): string => $this->comments->add($task, $user, $body, array_values($mentionIds))->getId(), $task);
    }

    public function addLink(UserRef $actor, string $taskId, TaskLinkFormData $data): string
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);
        if (trim($data->url) === '' || mb_strlen($data->url) > TaskConstraints::URL_MAX) {
            throw new InvalidArgumentException('TaskBoard: a link needs a URL of at most 2048 characters.');
        }
        // Audit ZD-L1: http(s) only, as the link form (no javascript:, data:, vbscript: … stored for a host template).
        $this->check($data->url, TaskConstraints::linkUrl(), 'TaskBoard: a link URL must be an http or https URL.');
        $this->check($data->label, TaskConstraints::linkLabel(), 'TaskBoard: a link label has at most 255 characters.');
        $this->check($data->externalId, TaskConstraints::linkExternalId(), 'TaskBoard: a link external id has at most 128 characters.');

        return $this->unit(fn (): string => $this->links->attach($task, $data, $user)->getId(), $task);
    }

    public function addHostObjectLink(UserRef $actor, string $taskId, string $objectType, string $objectId, ?string $label = null): string
    {
        $user = $this->actor($actor);
        $task = $this->editableTask($user, $taskId);
        if (trim($objectType) === '' || trim($objectId) === '') {
            throw new InvalidArgumentException('TaskBoard: a host object link needs a type and an id.');
        }
        $this->check($label, TaskConstraints::linkLabel(), 'TaskBoard: a link label has at most 255 characters.');

        return $this->unit(function () use ($task, $objectType, $objectId, $user, $label): string {
            // The task's row locked: two concurrent links of the same object never add two rows.
            $this->lock($task, null);

            return $this->links->attachObject($task, $objectType, $objectId, $user, $label)->getId();
        }, $task);
    }

    public function addDependency(UserRef $actor, string $taskId, string $otherTaskId, TaskDependencyType $type): string
    {
        $user  = $this->actor($actor);
        $task  = $this->editableTask($user, $taskId);
        $other = $this->editableTask($user, $otherTaskId);

        // @igor-ignore - Call on a stateless bundle service / a Doctrine entity loaded for this call; nothing is kept on the service.
        return $this->unit(fn (): string => $this->dependencies->add($task, $other, $type, $user)->getId(), $task);
    }

    public function addDocument(UserRef $author, string $taskId, string $title, string $content, ?int $position = null): string
    {
        $user = $this->actor($author);
        $task = $this->editableTask($user, $taskId);
        if (trim($title) === '' || mb_strlen($title) > TaskConstraints::TITLE_MAX) {
            throw new InvalidArgumentException('TaskBoard: a document needs a title of 1 to 255 characters.');
        }

        return $this->unit(function () use ($task, $title, $content, $user, $position): string {
            $this->lock($task, null);
            $document = new TaskDocument($task, $title, $content, $user, $position ?? count($task->getDocuments()));
            // @igor-ignore - Call on a stateless bundle service / a Doctrine entity loaded for this call; nothing is kept on the service.
            $task->getDocuments()->add($document);
            $this->entityManager->persist($document);
            $this->entityManager->flush();

            return $document->getId();
        }, $task);
    }

    public function logTime(UserRef $user, string $taskId, int $minutes, DateTimeImmutable $loggedAt, ?string $note = null): string
    {
        $actor = $this->actor($user);
        $task  = $this->reachableTask($actor, $taskId);
        if (!$this->resources->canTrackTime($actor, $task)) {
            throw new TaskBoardAccessDeniedException();
        }
        if ($minutes < 1 || $minutes > TaskConstraints::MAX_MINUTES) {
            throw new InvalidArgumentException('TaskBoard: minutes must be between 1 and 10080.');
        }
        $this->check($note, TaskConstraints::timeNote(), 'TaskBoard: a time note has at most 512 characters.');

        return $this->unit(fn (): string => $this->time->log($task, $actor, $minutes, $loggedAt, $note)->getId(), $task);
    }

    public function addColumn(UserRef $actor, string $boardId, BoardColumnFormData $data, ?int $position = null): string
    {
        $user  = $this->actor($actor);
        $board = $this->manageableColumns($user, $boardId);
        if (trim($data->name) === '' || mb_strlen($data->name) > TaskConstraints::COLUMN_NAME_MAX) {
            throw new InvalidArgumentException('TaskBoard: a column needs a name of 1 to 128 characters.');
        }
        $this->check($data->color, TaskConstraints::columnColor(), 'TaskBoard: a column colour is a hex colour (#rgb, #rgba, #rrggbb or #rrggbbaa).');

        return $this->unit(function () use ($board, $data, $user, $position): string {
            // @igor-ignore - Call on a stateless bundle service / a Doctrine entity loaded for this call; nothing is kept on the service.
            $column = $this->columns->add($board, $data, $user);
            if ($position !== null) {
                $ids = [];
                foreach ($board->getColumns() as $existing) {
                    if ($existing->getId() !== $column->getId()) {
                        $ids[] = $existing->getId();
                    }
                }
                array_splice($ids, min(max(0, $position), count($ids)), 0, [$column->getId()]);
                $this->columns->reorder($board, $ids, $user);
            }

            return $column->getId();
        });
    }

    public function reorderColumns(UserRef $actor, string $boardId, array $orderedColumnIds): void
    {
        $user  = $this->actor($actor);
        $board = $this->manageableColumns($user, $boardId);
        $ids   = [];
        foreach ($board->getColumns() as $column) {
            $ids[] = $column->getId();
        }
        $given = array_values($orderedColumnIds);
        if (count($given) !== count($ids) || count(array_unique($given)) !== count($given) || array_diff($given, $ids) !== []) {
            throw new InvalidArgumentException('TaskBoard: the order must list every column of the board exactly once.');
        }

        $this->unit(function () use ($board, $given, $user): void {
            $this->columns->reorder($board, $given, $user);
        });
    }

    public function adjustTotalTime(string $taskId, int $deltaSeconds): ?int
    {
        $task = $this->access->task($taskId);
        if (!$task instanceof Task) {
            return null;
        }

        return $this->unit(function () use ($task, $deltaSeconds): int {
            // The row is changed by the database, never from a value read in PHP: concurrent adjustments add up (the first
            // UPDATE locks the row until the unit ends), and the second statement clamps a negative total at 0.
            $this->entityManager->createQuery('UPDATE ' . Task::class . ' t SET t.totalTimeSeconds = t.totalTimeSeconds + :delta WHERE t.id = :id')
                ->setParameter('delta', $deltaSeconds)
                ->setParameter('id', $task->getId())
                ->execute();
            $this->entityManager->createQuery('UPDATE ' . Task::class . ' t SET t.totalTimeSeconds = 0 WHERE t.id = :id AND t.totalTimeSeconds < 0')
                ->setParameter('id', $task->getId())
                ->execute();
            // The loaded task follows the database (a bulk UPDATE bypasses the unit of work).
            $this->entityManager->refresh($task);

            return $task->getTotalTimeSeconds();
        });
    }

    public function clearTimeEntrySnapshotsForUser(string $userId): int
    {
        if (!TaskBoardUserRef::isValid($userId)) {
            throw new InvalidArgumentException('TaskBoard: the user id cannot be matched exactly.');
        }
        $identity = $this->userReferences->identity('e.user');
        $changed  = 0;
        $after    = null;
        do {
            $builder = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id', $identity . ' AS userKey')
                ->from(TaskTimeEntry::class, 'e')
                ->where('e.task IS NULL')
                ->andWhere($identity . ' = :user')
                ->andWhere('(e.taskTitleSnapshot IS NOT NULL OR e.taskExternalId IS NOT NULL)')
                ->setParameter('user', $userId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults(self::BATCH);
            if ($after !== null) {
                $builder->andWhere('e.id > :after')->setParameter('after', $after);
            }
            /** @var list<array{id: mixed, userKey: mixed}> $rows */
            $rows = $builder->getQuery()->getArrayResult();
            $ids  = [];
            foreach ($rows as $row) {
                $key = $row['userKey'] instanceof TaskBoardUserRef ? $row['userKey']->id : $row['userKey'];
                // Exactly this user: a collation never clears a look-alike user's entries.
                if (is_scalar($key) && (string) $key === $userId && is_scalar($row['id'])) {
                    $ids[] = (string) $row['id'];
                }
                $after = is_scalar($row['id']) ? (string) $row['id'] : $after;
            }
            if ($ids !== []) {
                $changed += $this->unit(fn (): int => (int) $this->entityManager->createQuery('UPDATE ' . TaskTimeEntry::class . ' e SET e.taskTitleSnapshot = NULL, e.taskExternalId = NULL WHERE e.id IN (:ids) AND e.task IS NULL')
                    ->setParameter('ids', $ids)
                    ->execute());
            }
        } while (count($rows) === self::BATCH);

        return $changed;
    }

    /** The host user behind the reference, when it exists and passes the global gate. */
    private function actor(UserRef $ref): object
    {
        return $this->access->user($ref) ?? throw new TaskBoardAccessDeniedException();
    }

    private function editableTask(object $user, string $taskId): Task
    {
        $task = $this->reachableTask($user, $taskId);
        if (!$this->resources->canEditTask($user, $task)) {
            throw new TaskBoardAccessDeniedException();
        }

        return $task;
    }

    /** Audit YJ-M1: the board by exact id, in a tenant the user reaches (tenancy), else the one refusal. */
    private function reachableBoard(object $user, string $boardId): TaskBoard
    {
        $board = $this->access->board($boardId);
        if (!$board instanceof TaskBoard || !$this->access->reaches($user, $board)) {
            throw new TaskBoardAccessDeniedException();
        }

        return $board;
    }

    /** Audit YJ-M1: the task by exact id, on a board in a tenant the user reaches (tenancy), else the one refusal. */
    private function reachableTask(object $user, string $taskId): Task
    {
        $task = $this->access->task($taskId);
        if (!$task instanceof Task || !$this->access->reaches($user, $task->getBoard())) {
            throw new TaskBoardAccessDeniedException();
        }

        return $task;
    }

    private function manageableColumns(object $user, string $boardId): TaskBoard
    {
        $board = $this->reachableBoard($user, $boardId);
        if (!$this->resources->canEditBoard($user, $board) || !$this->resources->canManageColumns($user, $board)) {
            throw new TaskBoardAccessDeniedException();
        }

        return $board;
    }

    private function lockColumn(?string $columnId): void
    {
        if ($columnId !== null && $columnId !== '') {
            $this->rowLock?->lockColumns([$columnId]);
        }
    }

    /**
     * The task form's rules (audit ZD-L1): title, `#rrggbb` colour, at most {@see TaskConstraints::TAGS_MAX} tags of at
     * most {@see TaskConstraints::TAG_MAX} characters.
     *
     * @throws InvalidArgumentException
     */
    private function assertTaskData(TaskFormData $data): void
    {
        if (trim($data->title) === '' || mb_strlen($data->title) > TaskConstraints::TITLE_MAX) {
            throw new InvalidArgumentException('TaskBoard: a task needs a title of 1 to 255 characters.');
        }
        $this->check($data->title, TaskConstraints::taskTitle(), 'TaskBoard: a task needs a title of 1 to 255 characters.');
        $this->check($data->color, TaskConstraints::taskColor(), 'TaskBoard: a task colour is #rrggbb.');
        if (count($data->tags) > TaskConstraints::TAGS_MAX) {
            throw new InvalidArgumentException('TaskBoard: a task has at most 20 tags.');
        }
        foreach ($data->tags as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('TaskBoard: a tag is a string of 1 to 64 characters.');
            }
            $this->check($tag, TaskConstraints::tagName(), 'TaskBoard: a tag is a string of 1 to 64 characters.');
        }
    }

    /**
     * The value against the forms' constraints; any violation is the given refusal.
     *
     * @param list<Constraint> $constraints
     *
     * @throws InvalidArgumentException
     */
    private function check(mixed $value, array $constraints, string $message): void
    {
        if ($this->validator->validate($value, $constraints)->count() > 0) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * The task's row locked until the unit ends, and the task as committed. With an expected revision, another one is
     * {@see TaskChangedException}. Inside an outer REPEATABLE READ transaction (MySQL / MariaDB) a refresh may still read
     * that transaction's snapshot: then the write is refused as changed (never written from a stale copy).
     *
     * @throws TaskChangedException
     * @throws TaskBoardAccessDeniedException when the task is gone
     */
    private function lock(Task $task, ?int $expectedRevision): void
    {
        // Audit YJ-L2: a task deleted since it was loaded is the one refusal, not a Doctrine NoResultException.
        $revision = $this->entityManager->createQuery('SELECT t.revision FROM ' . Task::class . ' t WHERE t.id = :id')
            ->setParameter('id', $task->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);
        if ($revision === null) {
            throw new TaskBoardAccessDeniedException();
        }
        $revision = is_scalar($revision) ? (int) $revision : 0;
        $boardId  = $task->getBoard()->getId();
        if ($revision !== $task->getRevision()) {
            $this->entityManager->refresh($task);
        }
        // A refresh that still gives another revision (an outer REPEATABLE READ snapshot) is never written from; nor is a
        // task moved to another board meanwhile (audit YJ-L3: the access decided before was for the old board).
        if ($task->getRevision() !== $revision || $task->getBoard()->getId() !== $boardId || ($expectedRevision !== null && $expectedRevision !== $revision)) {
            throw new TaskChangedException($revision);
        }
    }

    /**
     * One write unit: a transaction (a savepoint inside the application's), the task events held until it commits.
     *
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private function unit(Closure $write, ?Task $touched = null): mixed
    {
        $connection = $this->entityManager->getConnection();
        $depth      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        $this->events->begin();
        try {
            $result = $write();
            $connection->commit();
        } catch (Throwable $failure) {
            try {
                TransactionRollback::toLevel($connection, $depth);
            } finally {
                $this->events->rollBack();
                RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);
                $this->reload($touched);
            }
            if (LockConflict::is($failure)) {
                throw TransientStorageFailure::fromPrevious($failure);
            }

            throw $failure;
        }
        // After the commit; a failing host listener never reports the saved write as failed (logged).
        $this->events->commit(false);

        return $result;
    }

    private function reload(?Task $task): void
    {
        if (!$task instanceof Task || !$this->entityManager->isOpen()) {
            return;
        }
        try {
            if ($this->entityManager->contains($task)) {
                $this->entityManager->refresh($task);
            }
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            // Best effort: a task the rollback removed, or a lost connection, leaves the next read to the database.
        }
        // @codeCoverageIgnoreEnd
    }
}
