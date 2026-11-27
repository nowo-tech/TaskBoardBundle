<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\PersonalData;

use BackedEnum;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Question\DefaultTaskQuestionRecipientResolver;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

use function array_chunk;
use function array_map;
use function array_merge;
use function in_array;
use function is_string;
use function str_starts_with;
use function substr;
use function usort;

use const DATE_ATOM;

/**
 * Since 3.4.0 (Beacon WORKLIST, RGPD Art. 15 and 20): the TaskBoard part of a person's export. One section per step of
 * the account erasure ({@see self::ERASURE_SECTIONS}: what `TaskSyncPeopleReassigner::detachUser()` and
 * `TaskRecurrenceManager::forgetAuthor()` detach is what this lists), plus the rows the database drops with the user
 * (assignments) and the custom field values the person set ({@see self::EXTRA_SECTIONS}).
 *
 * What is in it: ids, dates, roles, counts and the names the person gave (board name, task title, document title), as
 * the other kits' exports. What is not: description, comment, document and question bodies (they may name other
 * people; the host adds them when it must), and never a secret or a token (provider credentials, connection references,
 * OAuth state, idempotency answers, run tokens).
 *
 * The rows of exactly this user: with `database.user_reference: id` the database's candidates are kept only when their id
 * is equal byte for byte (REQ-SEC-006, as the erasure). Read-only: no lock and no write. Stateless (worker mode).
 */
