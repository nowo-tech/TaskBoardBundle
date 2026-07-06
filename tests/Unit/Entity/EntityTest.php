<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class EntityTest extends TestCase
{
    public function testTaskBoardLifecycle(): void
    {
        $user  = new TestUser('1', 'owner@example.com');
        $board = new TaskBoard('Sprint', 'sprint', $user, 'Desc');
        $team  = new Team('Eng');
        $col   = new BoardColumn($board, 'Todo', 0, '#fff');

        $board->setName('Renamed')->setDescription('New')->setSlug('renamed')->setTeam($team)->addColumn($col);
        self::assertFalse($board->isArchived());
        $board->archive();
        self::assertTrue($board->isArchived());
        self::assertNotNull($board->getArchivedAt());
        $board->restore();
        self::assertFalse($board->isArchived());
        self::assertSame($team, $board->getTeam());
        self::assertSame($user, $board->getCreator());
        self::assertSame('renamed', $board->getSlug());
        self::assertCount(1, $board->getColumns());
        self::assertCount(0, $board->getTasks());
    }

    public function testTaskRelationsAndMutators(): void
    {
        $user   = new TestUser('1', 'dev@example.com');
        $board  = new TaskBoard('Demo', 'demo', $user);
        $column = new BoardColumn($board, 'Todo', 0);
        $board->addColumn($column);
        $parent = new Task($board, 'Parent', $user, column: $column);
        $child  = new Task($board, 'Child', $user, column: $column, parent: $parent, priority: TaskPriority::High);

        $child
            ->setTitle('Child updated')
            ->setDescription('Details')
            ->setPriority(TaskPriority::Urgent)
            ->setPosition(2)
            ->setEstimatedMinutes(60)
            ->setDueAt(new DateTimeImmutable('+1 day'))
            ->setTags(['api'])
            ->addTimeSeconds(120)
            ->markCompleted();

        self::assertTrue($child->isCompleted());
        self::assertSame(120, $child->getTotalTimeSeconds());
        self::assertSame($parent, $child->getParent());
        self::assertCount(0, $parent->getSubtasks());

        $member = new TaskMember($child, $user, TaskMemberRole::Assignee);
        $child->addMember($member);
        self::assertSame($user, $child->getAssignee());
        self::assertSame($child, $member->getTask());
        $member->setTask($child);
        $child->removeMember($member);

        self::assertSame($board, $column->getBoard());
        $column->setName('Doing')->setPosition(3)->setColor('#000');
        self::assertCount(0, $column->getTasks());

        $link = new TaskLink($child, TaskLinkType::Url, 'https://example.com', 'Docs', 'ext-1');
        $child->addLink($link);
        self::assertCount(1, $child->getLinks());
        $link->setLinkType(TaskLinkType::Issue)->setUrl('https://issue.test')->setLabel('Issue')->setExternalId('42');
        self::assertSame(TaskLinkType::Issue, $link->getLinkType());
        self::assertSame('https://issue.test', $link->getUrl());
        self::assertNotNull($link->getCreatedAt());
        $child->removeLink($link);

        $dep = new TaskDependency($parent, $child, TaskDependencyType::Blocks);
        self::assertSame($parent, $dep->getSourceTask());
        $parent->getOutgoingDependencies()->add($dep);
        $child->getIncomingDependencies()->add($dep);

        $doc = new TaskDocument($child, 'Spec', 'Content', $user);
        $doc->setTitle('Spec v2')->setContent('Updated')->setPosition(1);
        self::assertSame($user, $doc->getCreator());
        self::assertNotNull($doc->getCreatedAt());
        self::assertNotNull($doc->getUpdatedAt());
        $child->getDocuments()->add($doc);

        $time = new TaskTimeEntry($child, $user, 300, new DateTimeImmutable(), 'manual');
        self::assertSame(300, $time->getMinutes());
        self::assertSame('manual', $time->getDescription());
        self::assertSame($child, $time->getTask());
        self::assertSame($user, $time->getUser());
        $child->getTimeEntries()->add($time);

        $history = new TaskChangeHistory($child, $user, TaskChangeType::Title, 'Old', 'New', 'ctx');
        self::assertSame('ctx', $history->getContext());
        self::assertNotNull($history->getCreatedAt());
        $child->addChangeHistory($history);
        self::assertCount(1, $child->getChangeHistory());
        $entry = $child->getChangeHistory()->first();
        self::assertSame($user, $entry->getUser());
        self::assertSame('Old', $entry->getOldValue());
        self::assertSame('New', $entry->getNewValue());
        $child->addChangeHistory($entry);
        self::assertCount(1, $child->getChangeHistory());

        $link->setTask($child);
        self::assertSame($child, $link->getTask());

        $child->markIncomplete();
        self::assertFalse($child->isCompleted());
    }

    public function testTeamAndTeamMember(): void
    {
        $team = new Team('Ops');
        $team->setName('Operations');
        $user = new TestUser('2', 'member@example.com');
        $tm   = new TeamMember($team, $user, TeamRole::Manager);

        self::assertTrue($tm->isManager());
        self::assertFalse((new TeamMember($team, $user, TeamRole::Member))->isManager());
        self::assertSame('Operations', $team->getName());
        self::assertSame(TeamRole::Manager, $tm->getRole());
        self::assertSame($team, $tm->getTeam());
        self::assertNotEmpty($tm->getId());
        self::assertSame($user, $tm->getUser());
    }
}
