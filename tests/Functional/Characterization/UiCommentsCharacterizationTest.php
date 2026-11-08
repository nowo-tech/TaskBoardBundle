<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Characterization;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskCommentEvent;
use Nowo\TaskBoardBundle\Event\TaskMentionEvent;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function json_encode;
use function str_contains;

/**
 * Spec 213 S0: the comment routes' output, pinned before their rules move out of the controller. Must not change.
 */
final class UiCommentsCharacterizationTest extends FunctionalTestCase
{
    use CapturesUiOutput;

    private const BASE = '/tools/task-board/task/';

    private AppUser $reader;

    private Task $work;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = $this->user('reader@example.com');
        $this->work   = $this->task($this->board(), 'Work');
        $dispatcher   = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // The reader may read the task and not edit it, as a host's listener decides it.
        $readerId = $this->reader->getId();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($readerId): void {
            $subject = $event->getSubject();
            if ($subject instanceof AppUser && $subject->getId() === $readerId) {
                $event->grant();
                $event->markReadOnly();
            }
        });
        $dispatcher->addListener(TaskBoardEvents::TASK_COMMENT, function (TaskCommentEvent $event): void {
            $this->events[] = 'comment:' . $event->getAction();
        });
        $dispatcher->addListener(TaskBoardEvents::TASK_MENTION, function (TaskMentionEvent $event): void {
            $this->events[] = 'mention';
        });
        $this->logger()->records = [];
    }

    public function testAReaderAddsACommentAndTheMentionedPersonIsNamed(): void
    {
        $this->login($this->reader);

        $this->comment('Hi @owner@example.com', [['id' => (string) $this->owner->getId(), 'label' => 'owner@example.com']]);

        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . $this->work->getId(),
            'flashes'  => ['success' => ['Comment added.']],
            'writes'   => ['task_comment'],
            'refused'  => [],
        ], $this->captured());
        self::assertSame(['comment:created', 'mention'], $this->events);
        $stored = $this->onlyComment();
        self::assertSame('Hi @owner@example.com', $stored->getBody());
        self::assertSame([(string) $this->owner->getId()], $stored->getMentionedIds());
    }

    public function testAnEmptyCommentIsRefusedWithAFlash(): void
    {
        $this->login($this->reader);

        $this->comment('');

        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . $this->work->getId(),
            'flashes'  => ['error' => ['The comment was not saved: write between 1 and 5000 characters.']],
            'writes'   => [],
            'refused'  => [],
        ], $this->captured());
        self::assertSame([], $this->events);
    }

    public function testAForgedTokenOnACommentIs403AndLogged(): void
    {
        $this->login($this->reader);

        $this->client->request('POST', self::BASE . $this->work->getId() . '/comment', ['task_comment' => ['body' => 'x', 'mentions' => '[]', '_token' => 'forged']]);

        $captured = $this->captured();
        self::assertSame(403, $captured['status']);
        self::assertSame(['csrf_invalid'], $captured['refused']);
        self::assertSame([], $captured['writes']);
        self::assertSame([], $this->events);
    }

    public function testReadersAreRefusedWhenTheOptionIsOffAndEditorsAreNot(): void
    {
        self::$taskBoardConfig = ['comments' => ['readers_can_comment' => false]];
        self::ensureKernelShutdown();
        $this->events = [];
        $this->setUp();
        $this->login($this->reader);

        $this->comment('Reader');
        self::assertSame(403, $this->captured()['status']);

        $this->login($this->owner);
        $this->comment('Editor');
        self::assertSame(['task_comment'], $this->captured()['writes']);
        self::assertSame('Editor', $this->onlyComment()->getBody());
    }

    public function testOnlyTheAuthorEditsAndOnlyNewMentionsAreNotified(): void
    {
        $this->login($this->reader);
        $mention = [['id' => (string) $this->owner->getId(), 'label' => 'owner@example.com']];
        $this->comment('Hi @owner@example.com', $mention);
        $this->captured();
        $this->events = [];
        $comment      = $this->onlyComment();

        $this->client->request('POST', $this->commentUrl($comment), ['task_comment' => ['body' => 'Still @owner@example.com', 'mentions' => json_encode($mention), '_token' => PredictableCsrfTokenManager::value('task_comment')]]);
        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . $this->work->getId(),
            'flashes'  => ['success' => ['Comment updated.']],
            'writes'   => ['task_comment_edit'],
            'refused'  => [],
        ], $this->captured());
        self::assertSame(['comment:edited'], $this->events);

        $this->login($this->owner);
        $this->client->request('POST', $this->commentUrl($comment), ['task_comment' => ['body' => 'Hijack', 'mentions' => '[]', '_token' => PredictableCsrfTokenManager::value('task_comment')]]);
        self::assertSame(403, $this->captured()['status']);
        self::assertSame('Still @owner@example.com', $this->onlyComment()->getBody());
    }

    public function testTheAuthorOrAnEditorDeletesAndNobodyElse(): void
    {
        $other = $this->user('other@example.com');
        $this->login($this->owner);
        $this->comment('By the owner');
        $this->captured();
        $comment      = $this->onlyComment();
        $this->events = [];

        // A reader who did not write it.
        $this->login($this->reader);
        $this->client->request('POST', $this->commentUrl($comment) . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_task_comment_delete')]);
        self::assertSame(403, $this->captured()['status']);
        self::assertFalse($this->onlyComment()->isDeleted());

        // Someone with no access at all to the task.
        $this->login($other);
        $this->client->request('POST', $this->commentUrl($comment) . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_task_comment_delete')]);
        self::assertSame(403, $this->captured()['status']);

        // The author; no event is dispatched for a deletion.
        $this->login($this->owner);
        $this->client->request('POST', $this->commentUrl($comment) . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_task_comment_delete')]);
        self::assertSame([
            'status'   => 302,
            'location' => self::BASE . $this->work->getId(),
            'flashes'  => ['success' => ['Comment deleted.']],
            'writes'   => ['task_comment_delete'],
            'refused'  => [],
        ], $this->captured());
        self::assertSame([], $this->events);
        self::assertTrue($this->onlyComment()->isDeleted());

        // Deleted: not found, for edit and delete alike.
        $this->client->request('POST', $this->commentUrl($comment) . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_task_comment_delete')]);
        self::assertSame(404, $this->captured()['status']);
    }

    public function testAReaderDeletesTheirOwnComment(): void
    {
        $this->login($this->reader);
        $this->comment('Mine');
        $this->captured();

        $this->client->request('POST', $this->commentUrl($this->onlyComment()) . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_task_comment_delete')]);

        self::assertSame(['task_comment_delete'], $this->captured()['writes']);
        self::assertTrue($this->onlyComment()->isDeleted());
    }

    public function testAPanelWriteAnswersTheFragmentWithTheViewersControls(): void
    {
        $this->login($this->reader);

        $this->client->xmlHttpRequest('POST', self::BASE . $this->work->getId() . '/comment', ['task_comment' => ['body' => 'From the panel', 'mentions' => '[]', '_token' => PredictableCsrfTokenManager::value('task_comment')]]);

        $captured = $this->captured();
        self::assertSame(200, $captured['status']);
        self::assertNull($captured['location']);
        self::assertSame(['task_comment'], $captured['writes']);
        $this->dumpBody('comment-panel-reader');
        $comment = $this->onlyComment();
        $page    = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $page);
        self::assertStringContainsString('From the panel', $page);
        self::assertStringContainsString($this->commentUrl($comment), $page, 'the author can edit');
        self::assertStringContainsString($this->commentUrl($comment) . '/delete', $page, 'the author can delete');
    }

    public function testTheTaskPageShowsEachViewerTheirControls(): void
    {
        $this->login($this->owner);
        $this->comment('By the owner');
        $this->captured();
        $comment = $this->onlyComment();

        $this->client->request('GET', self::BASE . $this->work->getId());
        self::assertResponseIsSuccessful();
        $this->dumpBody('task-page-editor');
        $editor = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->commentUrl($comment) . '"', $editor, 'the author edits');
        self::assertStringContainsString($this->commentUrl($comment) . '/delete', $editor);
        self::assertStringContainsString(self::BASE . $this->work->getId() . '/comment"', $editor, 'the box');

        $this->login($this->reader);
        $this->client->request('GET', self::BASE . $this->work->getId());
        self::assertResponseIsSuccessful();
        $this->dumpBody('task-page-reader');
        $reader = (string) $this->client->getResponse()->getContent();
        self::assertFalse(str_contains($reader, $this->commentUrl($comment) . '"'), 'not the author: no edit form');
        self::assertFalse(str_contains($reader, $this->commentUrl($comment) . '/delete'), 'not an editor: no delete');
        self::assertStringContainsString(self::BASE . $this->work->getId() . '/comment"', $reader, 'readers comment by default');
    }

    public function testTheTaskPageHidesTheBoxFromReadersWhenTheOptionIsOff(): void
    {
        self::$taskBoardConfig = ['comments' => ['readers_can_comment' => false]];
        self::ensureKernelShutdown();
        $this->events = [];
        $this->setUp();
        $this->login($this->reader);

        $this->client->request('GET', self::BASE . $this->work->getId());

        self::assertResponseIsSuccessful();
        $this->dumpBody('task-page-reader-option-off');
        self::assertStringNotContainsString(self::BASE . $this->work->getId() . '/comment"', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param list<array{id: string, label: string}> $mentions
     */
    private function comment(string $body, array $mentions = []): void
    {
        $this->client->request('POST', self::BASE . $this->work->getId() . '/comment', ['task_comment' => ['body' => $body, 'mentions' => json_encode($mentions), '_token' => PredictableCsrfTokenManager::value('task_comment')]]);
    }

    private function commentUrl(TaskComment $comment): string
    {
        return self::BASE . $this->work->getId() . '/comment/' . $comment->getId();
    }

    private function onlyComment(): TaskComment
    {
        $this->em->clear();
        $comments = $this->em->getRepository(TaskComment::class)->findAll();
        self::assertCount(1, $comments);

        return $comments[0];
    }
}