final readonly class TaskBoardPersonalDataExporter implements TaskBoardPersonalDataExporterInterface
{
    /**
     * Erasure step ({@see TaskSyncPeopleReassigner::ERASURE_STEPS}, plus `recurrence`) => the export section that lists
     * what the step changes. Pinned by a test: a new erasure step needs its section.
     */
    public const ERASURE_SECTIONS = [
        'sync_people'  => 'sync_people',
        'questions'    => 'questions',
        'import_plans' => 'import_plans',
        'tasks'        => 'tasks_created',
        'comments'     => 'comments',
        'boards'       => 'boards',
        'documents'    => 'documents',
        'history'      => 'history',
        'last_writes'  => 'last_writes',
        'member_rows'  => 'member_history',
        'time_entries' => 'time_entries',
        'recurrence'   => 'recurrence',
    ];

    /** Sections no erasure step changes: the database removes the assignments with the user; values stay on the task. */
    public const EXTRA_SECTIONS = ['task_memberships', 'custom_field_values'];

    /** Every section, in the order of the document. */
    public const SECTIONS = ['boards', 'tasks_created', 'task_memberships', 'comments', 'documents', 'history', 'member_history', 'last_writes', 'custom_field_values', 'time_entries', 'questions', 'recurrence', 'import_plans', 'sync_people'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardUsers $users = new TaskBoardUsers(),
        // With `sync.enabled`: the remote people mapped or suggested to the user.
        private ?TaskSyncPersonRepository $people = null,
        // With `sync.enabled` and `sync.plans.enabled`: the import plans they created or started.
        private bool $importPlans = false,
        // With `task_questions.enabled`.
        private bool $questions = false,
        // With `recurrence.enabled`.
        private bool $recurrence = false,
    ) {
    }

    public function export(object $user): array
    {
        $id = SyncUser::id($user);
        if ($id === null) {
            return array_map(static fn (): array => [], array_flip(self::SECTIONS));
        }

        return [
            'boards'           => $this->rows(TaskBoard::class, 'creator', $user, ['x.id AS id', 'x.name AS name', 'x.tenantKey AS tenant_key', 'x.createdAt AS created_at', 'x.archivedAt AS archived_at']),
            'tasks_created'    => $this->rows(Task::class, 'creator', $user, ['x.id AS id', 'IDENTITY(x.board) AS board_id', 'x.number AS number', 'x.title AS title', 'x.createdAt AS created_at', 'x.completedAt AS completed_at', 'x.archivedAt AS archived_at']),
            'task_memberships' => $this->rows(TaskMember::class, 'user', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.memberRole AS role', 'x.createdAt AS created_at']),
            'comments'         => $this->rows(TaskComment::class, 'author', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.createdAt AS created_at', 'x.editedAt AS edited_at', 'x.deletedAt AS deleted_at']),
            'documents'        => $this->rows(TaskDocument::class, 'creator', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.title AS title', 'x.createdAt AS created_at', 'x.updatedAt AS updated_at']),
            'history'          => $this->rows(TaskChangeHistory::class, 'user', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.changeType AS change_type', 'x.channel AS channel', 'x.createdAt AS created_at']),
            'member_history'   => $this->memberHistory($id),
            'last_writes'      => [
                ...array_map(static fn (array $row): array => ['type' => 'task'] + $row, $this->rows(Task::class, 'updatedBy', $user, ['x.id AS id', 'x.channel AS channel', 'x.updatedAt AS updated_at'])),
                ...array_map(static fn (array $row): array => ['type' => 'comment'] + $row, $this->rows(TaskComment::class, 'updatedBy', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.channel AS channel', 'x.editedAt AS updated_at'])),
            ],
            'custom_field_values' => $this->customFieldValues($user),
            'time_entries'        => $this->rows(TaskTimeEntry::class, 'user', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.boardIdSnapshot AS board_id', 'x.minutes AS minutes', 'x.loggedAt AS logged_at', 'x.billable AS billable']),
            'questions'           => $this->questions ? $this->questionRows($user, $id) : [],
            'recurrence'          => $this->recurrence ? $this->rows(TaskRecurrence::class, 'author', $user, ['x.id AS id', 'IDENTITY(x.board) AS board_id', 'IDENTITY(x.latestTask) AS latest_task_id', 'x.status AS status', 'x.createdAt AS created_at']) : [],
            'import_plans'        => $this->importPlans ? $this->plans($id) : [],
            'sync_people'         => $this->people instanceof TaskSyncPersonRepository ? $this->syncPeople($this->people, $user) : [],
        ];
    }

    /**
     * The rows whose user field is exactly the user, oldest first, dates as RFC 3339.
     *
     * @param class-string $class
     * @param list<string> $select
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $class, string $field, object $user, array $select, ?string $where = null, array $parameters = []): array
    {
        $batches = $this->users->idMode()
            ? array_map(static fn (array $ids): array => ['x.id IN (:match)', $ids], array_chunk(TaskBoardUsers::exactRowIds($this->entityManager, $class, $field, TaskBoardUsers::exactId($user)), ExactKey::CHUNK))
            : [['x.' . $field . ' = :match', $user]];
        $rows = [];
        foreach ($batches as [$match, $value]) {
            $builder = $this->entityManager->createQueryBuilder()->select($select)->from($class, 'x')->where($match)->setParameter('match', $value);
            if ($where !== null) {
                $builder->andWhere($where);
            }
            foreach ($parameters as $name => $parameter) {
                $builder->setParameter($name, $parameter);
            }
            /** @var list<array<string, mixed>> $found */
            $found = $builder->getQuery()->getArrayResult();
            $rows  = array_merge($rows, array_map($this->plain(...), $found));
        }
        usort($rows, static fn (array $a, array $b): int => [$a['created_at'] ?? $a['logged_at'] ?? $a['updated_at'] ?? '', $a['id']] <=> [$b['created_at'] ?? $b['logged_at'] ?? $b['updated_at'] ?? '', $b['id']]);

        return $rows;
    }

    /**
     * The member changes that name the person by id (`<role> user:<id>`), which the erasure's `anonymise` clears.
     *
     * @return list<array<string, mixed>>
     */
    private function memberHistory(string $userId): array
    {
        $contexts = array_map(static fn (TaskMemberRole $role): string => $role->value . ' user:' . $userId, TaskMemberRole::cases());
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('h.id AS id', 'IDENTITY(h.task) AS task_id', 'h.changeType AS change_type', 'h.context AS context', 'h.createdAt AS created_at')
            ->from(TaskChangeHistory::class, 'h')
            ->where('h.changeType IN (:types)')->andWhere('h.context IN (:contexts)')
            ->setParameter('types', [TaskChangeType::MemberAdded, TaskChangeType::MemberRemoved])
            ->setParameter('contexts', $contexts)
            ->orderBy('h.createdAt', 'ASC')->addOrderBy('h.id', 'ASC')
            ->getQuery()->getArrayResult();
        $kept = [];
        foreach ($rows as $row) {
            // Exact: a collation may fold the context's case or trailing spaces (REQ-SEC-006).
            $context = $row['context'] ?? null;
            if (is_string($context) && in_array($context, $contexts, true)) {
                unset($row['context']);
                $row['role'] = substr($context, 0, (int) strpos($context, ' '));
                $kept[]      = $this->plain($row);
            }
        }

        return $kept;
    }

    /**
     * The custom field values the person set: their `custom_field` history rows (field id, and the value they wrote).
     *
     * @return list<array<string, mixed>>
     */
    private function customFieldValues(object $user): array
    {
        $rows = [];
        foreach ($this->rows(TaskChangeHistory::class, 'user', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.context AS context', 'x.newValue AS value', 'x.createdAt AS created_at'], 'x.changeType = :type', ['type' => TaskChangeType::CustomField]) as $row) {
            $context = is_string($row['context'] ?? null) ? $row['context'] : '';
            unset($row['context']);
            // `field:<id> <name>`: the id only (the name is the board's, not the person's).
            $row['field_id'] = str_starts_with($context, 'field:') ? explode(' ', substr($context, 6), 2)[0] : null;
            $rows[]          = $row;
        }

        return $rows;
    }

    /**
     * Questions they asked or withdrew, answers they gave, and questions addressed to them (`user:<id>`).
     *
     * @return list<array<string, mixed>>
     */
    private function questionRows(object $user, string $userId): array
    {
        $asked     = array_map(static fn (array $row): array => ['role' => 'asked'] + $row, $this->rows(TaskQuestion::class, 'askedBy', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.state AS state', 'x.askedAt AS created_at']));
        $withdrawn = array_map(static fn (array $row): array => ['role' => 'withdrew'] + $row, $this->rows(TaskQuestion::class, 'withdrawnBy', $user, ['x.id AS id', 'IDENTITY(x.task) AS task_id', 'x.state AS state', 'x.withdrawnAt AS created_at']));
        $answered  = array_map(static fn (array $row): array => ['role' => 'answered'] + $row, $this->rows(TaskQuestionAnswer::class, 'answeredBy', $user, ['x.id AS answer_id', 'IDENTITY(x.question) AS id', 'x.via AS via', 'x.answeredAt AS created_at']));
        $subject   = DefaultTaskQuestionRecipientResolver::PREFIX . $userId;
        /** @var list<array<string, mixed>> $recipient */
        $recipient = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(r.question) AS id', 'r.subjectId AS subject')
            ->from(TaskQuestionRecipient::class, 'r')->where('r.subjectId = :subject')->setParameter('subject', $subject)
            ->getQuery()->getArrayResult();
        $addressed = [];
        foreach ($recipient as $row) {
            if (($row['subject'] ?? null) === $subject) {
                $addressed[] = ['role' => 'recipient', 'id' => $row['id']];
            }
        }

        return [...$asked, ...$withdrawn, ...$answered, ...$addressed];
    }

    /**
     * Import plans they created or started: ids, tenant, provider, status and dates (no connection reference, label or
     * run token).
     *
     * @return list<array<string, mixed>>
     */
    private function plans(string $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id AS id', 'p.tenantKey AS tenant_key', 'p.provider AS provider', 'p.status AS status', 'p.createdById AS created_by', 'p.startedById AS started_by', 'p.createdAt AS created_at', 'p.startedAt AS started_at', 'p.finishedAt AS finished_at')
            ->from(TaskSyncImportPlan::class, 'p')
            ->where('p.createdById = :id OR p.startedById = :id')->setParameter('id', $userId)
            ->orderBy('p.createdAt', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getArrayResult();
        $kept = [];
        foreach ($rows as $row) {
            $roles = [];
            if (($row['created_by'] ?? null) === $userId) {
                $roles[] = 'created';
            }
            if (($row['started_by'] ?? null) === $userId) {
                $roles[] = 'started';
            }
            if ($roles !== []) {
                unset($row['created_by'], $row['started_by']);
                $kept[] = ['roles' => $roles] + $this->plain($row);
            }
        }

        return $kept;
    }

    /**
     * The remote people mapped to the user, each with what references them (by id); suggested ones by id only.
     *
     * @return list<array<string, mixed>>
     */
    private function syncPeople(TaskSyncPersonRepository $people, object $user): array
    {
        $rows = [];
        foreach ($people->linkedToUser($user) as $person) {
            // Audit ZCS-L4: a suggestion (an e-mail match of an account not verified) is the other person's identity
            // until someone confirms it: its ids only, never its name, e-mail or remote work.
            $rows[] = $person->getMapping() === TaskSyncPersonMapping::Suggested
                ? ['person' => ['id' => $person->getId(), 'directoryId' => $person->getDirectory()->getId(), 'provider' => $person->getDirectory()->getProvider(), 'mapping' => TaskSyncPersonMapping::Suggested->value]]
                : $people->exportPerson($person);
        }

        return $rows;
    }

    /**
     * Dates as RFC 3339, enums as their value, user references as their id.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function plain(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = match (true) {
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof BackedEnum        => $value->value,
                $value instanceof TaskBoardUserRef  => $value->id,
                default                             => $value,
            };
        }

        return $row;
    }
}
