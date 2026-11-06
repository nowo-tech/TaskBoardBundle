<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\View;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\People\PersonToken;
use Nowo\TaskBoardBundle\Sync\SyncTaskDetailsWriter;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function explode;
use function is_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Since 1.25: view models of the remote people of a synced task (Twig `task_board_sync_creator(task)`,
 * `task_board_sync_comment_author(comment)`, `task_board_sync_unmatched(task, role)`). Reads the sync rows only;
 * memoised per request (cleared by `kernel.reset`, so a worker never serves an earlier request's rows). Names are
 * shown, never logged.
 */
final class SyncPeopleViewProvider implements ResetInterface
{
    /** @var array<string, false|TaskSyncItem> */
    private array $items = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskBoardResourceAccessChecker $accessChecker,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?string $userClass = null,
    ) {
    }

    /** The remote creator of an imported task; null for a task the sync did not create or whose creator is unknown. */
    public function creatorOf(Task $task): ?SyncPersonView
    {
        $person = $this->item($task)?->getCreatorPerson();

        return $person instanceof TaskSyncPerson ? $this->view($person, $task) : null;
    }

    /** The remote author of an imported comment; null for a local comment or a 1.23 one with the prefix line. */
    public function commentAuthorOf(TaskComment $comment): ?SyncPersonView
    {
        $sync   = $this->entityManager->getRepository(TaskSyncComment::class)->findOneBy(['comment' => $comment]);
        $person = $sync instanceof TaskSyncComment ? $sync->getAuthorPerson() : null;

        return $person instanceof TaskSyncPerson ? $this->view($person, $comment->getTask()) : null;
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): what the provider has besides the synced fields (the item's `remote_meta`): customId,
     * points, doneAt / closedAt, startHasTime / dueHasTime, path, estimates (`{name, minutes}`), recurrence and
     * statusHistory (`{status, since, minutes, current}`), plus `provider`. Empty for a task the sync did not write.
     *
     * @return array<string, mixed>
     */
    public function detailsOf(Task $task): array
    {
        $item = $this->item($task);
        $meta = $item?->getRemoteMeta() ?? [];

        unset($meta[SyncTaskDetailsWriter::META_DETAIL_READ], $meta[SyncTaskDetailsWriter::META_REPLIES_READ]);
        if (!$item instanceof TaskSyncItem || ($meta === [] && $item->getCustomId() === null)) {
            return [];
        }
        if ($item->getCustomId() !== null) {
            $meta['customId'] = $item->getCustomId();
        }
        if (is_array($meta['estimates'] ?? null)) {
            $meta['estimates'] = array_map(fn (array $estimate): array => ['name' => $this->personLabel((string) $estimate['person']), 'minutes' => $estimate['minutes']], $meta['estimates']);
        }

        return $meta + ['provider' => $item->getConnection()->getProvider()];
    }

    /**
     * Since 1.27.0: the label of a {@see PersonToken}: the person's name (anonymised when forgotten), `#<id>` without a
     * directory, `—` for a person no longer there.
     */
    public function personLabel(string $token): string
    {
        if (str_starts_with($token, PersonToken::REF_PREFIX)) {
            return '#' . substr($token, strlen(PersonToken::REF_PREFIX));
        }
        $label = $this->tokenLabel($token);

        return $label === $token ? '—' : $label;
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): the thread and action item of an imported comment — `reply` (it answers another
     * comment), `replyTo` (that comment's remote author, when known), `resolved` (null = not an action item),
     * `assignee` (who it is assigned to) and `remoteEditedAt`. Null for a local comment.
     *
     * @return array{reply: bool, replyTo: SyncPersonView|null, resolved: bool|null, assignee: SyncPersonView|null, remoteEditedAt: DateTimeImmutable|null}|null
     */
    public function commentMetaOf(TaskComment $comment): ?array
    {
        $sync = $this->entityManager->getRepository(TaskSyncComment::class)->findOneBy(['comment' => $comment]);
        if (!$sync instanceof TaskSyncComment) {
            return null;
        }
        $task     = $comment->getTask();
        $parentId = $sync->getParentRemoteId();
        $parent   = $parentId === null ? null : $this->entityManager->getRepository(TaskSyncComment::class)->findOneBy(['item' => $sync->getItem(), 'remoteId' => $parentId]);
        $replyTo  = $parent?->getAuthorPerson();
        $assignee = $sync->getAssigneePerson();

        return [
            'reply'          => $parentId !== null,
            'replyTo'        => $replyTo instanceof TaskSyncPerson ? $this->view($replyTo, $task) : null,
            'resolved'       => $sync->isResolved(),
            'assignee'       => $assignee instanceof TaskSyncPerson ? $this->view($assignee, $task) : null,
            'remoteEditedAt' => $sync->getRemoteUpdatedAt(),
        ];
    }

    /**
     * People assigned (or watching) in the provider who are not written as task members ("Also assigned in ClickUp").
     *
     * @return list<SyncPersonView>
     */
    public function unmatchedOf(Task $task, string $role = 'assignee'): array
    {
        $item = $this->item($task);
        if (!$item instanceof TaskSyncItem || TaskMemberRole::tryFrom($role) === null) {
            return [];
        }
        $views = [];
        foreach ($item->unmatchedPeople($role) as $personId) {
            $person = $this->entityManager->find(TaskSyncPerson::class, $personId);
            if ($person instanceof TaskSyncPerson) {
                $views[] = $this->view($person, $task);
            }
        }

        return $views;
    }

    /**
     * A history token as a label: `person:<id>` → the person's name (anonymised when forgotten), `user:<id>` → the
     * user's label; anything else unchanged.
     */
    public function tokenLabel(string $token): string
    {
        [$kind, $id] = explode(':', $token, 2) + [1 => ''];
        if ($kind === 'person') {
            $person = $this->entityManager->find(TaskSyncPerson::class, $id);

            return $person instanceof TaskSyncPerson ? $this->name($person) : $token;
        }
        if ($kind === 'user' && $this->userClass !== null && $id !== '') {
            /** @var class-string $class */
            $class = $this->userClass;
            $user  = $this->entityManager->find($class, $id);

            return $user !== null ? UserLabel::of($user) : $token;
        }

        return $token;
    }

    public function reset(): void
    {
        $this->items = [];
    }

    private function item(Task $task): ?TaskSyncItem
    {
        $key  = $task->getId();
        $item = $this->items[$key] ??= $this->entityManager->getRepository(TaskSyncItem::class)->findOneBy(['task' => $task]) ?? false;

        return $item instanceof TaskSyncItem ? $item : null;
    }

    private function view(TaskSyncPerson $person, Task $task): SyncPersonView
    {
        $provider = $person->getDirectory()->getProvider();
        $user     = $person->getMappedUser();
        $access   = $user === null ? null : $this->accessChecker->canViewBoard($user, $task->getBoard());

        return new SyncPersonView(
            personId: $person->getId(),
            name: $this->name($person),
            provider: $provider,
            external: $access !== true,
            anonymised: $person->isAnonymised(),
            boardAccess: $access,
        );
    }

    /** The display name; an anonymised person is "Former <provider> user #n" (translated). */
    private function name(TaskSyncPerson $person): string
    {
        if (!$person->isAnonymised()) {
            return $person->getName();
        }
        $number   = (string) $person->anonymousNumber();
        $provider = $person->getDirectory()->getProvider();
        if (!$this->translator instanceof TranslatorInterface) {
            return 'Former ' . $provider . ' user #' . $number;
        }

        return $this->translator->trans('task_board.sync.people.former_user', ['%provider%' => $this->translator->trans('task_board.sync.provider.' . $provider, [], 'NowoTaskBoardBundle'), '%number%' => $number], 'NowoTaskBoardBundle');
    }
}
