<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Member\MemberBadge;
use Nowo\TaskBoardBundle\Member\MemberBadges;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedMemberBadges;
use ReflectionProperty;
use Twig\Environment;

use function array_filter;
use function array_values;
use function str_contains;
use function str_repeat;
use function str_starts_with;

/**
 * Since 2.18.0 (Beacon spec 234): member badges (one provider call per page, escaped values, unchanged output without
 * a provider, nothing kept across the requests of one kernel) and write provenance (stored on history rows, tasks and
 * comments; shown escaped; NULL without a provider).
 */
final class MemberBadgesAndProvenanceTest extends FunctionalTestCase
{
    /** The macro as 2.17.0 shipped it. */
    private const MACRO_2_17 = <<<'TWIG'
        {% macro member_label(user) %}
            {#- Since 2.15.0: the host user behind a user reference (database.user_reference: id); the user as it is otherwise. -#}
            {%- set user = nowo_task_board_user(user) -%}
            {% if user.displayName is defined and user.displayName %}
                {{ user.displayName }}
            {% elseif user.fullName is defined and user.fullName %}
                {{ user.fullName }}
            {% elseif user.email is defined and user.email %}
                {{ user.email }}
            {% elseif user.username is defined and user.username %}
                {{ user.username }}
            {% elseif user.id is defined %}
                #{{ user.id }}
            {% else %}
                {{ 'task_board.task.unknown_member'|trans({}, 'NowoTaskBoardBundle') }}
            {% endif %}
        {% endmacro %}
        TWIG;

    private const CONFIG = ['member_badge_provider' => 'app.member_badges', 'write_provenance_provider' => 'app.write_provenance'];

    protected function setUp(): void
    {
        ScriptedMemberBadges::reset();
        PathWriteProvenance::reset();
        self::$taskBoardConfig = str_starts_with($this->name(), 'testWithoutProviders') ? [] : self::CONFIG;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        ScriptedMemberBadges::reset();
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    public function testWithoutProvidersNothingIsStoredAndThePageIsUnchanged(): void
    {
        $task = $this->task($this->board(), 'Plan');
        $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner);
        $comment = $this->comments()->add($task, $this->owner, 'Hello');
        $this->comments()->edit($comment, $this->owner, 'Hello again');

        $entry = $task->getChangeHistory()->last();
        self::assertInstanceOf(TaskChangeHistory::class, $entry);
        self::assertNull($entry->getChannel());
        self::assertNull($entry->getActorLabel());
        self::assertNull($task->getChannel());
        self::assertNull($task->getActorLabel());
        self::assertSame($this->owner, $task->getUpdatedBy(), 'the last writer is the actor the bundle already has');
        self::assertNull($comment->getChannel());
        self::assertNull($comment->getActorLabel());
        self::assertSame($this->owner, $comment->getUpdatedBy());

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-member-badge"]'));
        self::assertCount(0, $crawler->filter('.nowo-task-activity__via'));

        // Without a provider the macro renders byte for byte as the 2.17 macro, with or without badges asked.
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $before = $twig->createTemplate('{% import _self as old %}{{ old.member_label(user) }}' . self::MACRO_2_17)->render(['user' => $this->owner]);
        $now    = $twig->createTemplate("{% import '@NowoTaskBoardBundle/manage/_task_member_label.html.twig' as m %}[{{ m.member_label(user) }}][{{ m.member_label(user, true) }}]")->render(['user' => $this->owner]);
        self::assertSame('[' . $before . '][' . $before . ']', $now);
        self::assertStringContainsString('owner@example.com', $before);
    }

    public function testTheProvenanceIsStoredOnEveryWriteAndShownEscaped(): void
    {
        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::Mcp, '<script>alert("t")</script>');
        $task                         = $this->task($this->board(), 'Plan');
        $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner);
        $comment = $this->comments()->add($task, $this->owner, 'Hello');
        $this->em->flush();

        $entry = $task->getChangeHistory()->last();
        self::assertInstanceOf(TaskChangeHistory::class, $entry);
        self::assertSame(WriteChannel::Mcp, $entry->getChannel());
        self::assertSame('<script>alert("t")</script>', $entry->getActorLabel());
        self::assertSame(WriteChannel::Mcp, $task->getChannel());
        self::assertSame(WriteChannel::Mcp, $comment->getChannel());

