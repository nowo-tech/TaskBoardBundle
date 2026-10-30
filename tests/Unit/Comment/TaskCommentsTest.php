<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Comment;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Comment\DefaultTaskMentionCandidatesResolver;
use Nowo\TaskBoardBundle\Comment\MentionCandidate;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Dto\TaskCommentFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCommentEvent;
use Nowo\TaskBoardBundle\Event\TaskMentionEvent;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Text\PlainTaskTextRenderer;
use Nowo\TaskBoardBundle\Text\TaskTextRendererInterface;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Since 1.16: comments, @mentions (valid candidates only), their events, the default candidates and the rendering.
 */
final class TaskCommentsTest extends TestCase
{
    private TestUser $ana;

    private TestUser $bob;

    private TestUser $eve;

    private Task $task;

    /** @var list<object> */
    private array $events = [];

    private int $saves = 0;

    protected function setUp(): void
    {
        $this->ana  = new TestUser('1', 'ana', 'ana@example.com');
        $this->bob  = new TestUser('2', 'bob', 'bob@example.com');
        $this->eve  = new TestUser('3', 'eve', 'eve@example.com');
        $this->task = new Task(new TaskBoard('Board', 'board', $this->ana), 'Task', $this->ana);
    }

    public function testAddingACommentKeepsOnlyValidMentionsNamedInTheText(): void
    {
        $comment = $this->manager()->add($this->task, $this->ana, "  Hi @bob@example.com and @eve@example.com,\r\nsee @ana@example.com  ", ['2', '3', '1', '99']);

        self::assertSame("Hi @bob@example.com and @eve@example.com,\nsee @ana@example.com", $comment->getBody());
        // eve is no candidate; ana mentions herself (kept in the text, never notified).
        self::assertSame([['id' => '1', 'label' => 'ana@example.com'], ['id' => '2', 'label' => 'bob@example.com']], $comment->getMentions());
        self::assertSame(['1', '2'], $comment->getMentionedIds());
        self::assertTrue($comment->mentions('2'));
        self::assertFalse($comment->mentions('3'));
        self::assertSame(1, $this->saves);
        self::assertCount(2, $this->events);
        self::assertInstanceOf(TaskCommentEvent::class, $this->events[0]);
        self::assertSame(TaskCommentEvent::CREATED, $this->events[0]->getAction());
        self::assertSame($comment, $this->events[0]->getComment());
        self::assertInstanceOf(TaskMentionEvent::class, $this->events[1]);
        self::assertSame([$this->bob], $this->events[1]->getMentionedUsers());
        self::assertSame($comment, $this->events[1]->getComment());
        self::assertSame($this->ana, $this->events[1]->getActor());
        self::assertSame($this->task, $this->events[1]->getTask());

        $plain = $this->manager()->add($this->task, $this->ana, 'No mentions', []);
        self::assertSame([], $plain->getMentions());
    }

    public function testAnEditNotifiesOnlyThePeopleItAdds(): void
    {
        $comment      = new TaskComment($this->task, $this->ana, 'Hi @bob@example.com', [['id' => '2', 'label' => 'bob@example.com']]);
        $this->events = [];

        $this->manager(['1', '2', '3'])->edit($comment, $this->ana, 'Hi @bob@example.com and @eve@example.com', ['2', '3']);

        self::assertNotNull($comment->getEditedAt());
        self::assertSame(TaskCommentEvent::EDITED, $this->events[0]->getAction());
        self::assertSame([$this->eve], $this->events[1]->getMentionedUsers());

        $this->events = [];
        $this->manager(['1', '2', '3'])->edit($comment, $this->ana, 'Only @bob@example.com now', ['2', '3']);
        self::assertCount(1, $this->events, 'no new mention, no mention event');
        self::assertSame(['2'], $comment->getMentionedIds());
    }

