<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\People\PeopleSession;
use Nowo\TaskBoardBundle\Sync\People\SyncPeopleWriter;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteChecklistItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use SortDirection;

use function addcslashes;
use function array_key_last;
use function in_array;
use function sprintf;

/**
 * Since 1.25: ClickUp checklists as subtasks — one subtask per item, under the task, in checklist order, tagged
 * "checklist:<name>"; a resolved item is completed and goes to the done column (the column of the last column rule,
 * as for tasks without a status type); the item's assignee goes through the people directory. Upsert by item id
 * (external id "<task id>#<item id>"); an item gone from a checklist that was read is archived (never deleted).
 *
 * @internal used by the sync writer
 */
final readonly class SyncChecklistWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SyncStore $store,
        private ?SyncPeopleWriter $peopleWriter = null,
        private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer(),
        private TaskChangeRecorder $changeRecorder = new TaskChangeRecorder(),
    ) {
    }

    public function sync(SyncRunContext $ctx, ?PeopleSession $session, TaskSyncItem $parentItem, Task $parent, RemoteTask $remote): void
    {
        $columns = $ctx->board->getColumns()->toArray();
        // Done items: the column the status map gives the first closed / done status, else the last one (1.23 rule).
        $done   = StatusColumns::doneColumn($ctx->connection->getStatusColumnMap(), $ctx->board) ?? ($columns === [] ? null : $columns[array_key_last($columns)]);
        $open   = $parent->getColumn();
        $wanted = [];
        foreach ($remote->checklists as $checklist) {
            $tag = Text::cut('checklist:' . $this->sanitizer->line($checklist->name, 120), 64);
            foreach ($checklist->items as $entry) {
                $wanted[Text::cut(sprintf('%s#%s', $parentItem->getExternalId(), $entry->id), 128)] = [$entry, $tag];
            }
        }
        $existing = $this->existing($ctx, $parentItem->getExternalId());
        $position = 0;
        foreach ($wanted as $externalId => [$entry, $tag]) {
            $item = $existing[$externalId] ?? null;
            $task = $item?->getTask();
            if ($item instanceof TaskSyncItem && !$task instanceof Task) {
                continue; // deleted here: never recreated
            }
            if (!$task instanceof Task) {
                $task = new Task(board: $ctx->board, title: $this->title($entry), creator: $parent->getCreator(), column: $entry->resolved ? $done : $open, parent: $parent, position: $position, tags: [$tag]);
                $this->entityManager->persist($task);
                $this->changeRecorder->recordCreated($task, $ctx->actor);
                $item = (new TaskSyncItem($ctx->connection, $ctx->board->getId(), $task, $externalId, $remote->remoteUpdatedAt))->setItemKind(TaskSyncItem::KIND_CHECKLIST_ITEM);
                $this->entityManager->persist($item);
                $ctx->count('checklist.created');
            } elseif ($this->apply($ctx, $task, $entry, $tag, $done, $open)) {
                $ctx->count('checklist.updated');
            }
            if ($entry->resolved && !$task->isCompleted()) {
                $task->markCompleted();
            } elseif (!$entry->resolved && $task->isCompleted()) {
                $task->markIncomplete();
            }
            if ($task->isArchived() && $task->getArchiveReason() === Task::ARCHIVE_REASON_REMOTE) {
                $task->restore();
            }
            $item->setRemoteUpdatedAt($remote->remoteUpdatedAt)->setLastSeenRunId($ctx->runId);
            if ($session instanceof PeopleSession && $this->peopleWriter instanceof SyncPeopleWriter) {
                $this->peopleWriter->syncMembers($session, $item, $task, TaskMemberRole::Assignee, $entry->assignee === null ? [] : [$entry->assignee], TaskSyncPerson::KIND_CHECKLIST, $ctx->actor);
            }
            ++$position;
        }
        foreach ($existing as $externalId => $item) {
            $task = $item->getTask();
            if (!isset($wanted[$externalId]) && $task instanceof Task && !$task->isArchived()) {
                $task->archive(Task::ARCHIVE_REASON_REMOTE);
                $this->changeRecorder->record($task, $ctx->actor, TaskChangeType::Archived, null, $ctx->provider->id());
                $ctx->count('checklist.archived');
            }
        }
        $this->entityManager->flush();
    }

    private function apply(SyncRunContext $ctx, Task $task, RemoteChecklistItem $entry, string $tag, ?BoardColumn $done, ?BoardColumn $open): bool
    {
        $changed = false;
        $title   = $this->title($entry);
        if ($task->getTitle() !== $title) {
            $this->changeRecorder->recordIfChanged($task, $ctx->actor, TaskChangeType::Title, $task->getTitle(), $title);
            $task->setTitle($title);
            $changed = true;
        }
        $column = $entry->resolved ? $done : $open;
        if ($column instanceof BoardColumn && $task->getColumn()?->getId() !== $column->getId() && ($entry->resolved || $task->isCompleted())) {
            $this->changeRecorder->recordIfChanged($task, $ctx->actor, TaskChangeType::Column, $task->getColumn(), $column);
            $task->setColumn($column);
            $changed = true;
        }
        if (!in_array($tag, $task->getTags(), true)) {
            $task->setTags([...$task->getTags(), $tag]);
            $changed = true;
        }

        return $changed;
    }

    private function title(RemoteChecklistItem $entry): string
    {
        $title = $this->sanitizer->line($entry->name, Task::TITLE_MAX);

        return $title === '' ? '—' : $title;
    }

    /**
     * The checklist items already synced under a task, by external id.
     *
     * @return array<string, TaskSyncItem>
     */
    private function existing(SyncRunContext $ctx, string $parentExternalId): array
    {
        /** @var list<TaskSyncItem> $items */
        $items = $this->store->entityManager()->createQueryBuilder()->select('i', 't')->from(TaskSyncItem::class, 'i')->leftJoin('i.task', 't')
            ->where('i.boardId = :board')->andWhere('i.itemKind = :kind')->andWhere('i.externalId LIKE :prefix')
            ->setParameter('board', $ctx->board->getId())->setParameter('kind', TaskSyncItem::KIND_CHECKLIST_ITEM)
            ->setParameter('prefix', addcslashes($parentExternalId, '%_\\') . '#%')
            ->orderBy('i.externalId', SortDirection::Ascending)->getQuery()->getResult();
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->getExternalId()] = $item;
        }

        return $byId;
    }
}