        // An edit by someone else through another channel: the comment keeps its author, its last writer changes.
        $editor                       = $this->user('editor@example.com');
        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::Api, str_repeat('n', 150));
        $this->comments()->edit($comment, $editor, 'Hello again');
        self::assertSame($this->owner, $comment->getAuthor());
        self::assertSame($editor, $comment->getUpdatedBy());
        self::assertSame(WriteChannel::Api, $comment->getChannel());
        self::assertSame(str_repeat('n', 120), $comment->getActorLabel(), 'cut to 120 when stored');

        // Read back from the database.
        $this->em->clear();
        $task = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(WriteChannel::Mcp, $task->getChannel());
        self::assertSame('<script>alert("t")</script>', $task->getActorLabel());
        $comment = $this->em->find(TaskComment::class, $comment->getId());
        self::assertInstanceOf(TaskComment::class, $comment);
        self::assertSame(WriteChannel::Api, $comment->getChannel());
        self::assertSame('editor@example.com', $comment->getUpdatedBy()?->getUserIdentifier());

        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->login($owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert("t")</script>', $content);
        self::assertStringContainsString('via MCP · &lt;script&gt;alert(&quot;t&quot;)&lt;/script&gt;', $content);
        self::assertContains('via MCP · <script>alert("t")</script>', $crawler->filter('[data-testid="nowo-task-history-via"]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['via API · ' . str_repeat('n', 120)], $crawler->filter('[data-testid="nowo-task-comment-via"]')->each(static fn ($node): string => $node->text()));
    }

    public function testTheBadgesOfAPageAreOneCallAndPrintedEscaped(): void
    {
        $task   = $this->task($this->board(), 'Plan');
        $people = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $person   = $this->user($name . '@example.com');
            $people[] = $person;
            $this->recorder()->record($task, $person, TaskChangeType::Title, 'x', $name);
            $this->comments()->add($task, $person, 'Comment by ' . $name);
        }
        $agent = $this->user('bot@example.com');
        $task->addMember(new TaskMember($task, $agent, TaskMemberRole::Assignee));
        $this->em->flush();
        ScriptedMemberBadges::$badges[(string) $agent->getId()]     = [new MemberBadge('Agent', 'agent', 'Service account')];
        ScriptedMemberBadges::$badges[(string) $people[0]->getId()] = [new MemberBadge('<img src=x onerror=alert(1)>', 'warning')];

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, ScriptedMemberBadges::$batches, 'one call for the whole page (no N+1)');
        self::assertSame(0, ScriptedMemberBadges::$single);
        foreach ([...$people, $agent] as $person) {
            self::assertContains((string) $person->getId(), ScriptedMemberBadges::$batches[0]);
        }

        $agentBadge = $crawler->filter('.nowo-task-detail__member-chip-name [data-testid="nowo-task-member-badge"]');
        self::assertCount(1, $agentBadge);
        self::assertSame('Agent', $agentBadge->text());
        self::assertSame('Service account', $agentBadge->attr('title'));
        self::assertStringContainsString('nowo-task-member-badge--agent', (string) $agentBadge->attr('class'));

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x', $content);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $content);
        // a@ wrote one history row and one comment: one badge each.
        self::assertCount(2, $crawler->filter('.nowo-task-member-badge--warning'));
        // The avatar titles keep the plain name (no badge markup inside an attribute).
        self::assertSame([], array_values(array_filter($crawler->filter('[title]')->each(static fn ($node): string => (string) $node->attr('title')), static fn (string $title): bool => str_contains($title, 'nowo-task-member-badge'))));
    }

    public function testNoBadgeOfOneRequestReachesTheNextInOneKernel(): void
    {
        // Worker mode: one kernel kept alive (no reboot), two requests by two users, the provider changes in between.
        $alice = $this->user('alice@example.com');
        $bob   = $this->user('bob@example.com');
        $taskA = $this->task($this->board('Alice', creator: $alice), 'A');
        $taskB = $this->task($this->board('Bob', creator: $bob), 'B');
        $this->recorder()->record($taskA, $alice, TaskChangeType::Title, 'x', 'A');
        $this->recorder()->record($taskB, $bob, TaskChangeType::Title, 'x', 'B');
        $this->em->flush();
        $kernel = $this->client->getKernel();

        ScriptedMemberBadges::$badges[(string) $alice->getId()] = [new MemberBadge('Agent', 'agent')];
        $this->login($alice);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $taskA->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(['Agent'], $crawler->filter('[data-testid="nowo-task-member-badge"]')->each(static fn ($node): string => $node->text()));

        ScriptedMemberBadges::$badges = [(string) $bob->getId() => [new MemberBadge('Guest', 'neutral')]];
        $this->login($bob);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $taskB->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($kernel, $this->client->getKernel(), 'the same kernel');
        self::assertSame(['Guest'], $crawler->filter('[data-testid="nowo-task-member-badge"]')->each(static fn ($node): string => $node->text()));
        self::assertCount(2, ScriptedMemberBadges::$batches, 'asked again: nothing kept from the first request');
        self::assertNotContains((string) $alice->getId(), ScriptedMemberBadges::$batches[1]);

        // The memo is empty once a request is done (cleared at the boundary and by kernel.reset).
        $memo = self::getContainer()->get(MemberBadges::class);
        self::assertInstanceOf(MemberBadges::class, $memo);
        $memo->reset();
        self::assertSame([], (new ReflectionProperty(MemberBadges::class, 'badges'))->getValue($memo));
    }

    public function testTheProvenanceAloneIsNoNewRevision(): void
    {
        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::Api, 'token');
        $task                         = $this->task($this->board(), 'Plan');
        $other                        = $this->task($task->getBoard(), 'Other');
        $revision                     = $task->getRevision();

        // A history row without a change of the task's own fields (e.g. a dependency's) stamps the provenance only.
        $this->recorder()->record($task, $this->user('x@example.com'), TaskChangeType::DependencyAdded, null, $other->getTitle(), 'blocks');
        $this->em->flush();
        self::assertSame(WriteChannel::Api, $task->getChannel());
        self::assertSame($revision, $task->getRevision());
    }

    private function tasks(): TaskManager
    {
        $service = self::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $service);

        return $service;
    }

    private function comments(): TaskCommentManager
    {
        $service = self::getContainer()->get(TaskCommentManager::class);
        self::assertInstanceOf(TaskCommentManager::class, $service);

        return $service;
    }

    private function recorder(): TaskChangeRecorder
    {
        $service = self::getContainer()->get(TaskChangeRecorder::class);
        self::assertInstanceOf(TaskChangeRecorder::class, $service);

        return $service;
    }
}