    public function testEmptyAndTooLongCommentsAreRefused(): void
    {
        foreach (['   ', str_repeat('x', TaskComment::MAX_LENGTH + 1)] as $body) {
            try {
                $this->manager()->add($this->task, $this->ana, $body);
                self::fail('InvalidArgumentException expected');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame(0, $this->saves);
        $this->manager()->add($this->task, $this->ana, str_repeat('x', TaskComment::MAX_LENGTH));
        self::assertSame(1, $this->saves);
    }

    public function testDeletingKeepsThePlaceAndClearsTheText(): void
    {
        $comment = new TaskComment($this->task, $this->ana, 'Secret', [['id' => '2', 'label' => 'bob@example.com']]);
        self::assertFalse($comment->isDeleted());
        self::assertNull($comment->getDeletedAt());
        self::assertNotSame('', $comment->getId());
        self::assertSame($this->task, $comment->getTask());
        self::assertSame($this->ana, $comment->getAuthor());
        self::assertNotNull($comment->getCreatedAt());

        $this->manager()->delete($comment);

        self::assertTrue($comment->isDeleted());
        self::assertNotNull($comment->getDeletedAt());
        self::assertSame('', $comment->getBody());
        self::assertSame([], $comment->getMentions());
    }

    public function testTheAuthorIsMatchedByIdOrIdentity(): void
    {
        $comment = new TaskComment($this->task, $this->ana, 'x');

        self::assertTrue(TaskCommentManager::isAuthor($comment, $this->ana));
        self::assertTrue(TaskCommentManager::isAuthor($comment, new TestUser('1', 'ana-again')));
        self::assertFalse(TaskCommentManager::isAuthor($comment, $this->bob));
        self::assertFalse(TaskCommentManager::isAuthor($comment, new stdClass()));
    }

    public function testTheDefaultCandidatesAreTheAssignableUsersElseTheBoardsPeople(): void
    {
        $provider = $this->createMock(TaskAssigneeProviderInterface::class);
        $provider->method('assignableUsers')->willReturn([$this->bob, $this->ana, $this->bob, new stdClass()]);
        $labels = array_map(static fn (MentionCandidate $c): string => $c->label, (new DefaultTaskMentionCandidatesResolver($provider))->candidates($this->task));
        self::assertSame(['ana@example.com', 'bob@example.com'], $labels);

        $board = $this->task->getBoard();
        $other = new Task($board, 'Other', $this->ana, column: new BoardColumn($board, 'Todo', 0));
        $other->addMember(new TaskMember($other, $this->eve, TaskMemberRole::Assignee));
        $board->getTasks()->add($other);
        $ids = array_map(static fn (MentionCandidate $c): string => $c->id, (new DefaultTaskMentionCandidatesResolver())->candidates($this->task));
        self::assertSame(['1', '3'], $ids);
    }

    public function testTheFormDataReadsThePickedIds(): void
    {
        self::assertSame(['1', '2'], (new TaskCommentFormData('x', '[{"id":"1","label":"A"},2,{"id":""},{"label":"no id"},null]'))->mentionIds());
        self::assertSame([], (new TaskCommentFormData('x', 'nope'))->mentionIds());
    }

    public function testCommentBodyIsEscapedWithMentionChips(): void
    {
        $comment = new TaskComment($this->task, $this->ana, "<b>Hi</b> @Ann and @Ann Lee\nbye", [['id' => '5', 'label' => 'Ann'], ['id' => '6', 'label' => 'Ann Lee']]);

        $html = TaskBoardTwigExtension::commentBody($comment, '6');

        self::assertSame('&lt;b&gt;Hi&lt;/b&gt; <span class="nowo-task-mention" data-user-id="5">@Ann</span> and <span class="nowo-task-mention nowo-task-mention--me" data-user-id="6">@Ann Lee</span><br>' . "\nbye", $html);
        self::assertSame('plain', TaskBoardTwigExtension::commentBody(new TaskComment($this->task, $this->ana, 'plain')));
    }

    /** Since 1.19: a host text renderer (here a fake Markdown) renders the body; the mention chips survive it. */
    public function testCommentBodyGoesThroughTheTextRenderer(): void
    {
        $markdown = new class implements TaskTextRendererInterface {
            public function render(string $text): string
            {
                return '<p>' . str_replace('**done**', '<strong>done</strong>', htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        };
        $comment = new TaskComment($this->task, $this->ana, '**done** @Ann', [['id' => '5', 'label' => 'Ann']]);

        self::assertSame('<p><strong>done</strong> <span class="nowo-task-mention" data-user-id="5">@Ann</span></p>', TaskBoardTwigExtension::commentBody($comment, null, $markdown));

        $extension = new TaskBoardTwigExtension('layout.html.twig', 'tabler', [], new TaskObjectLinkResolver(), null, '', $markdown);
        self::assertSame('<p><strong>done</strong> <span class="nowo-task-mention nowo-task-mention--me" data-user-id="5">@Ann</span></p>', $extension->renderCommentBody($comment, '5'));
        self::assertSame('<p><strong>done</strong></p>', $extension->renderText('**done**'));
        self::assertSame('', $extension->renderText('  '));
        self::assertSame('', $extension->renderText(null));
        self::assertSame('a<br>' . "\n" . '&lt;b&gt;', (new PlainTaskTextRenderer())->render("a\n<b>"));
    }

    public function testLabelsAndCutTexts(): void
    {
        self::assertSame('ana@example.com', UserLabel::of($this->ana));
        self::assertSame('ana', UserLabel::of(new TestUser('9', 'ana')));
        self::assertSame('#?', UserLabel::of(new stdClass()));
        $withId = new class {
            public function getId(): int
            {
                return 7;
            }
        };
        self::assertSame('#7', UserLabel::of($withId));

        self::assertSame('abc', Text::cut('abcdef', 3));
        self::assertSame('ab', Text::cut('ab', 3));
        self::assertNull(Text::cutNullable(null, 3));

        $long  = str_repeat('é', 300);
        $board = new TaskBoard($long, 'slug', $this->ana);
        self::assertSame(255, mb_strlen($board->getName()));
        self::assertSame(255, mb_strlen($board->setName($long)->getName()));
        $column = new BoardColumn($board, $long, 0);
        self::assertSame(128, mb_strlen($column->getName()));
        self::assertSame(128, mb_strlen($column->setName($long)->getName()));
        $task = new Task($board, $long, $this->ana);
        self::assertSame(Task::TITLE_MAX, mb_strlen($task->getTitle()));
        self::assertSame(255, mb_strlen($task->setTitle($long)->getTitle()));
        $link = TaskLink::forHostObject($task, 'issue', '1', $long);
        self::assertSame(255, mb_strlen((string) $link->getLabel()));
        $link = new TaskLink($task, TaskLinkType::Url, 'https://x.test', null, $long);
        self::assertSame(128, mb_strlen((string) $link->getExternalId()));
        self::assertSame(255, mb_strlen((string) $link->setLabel($long)->getLabel()));
        self::assertSame(128, mb_strlen((string) $link->setExternalId($long)->getExternalId()));
    }

    /**
     * @param list<string> $candidateIds
     */
    private function manager(array $candidateIds = ['1', '2']): TaskCommentManager
    {
        $repository = $this->createMock(TaskCommentRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (): void {
            ++$this->saves;
        });

        $users      = ['1' => $this->ana, '2' => $this->bob, '3' => $this->eve];
        $candidates = $this->createMock(TaskMentionCandidatesResolverInterface::class);
        $candidates->method('candidates')->willReturn(array_map(static fn (string $id): MentionCandidate => new MentionCandidate($id, $users[$id]->email, $users[$id]), $candidateIds));

        $dispatcher = new EventDispatcher();
        foreach ([TaskBoardEvents::TASK_COMMENT, TaskBoardEvents::TASK_MENTION] as $name) {
            $dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }

        return new TaskCommentManager($repository, $candidates, $dispatcher);
    }
}
