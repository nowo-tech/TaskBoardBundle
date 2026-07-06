<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TaskLinkAttacherTest extends TestCase
{
    public function testEnrichesGitLabMergeRequestFromUrl(): void
    {
        $task = new Task(
            board: new TaskBoard('Demo', 'demo', new stdClass()),
            title: 'Task',
            creator: new stdClass(),
        );
        $user = new stdClass();

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with($task);

        $attacher = new TaskLinkAttacher($repository, new GitLabMergeRequestLinkParser(), new TaskChangeRecorder());
        $link     = $attacher->attach($task, new TaskLinkFormData(
            linkType: TaskLinkType::Url,
            url: 'https://gitlab.example.com/group/project/-/merge_requests/7',
        ), $user);

        self::assertSame(TaskLinkType::MergeRequest, $link->getLinkType());
        self::assertSame('7', $link->getExternalId());
        self::assertSame('MR !7', $link->getLabel());
        self::assertCount(1, $task->getChangeHistory());
        self::assertSame(TaskChangeType::LinkAdded, $task->getChangeHistory()->first()->getChangeType());
    }

    public function testUpdateChangesLinkFields(): void
    {
        $task = new Task(
            board: new TaskBoard('Demo', 'demo', new stdClass()),
            title: 'Task',
            creator: new stdClass(),
        );
        $user = new stdClass();

        $attacher = new TaskLinkAttacher(
            $this->createMock(TaskRepositoryInterface::class),
            new GitLabMergeRequestLinkParser(),
            new TaskChangeRecorder(),
        );
        $link = $attacher->attach($task, new TaskLinkFormData(
            linkType: TaskLinkType::Url,
            url: 'https://example.com/docs',
            label: 'Docs',
        ), $user);

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with($task);

        $attacher = new TaskLinkAttacher($repository, new GitLabMergeRequestLinkParser(), new TaskChangeRecorder());
        self::assertTrue($attacher->update($task, $link->getId(), new TaskLinkFormData(
            linkType: TaskLinkType::Documentation,
            url: 'https://example.com/guide',
            label: 'Guide',
            externalId: 'guide-1',
        ), $user));

        self::assertSame(TaskLinkType::Documentation, $link->getLinkType());
        self::assertSame('https://example.com/guide', $link->getUrl());
        self::assertSame('Guide', $link->getLabel());
        self::assertSame('guide-1', $link->getExternalId());
    }

    public function testRemoveDeletesLinkFromTask(): void
    {
        $task = new Task(
            board: new TaskBoard('Demo', 'demo', new stdClass()),
            title: 'Task',
            creator: new stdClass(),
        );
        $user = new stdClass();

        $attacher = new TaskLinkAttacher(
            $this->createMock(TaskRepositoryInterface::class),
            new GitLabMergeRequestLinkParser(),
            new TaskChangeRecorder(),
        );
        $link = $attacher->attach($task, new TaskLinkFormData(
            url: 'https://example.com',
        ), $user);

        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Task $saved): bool => $saved->getLinks()->count() === 0));

        $attacher = new TaskLinkAttacher($repository, new GitLabMergeRequestLinkParser(), new TaskChangeRecorder());
        self::assertTrue($attacher->remove($task, $link->getId(), $user));
    }
}
