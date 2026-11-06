<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Doctrine\ORM\EntityManagerInterface;
use Normalizer;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Event\PeopleMappingChangedEvent;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function class_exists;
use function count;
use function filter_var;
use function is_string;
use function mb_strtolower;
use function method_exists;
use function microtime;
use function preg_replace;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Since 1.27.0 (TB-SYNC-2): the People page / step of a connection — the rows (remote person, current mapping and the
 * select's pre-filled value) and the save, which maps people through {@see TaskSyncPeopleReassigner} so what was
 * already imported (creator, assignees, watchers, comment authors; the host moves time on `SYNC_PERSON_REASSIGNED`)
 * follows the new mapping.
 *
 * Deny by default: a pre-fill (e-mail, then normalised name) is only a starting value; nobody is mapped until a
 * manager saves the page, and only to a candidate of every board of the directory (the host's
 * `SyncPersonResolverInterface`). The automatic mapping of a sync stays the 1.25 verified e-mail match.
 */
final readonly class PeopleMappingService
{
    /** The select value "Not mapped". */
    public const NOT_MAPPED = '';

    /** The select value "Invite by e-mail" (only with a host inviter). */
    public const INVITE = '__invite';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPersonRepository $people,
        private TaskSyncPeopleDirectoryService $directories,
        private TaskSyncPeopleReassigner $reassigner,
        private SyncPersonResolverInterface $resolver,
        private ?PeopleInviterInterface $inviter = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private int $candidateLimit = 200,
        private float $reapplySeconds = 10.0,
    ) {
    }

    /**
     * The users the board's people may be mapped to (the host's candidates), by id.
     *
     * @return array<string, object>
     */
    public function candidates(TaskBoard $board): array
    {
        $candidates = [];
        foreach ($this->resolver->candidates($board, '', $this->candidateLimit) as $user) {
            $id = SyncUser::id($user);
            if ($id !== null) {
                $candidates[$id] = $user;
            }
        }

        return $candidates;
    }

    /** Whether the *Invite by e-mail* option is offered to the actor on this board. */
    public function canInvite(TaskBoard $board, object $actor): bool
    {
        return $this->inviter instanceof PeopleInviterInterface && $this->inviter->canInvite($board, $actor);
    }

    /**
     * One page of rows (people by name; anonymised people are left out: they can no longer be mapped).
     *
     * @param array<string, object> $candidates {@see self::candidates()}
     *
     * @return list<PeopleMappingRow>
     */
    public function rows(TaskSyncDirectory $directory, array $candidates, PersonFilter $filter = new PersonFilter(), int $limit = 50, int $offset = 0): array
    {
        $rows = [];
        foreach ($this->people->forDirectory($directory, $filter, $limit, $offset) as $person) {
            if ($person->isAnonymised()) {
                continue;
            }
            $rows[] = $this->row($person, $candidates);
        }

        return $rows;
    }

    /**
     * Saves the submitted selects (person id => user id, {@see self::NOT_MAPPED} or {@see self::INVITE}). Rows whose
     * value did not change are skipped; a changed one is re-associated (batched until `sync.people.reapply_seconds`
     * runs out; the rest is pending). Unfinished re-associations of the directory are resumed first. Marks the People
     * step as reviewed and dispatches {@see PeopleMappingChangedEvent} when something changed.
     *
     * @param array<string, string> $choices
     *
     * @throws PeopleMappingDeniedException
     */
    public function save(TaskSyncDirectory $directory, TaskBoard $board, array $choices, object $actor): PeopleMappingResult
    {
        $this->directories->assertCanManage($directory, $actor, $board);
        $deadline   = microtime(true) + $this->reapplySeconds;
        $candidates = $this->candidates($board);
        $changes    = [];
        $errors     = [];
        $invited    = 0;
        $pending    = 0;
        $moved      = $this->resume($directory, $actor, $deadline);
        foreach ($choices as $personId => $value) {
            $person = $this->people->findPerson((string) $personId);
            if (!$person instanceof TaskSyncPerson || $person->getDirectory()->getId() !== $directory->getId() || $person->isAnonymised()) {
                $errors[] = 'unknown_person';
                continue;
            }
            $current = $person->getMapping()->isMapped() ? (SyncUser::id($person->getMappedUser()) ?? '') : self::NOT_MAPPED;
            if ($value === self::INVITE) {
                $user = $this->invite($board, $person, $actor);
                if ($user === false) {
                    $errors[] = 'invite_unavailable';
                    continue;
                }
                if ($user === null) {
                    ++$invited;
                    continue;
                }
                $value = SyncUser::id($user) ?? '';
            }
            if ($value === $current || ($value === self::NOT_MAPPED && !$person->getMapping()->isMapped())) {
                continue;
            }
            $target = $value === self::NOT_MAPPED ? null : ($candidates[$value] ?? $this->resolver->candidateById($board, $value));
            if ($value !== self::NOT_MAPPED && $target === null) {
                $errors[] = 'not_candidate';
                continue;
            }
            $previousMapping = $person->getMapping()->value;
            try {
                $report = $this->reassignUntil($person, $target, $actor, $deadline);
            } catch (InvalidMappingException $exception) {
                $errors[] = $exception->reason;
                continue;
            } catch (ReassignInProgressException) {
                $errors[] = 'reassign_in_progress';
                continue;
            }
            $moved = $moved->plus($report);
            if (!$report->isDone()) {
                ++$pending;
            }
            $changes[] = [
                'personId'        => $person->getId(),
                'previousMapping' => $previousMapping,
                'mapping'         => $person->getMapping()->value,
                'previousUserId'  => $current === self::NOT_MAPPED ? null : $current,
                'userId'          => SyncUser::id($target),
            ];
        }
        $this->directories->markReviewed($directory, $actor, $board);
        if ($changes !== [] || $invited > 0) {
            $this->eventDispatcher?->dispatch(
                new PeopleMappingChangedEvent($board->getId(), $directory->getId(), SyncUser::id($actor), $changes, $invited, $pending),
                TaskBoardEvents::SYNC_PEOPLE_MAPPING_CHANGED,
            );
        }

        return new PeopleMappingResult($changes, $invited, count($this->people->pendingReassigns($directory)), $errors, $moved->withCursor(null));
    }

    /**
     * Continues the unfinished re-associations of the directory until the deadline (each sync calls it after its run,
     * so a mapping change reaches every already imported task). Never throws.
     */
    public function resume(TaskSyncDirectory $directory, object $actor, ?float $deadline = null): ReassignReport
    {
        $deadline ??= microtime(true) + $this->reapplySeconds;
        $total = new ReassignReport();
        foreach ($this->people->pendingReassigns($directory) as $person) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $target = $person->isReassignExternal() ? null : $person->getReassignToUser();
                if ($target === null && !$person->isReassignExternal()) {
                    // The target user is gone: nothing left to move to.
                    $this->reassigner->cancelReassign($person, $actor);
                    continue;
                }
                $total = $total->plus($this->reassignUntil($person, $target, $actor, $deadline));
            } catch (Throwable) {
                // Refused (access, candidacy, a busy board): it stays pending for a later save or sync.
            }
        }

        return $total;
    }

    /** Lower case, accents and punctuation spacing removed: "  José  Pérez " and "jose perez" are the same name. */
    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        if (class_exists(Normalizer::class)) {
            $name = (string) Normalizer::normalize($name, Normalizer::FORM_D);
            $name = (string) preg_replace('/\p{Mn}+/u', '', $name);
        }
        $name = (string) preg_replace('/[\s._-]+/u', ' ', $name);

        return trim(mb_strtolower($name));
    }

    /**
     * @param array<string, object> $candidates
     */
    private function row(TaskSyncPerson $person, array $candidates): PeopleMappingRow
    {
        $pending = $person->getReassignId() !== null;
        $mapping = $person->getMapping();
        if ($mapping->isMapped()) {
            $id = SyncUser::id($person->getMappedUser()) ?? '';

            return new PeopleMappingRow($person, $id, $id, PeopleMappingRow::PREFILL_MAPPED, $pending);
        }
        if ($mapping === TaskSyncPersonMapping::External) {
            return new PeopleMappingRow($person, '', '', PeopleMappingRow::PREFILL_EXTERNAL, $pending);
        }
        $suggested = SyncUser::id($person->getSuggestedUser());
        if ($mapping === TaskSyncPersonMapping::Suggested && $suggested !== null && isset($candidates[$suggested])) {
            return new PeopleMappingRow($person, '', $suggested, PeopleMappingRow::PREFILL_EMAIL, $pending);
        }
        $byEmail = $this->unique($candidates, static fn (object $user): bool => $person->getEmailNormalized() !== null && SyncUser::normalizeEmail(self::email($user)) === $person->getEmailNormalized());
        if ($byEmail !== null) {
            return new PeopleMappingRow($person, '', $byEmail, PeopleMappingRow::PREFILL_EMAIL, $pending);
        }
        $name   = self::normalizeName($person->getName());
        $byName = $name === '' ? null : $this->unique($candidates, static fn (object $user): bool => self::normalizeName(UserLabel::of($user)) === $name);
        if ($byName !== null) {
            return new PeopleMappingRow($person, '', $byName, PeopleMappingRow::PREFILL_NAME, $pending);
        }

        return new PeopleMappingRow($person, '', '', PeopleMappingRow::PREFILL_NONE, $pending);
    }

    /**
     * The id of the only candidate that matches, else null (no match, or more than one).
     *
     * @param array<string, object> $candidates
     * @param callable(object): bool $matches
     */
    private function unique(array $candidates, callable $matches): ?string
    {
        $found = [];
        foreach ($candidates as $id => $user) {
            if ($matches($user)) {
                $found[] = (string) $id;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    private static function email(object $user): ?string
    {
        $email = method_exists($user, 'getEmail') ? $user->getEmail() : null;

        return is_string($email) ? $email : null;
    }

    /**
     * The host's invitation: the user to map, null when pending, false when it cannot be sent.
     */
    private function invite(TaskBoard $board, TaskSyncPerson $person, object $actor): object|false|null
    {
        $email = $person->getEmail();
        if (!$this->canInvite($board, $actor) || $email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return $this->inviter?->invite($board, $email, $person->getName(), $actor);
    }

    /**
     * Re-associates in batches until done, the deadline, or a batch that moved nothing (a busy board).
     */
    private function reassignUntil(TaskSyncPerson $person, ?object $target, object $actor, float $deadline): ReassignReport
    {
        $report = $this->reassigner->reassign($person, $target, $actor);
        $total  = $report;
        while (!$report->isDone() && $report->busyBoards === 0 && microtime(true) < $deadline) {
            $report = $this->reassigner->reassign($person, $target, $actor, null, $report->cursor);
            $total  = $total->plus($report);
        }
        $this->entityManager->flush();

        return $total;
    }
}
