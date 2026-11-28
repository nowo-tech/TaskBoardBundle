<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\AuditKitBundle\Timeline\TimelineCriteria;
use Nowo\AuditKitBundle\Timeline\TimelineCursor;
use Nowo\AuditKitBundle\Timeline\TimelineEvent;
use Nowo\AuditKitBundle\Timeline\TimelineSubject;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\TaskBoardBundle\Bridge\AuditKit\TaskTimelineSource;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_filter;
use function array_map;
use function array_reverse;
use function array_values;
use function count;
use function date_default_timezone_get;
use function intdiv;
use function sort;
use function str_repeat;

/**
 * Since 3.7.0 (Beacon spec 235 T003): task changes and comments of one task as an AuditKit timeline source, read from
 * the database — visibility of the task page, never another task or tenant, kinds and correlation filters, and a stable
 * keyset at whole seconds in the server's timezone (no entry repeated or skipped, whatever the cursor's source).
 */
final class TaskTimelineSourceTest extends FunctionalTestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    private Task $task;

    private DateTimeImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = new DateTimeImmutable('2026-11-20 10:00:00');
        $this->task = $this->task($this->board(), 'Timeline');
        // Three rows at 10:00:00 (ties), one at 10:00:05, one comment at 10:00:00 and one at 10:00:09.
        $this->history(TaskChangeType::Title, 'A', 'B', 0, self::TRACE, new WriteProvenance(WriteChannel::Api, 'CI token'));
        $this->history(TaskChangeType::Priority, 'normal', 'high', 0);
        $this->history(TaskChangeType::Description, 'old', str_repeat('x', 20), 0, self::TRACE);
        $this->history(TaskChangeType::Title, 'B', str_repeat('y', 501), 5);
        $this->comment(0);
        $this->comment(9, deleted: true);
        // Another task of the same board: never in this task's timeline.
        $other = $this->task($this->task->getBoard(), 'Other');
        $this->em->persist(new TaskChangeHistory($other, $this->owner, TaskChangeType::Title, 'X', 'Y', correlationId: CorrelationId::fromString(self::TRACE)));
        $this->em->flush();
        $this->em->clear();
        $this->signIn($this->owner);
    }

    public function testItIsRegisteredAndTagged(): void
    {
        self::assertInstanceOf(TaskTimelineSource::class, self::getContainer()->get('app.timeline_source'));
    }

    public function testTheTasksChangesAndCommentsNewestFirst(): void
    {
        $events = $this->source()->page($this->subject(), new TimelineCriteria(), null, 50);
        self::assertCount(6, $events);
        self::assertSame(['comment.added', 'task.title', 'task.title', 'task.priority', 'task.description', 'comment.added'], $this->sortedKinds($events));
        self::assertSame('comment.added', $events[0]->kind);
        self::assertTrue($events[0]->payload['deleted']);
        self::assertSame($this->at(9), $events[0]->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame(['title' => ['changed' => true, 'from_len' => 1, 'to_len' => 501]], $events[1]->changes);

        $title = $this->only($events, static fn (TimelineEvent $event): bool => $event->kind === 'task.title' && $event->correlationId === self::TRACE);
        self::assertSame(TaskTimelineSource::NAME, $title->source);
        self::assertSame(['title' => ['from' => 'A', 'to' => 'B']], $title->changes);
        self::assertSame((string) $this->owner->getId(), $title->actorId);
        self::assertSame('api', $title->channel);
        self::assertSame('CI token', $title->tokenLabel);
        self::assertSame($this->task->getId(), $title->payload['task_id']);
        self::assertSame('owner@example.com', $title->payload['actor_name']);
        self::assertSame('000000', $title->occurredAt->format('u'));

        $description = $this->only($events, static fn (TimelineEvent $event): bool => $event->kind === 'task.description');
        self::assertSame(['description' => ['changed' => true, 'from_len' => 3, 'to_len' => 20]], $description->changes, 'a description is never copied');

        $comment = $this->only($events, static fn (TimelineEvent $event): bool => $event->kind === 'comment.added' && $event->payload['deleted'] === false);
        self::assertSame((string) $this->owner->getId(), $comment->actorId);
        self::assertNull($comment->correlationId);
        self::assertArrayNotHasKey('body', $comment->payload);
        self::assertFalse($comment->payload['edited']);
    }

    public function testKindsDatesAndTheCorrelationFilter(): void
    {
        $source = $this->source();
        self::assertSame(['comment.added', 'comment.added'], $this->kinds($source->page($this->subject(), new TimelineCriteria(kinds: ['comment.added']), null, 50)));
        self::assertSame(['task.priority'], $this->kinds($source->page($this->subject(), new TimelineCriteria(kinds: ['task.priority', 'unknown']), null, 50)));
        self::assertSame([], $source->page($this->subject(), new TimelineCriteria(kinds: []), null, 50));

        $correlated = $source->page($this->subject(), new TimelineCriteria(correlationId: self::TRACE), null, 50);
        self::assertSame(['task.title', 'task.description'], $this->sortedKinds($correlated, false), 'this task only, comments have no id');
        self::assertSame([], $source->page($this->subject(), new TimelineCriteria(correlationId: '4BF92F3577B34DA6A3CE929D0E0E4736'), null, 50), 'byte for byte');

        $utc    = new DateTimeZone('UTC');
        $window = new TimelineCriteria(since: $this->base->modify('+1 second')->setTimezone($utc), until: $this->base->modify('+5 seconds')->setTimezone($utc));
        self::assertSame(['task.title'], $this->kinds($source->page($this->subject(), $window, null, 50)));
    }

    public function testPagesAreStableWithTiesAndForeignCursors(): void
    {
        $source = $this->source();
        $all    = $source->page($this->subject(), new TimelineCriteria(), null, 50);
        $ids    = array_map(static fn (TimelineEvent $event): string => $event->id, $all);

        // Two at a time through this source's own cursor (ties at 10:00:00 across both tables).
        $seen  = [];
        $after = null;
        do {
            $page = $source->page($this->subject(), new TimelineCriteria(), $after, 2);
            foreach ($page as $event) {
                $seen[] = $event->id;
            }
            $after = $page === [] ? null : $page[count($page) - 1]->cursor();
        } while ($page !== []);
        self::assertSame($ids, $seen);

        // A cursor of another source at 10:00:00 (UTC-shifted, as a merger may hand it over).
        $at = $this->base->setTimezone(new DateTimeZone('UTC'));
        self::assertCount(0, $source->page($this->subject(), new TimelineCriteria(), new TimelineCursor($at, 'zz_later', 'x'), 50), 'our name sorts before the cursor\'s: strictly older');
        self::assertCount(4, $source->page($this->subject(), new TimelineCriteria(), new TimelineCursor($at, 'a_earlier', 'x'), 50), 'our name sorts after: its second included');
        self::assertCount(4, $source->page($this->subject(), new TimelineCriteria(), new TimelineCursor($at->modify('+250 milliseconds'), 'zz_later', 'x'), 50), 'between two seconds: its second is older');
        // This source's own name with an id of the other table, before or after every id of this one.
        self::assertCount(4, $source->page($this->subject(), new TimelineCriteria(), new TimelineCursor($at, TaskTimelineSource::NAME, 'history:zzz'), 50), 'after the last history id: every row of that second');
        self::assertCount(1, $source->page($this->subject(), new TimelineCriteria(), new TimelineCursor($at, TaskTimelineSource::NAME, 'comment:zzz'), 50), 'comment ids sort before history ids');
        self::assertCount(0, $source->page($this->subject(), new TimelineCriteria(), new TimelineCursor($at, TaskTimelineSource::NAME, 'aaa'), 50));
    }

    public function testNothingForOtherSubjectsOrReadersWhoMayNotSeeTheTask(): void
    {
        $source = $this->source();
        self::assertSame([], $source->page(new TimelineSubject('correlation', self::TRACE), new TimelineCriteria(), null, 50), 'never across tasks or tenants');
        self::assertSame([], $source->page(new TimelineSubject('scope', 'tenant-a'), new TimelineCriteria(), null, 50));
        self::assertSame([], $source->page($this->subject(), new TimelineCriteria(), null, 0));
        self::assertSame([], $source->page(new TimelineSubject(TaskTimelineSource::SUBJECT_TASK, 'missing'), new TimelineCriteria(), null, 50));
        self::assertSame([], $source->page(new TimelineSubject(TaskTimelineSource::SUBJECT_TASK, str_repeat('a', 65)), new TimelineCriteria(), null, 50));

        $this->signIn($this->user('stranger@example.com'));
        self::assertSame([], $source->page($this->subject(), new TimelineCriteria(), null, 50), 'not a member of the board');

        $this->signIn($this->user('disabled@example.com', []));
        self::assertSame([], $source->page($this->subject(), new TimelineCriteria(), null, 50), 'no access to the task board at all');

        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(null);
        self::assertSame([], $source->page($this->subject(), new TimelineCriteria(), null, 50), 'nobody signed in');
    }

    public function testAMergerPageOf500PlusOneAndLookAlikeCorrelationIdsNeverEndThePageEarly(): void
    {
        $task = $this->em->find(Task::class, $this->task->getId());
        self::assertInstanceOf(Task::class, $task);
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        for ($i = 0; $i < 600; ++$i) {
            $row = new TaskChangeHistory($task, $owner, TaskChangeType::Tags, (string) $i, (string) ($i + 1));
            (new ReflectionProperty(TaskChangeHistory::class, 'createdAt'))->setValue($row, $this->base->modify('-' . (1 + intdiv($i, 7)) . ' seconds'));
            $this->em->persist($row);
        }
        $this->em->flush();
        $this->em->clear();
        $source = $this->source();
        // AuditKit's merger asks for its page size plus one to know there is a next page.
        self::assertCount(501, $source->page($this->subject(), new TimelineCriteria(), null, 501));
        self::assertCount(606, $source->page($this->subject(), new TimelineCriteria(), null, 5000), 'at most 1000');
        $map = $this->em->getUnitOfWork()->getIdentityMap();
        self::assertArrayNotHasKey(TaskChangeHistory::class, $map, 'rows are read as arrays: no history row enters the identity map');
        self::assertArrayNotHasKey(TaskComment::class, $map);

        // Look-alike ids (another case) are newer than the exact ones: they never take a slot of the page.
        $lookAlike = '4BF92F3577B34DA6A3CE929D0E0E4736';
        foreach ([20, 21, 22] as $second) {
            $this->history(TaskChangeType::Color, '#000000', '#ffffff', $second, $lookAlike);
        }
        $page = $source->page($this->subject(), new TimelineCriteria(correlationId: self::TRACE), null, 2);
        self::assertSame(['task.title', 'task.description'], $this->sortedKinds($page, false));
        self::assertSame([self::TRACE, self::TRACE], array_map(static fn (TimelineEvent $event): ?string => $event->correlationId, $page));
        self::assertCount(3, $source->page($this->subject(), new TimelineCriteria(correlationId: $lookAlike), null, 50));
    }

    public function testWithoutTheCommentsRepositoryNoCommentIsListed(): void
    {
        $container = self::getContainer();
        $source    = new TaskTimelineSource(
            $this->em,
            $container->get('security.token_storage'),
            $container->get(TaskBoardAccessCheckerInterface::class),
            $container->get(TaskBoardResourceAccessChecker::class),
        );
        self::assertSame(['task.title', 'task.title', 'task.priority', 'task.description'], $this->sortedKinds($source->page($this->subject(), new TimelineCriteria(), null, 50)));
    }

    private function history(TaskChangeType $type, string $old, string $new, int $second, ?string $correlation = null, ?WriteProvenance $provenance = null): void
    {
        $task  = $this->em->find(Task::class, $this->task->getId()) ?? $this->task;
        $owner = $this->em->find(AppUser::class, $this->owner->getId()) ?? $this->owner;
        $row   = new TaskChangeHistory($task, $owner, $type, $old, $new, provenance: $provenance, correlationId: $correlation === null ? null : CorrelationId::fromString($correlation));
        (new ReflectionProperty(TaskChangeHistory::class, 'createdAt'))->setValue($row, $this->base->modify('+' . $second . ' seconds'));
        $this->em->persist($row);
        $this->em->flush();
    }

    private function comment(int $second, bool $deleted = false): void
    {
        $comment = new TaskComment($this->task, $this->owner, 'Hello', [], $this->base->modify('+' . $second . ' seconds'));
        if ($deleted) {
            (new ReflectionProperty(TaskComment::class, 'deletedAt'))->setValue($comment, $this->base);
        }
        $this->em->persist($comment);
        $this->em->flush();
    }

    private function signIn(AppUser $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function source(): TaskTimelineSource
    {
        $source = self::getContainer()->get('app.timeline_source');
        self::assertInstanceOf(TaskTimelineSource::class, $source);

        return $source;
    }

    private function subject(): TimelineSubject
    {
        return new TimelineSubject(TaskTimelineSource::SUBJECT_TASK, $this->task->getId());
    }

    private function at(int $second): string
    {
        return $this->base->modify('+' . $second . ' seconds')->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * @param list<TimelineEvent> $events
     *
     * @return list<string>
     */
    private function kinds(array $events): array
    {
        return array_map(static fn (TimelineEvent $event): string => $event->kind, $events);
    }

    /**
     * Kinds in time order (newest first), the kinds of one second sorted, since ties are ordered by id.
     *
     * @param list<TimelineEvent> $events
     *
     * @return list<string>
     */
    private function sortedKinds(array $events, bool $byTime = true): array
    {
        $groups = [];
        foreach ($events as $event) {
            $groups[$byTime ? $event->occurredAt->format('U') : 0][] = $event->kind;
        }
        $kinds = [];
        foreach ($groups as $group) {
            sort($group);
            $kinds = [...$kinds, ...array_reverse($group)];
        }

        return $kinds;
    }

    /**
     * @param list<TimelineEvent> $events
     * @param callable(TimelineEvent): bool $match
     */
    private function only(array $events, callable $match): TimelineEvent
    {
        $found = array_values(array_filter($events, $match));
        self::assertCount(1, $found);

        return $found[0];
    }
}
