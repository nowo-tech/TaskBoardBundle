<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Tests\Functional\App\AttributionPeopleResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;

use function array_replace_recursive;

/**
 * Since 1.28.0 (audit HI-M2): the writes that reach no people session or person reference apply the same rule: a user who
 * cannot view the board, or who is attribution only, is neither member nor comment author, so no task through
 * `isTaskParticipant`.
 */
final class EmailPathGuardTest extends SyncPeopleFunctionalTestCase
{
    private const EMAIL = 'flag@host.test';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['people' => ['resolver' => 'app.people_resolver_attribution']]]);
    }

    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        AttributionPeopleResolver::$attributionOnly = [];
        parent::setUp();
    }

    private function emailRow(): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: 'By mail', externalId: 't1', status: 'To do', priority: TaskPriority::Normal, assigneeEmail: self::EMAIL, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@1790000001'),
        );
    }

    private function scene(): void
    {
        $this->put($this->emailRow());
        $this->provider->setComments('t1', [new RemoteComment('c1', 'hi', self::EMAIL, 'Flag', new DateTimeImmutable('@1790000100'))]);
    }

    private function assertNotWritten(): void
    {
        $this->em->clear();
        $this->refreshOwner();
        $task = $this->taskTitled('By mail');
        self::assertCount(0, $task->getMembers());
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);
        $author = $comment->getAuthor();
        self::assertNotSame(self::EMAIL, $author instanceof AppUser ? $author->getEmail() : null);
        self::assertStringContainsString('Flag', $comment->getBody(), 'the name stays as text');
    }

    public function testPeopleOffAttributionOnlyEvenIfTheBoardAllows(): void
    {
        $this->viewer(self::EMAIL);
        AttributionPeopleResolver::$attributionOnly = [self::EMAIL];
        $board                                      = $this->boardWithColumns('To do');
        $this->scene();
        $report = $this->sync($board, people: false);
        self::assertSame(2, $report->stats['emailPath.notWritten'] ?? null);
        $this->assertNotWritten();
    }

    public function testPeopleOffNoBoardAccess(): void
    {
        $this->user(self::EMAIL);
        $board = $this->boardWithColumns('To do');
        $this->scene();
        $this->sync($board, people: false);
        $this->assertNotWritten();
    }

    public function testNoDirectoryAttached(): void
    {
        $this->viewer(self::EMAIL);
        AttributionPeopleResolver::$attributionOnly = [self::EMAIL];
        $board                                      = $this->boardWithColumns('To do');
        // The provider knows no workspace for the list: no directory, every row takes the e-mail path.
        $this->scene();
        $this->sync($board);
        self::assertNull($this->connectionOf($board)?->getDirectory());
        $this->assertNotWritten();
    }

    public function testARowAndACommentWithAnEmailAndNoValidId(): void
    {
        $this->viewer(self::EMAIL);
        AttributionPeopleResolver::$attributionOnly = [self::EMAIL];
        $board                                      = $this->boardWithColumns('To do');
        $this->workspace([]);
        $this->scene();
        $this->sync($board);
        self::assertNotNull($this->connectionOf($board)?->getDirectory());
        $this->assertNotWritten();
    }

    public function testAnAddressNobodyHasStaysAName(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->scene();
        $report = $this->sync($board, people: false);
        self::assertArrayNotHasKey('emailPath.notWritten', $report->stats);
        $this->assertNotWritten();
    }

    public function testAUserWhoCanViewAndIsNotFlaggedIsStillWritten(): void
    {
        $this->viewer(self::EMAIL);
        $board = $this->boardWithColumns('To do');
        $this->scene();
        $this->sync($board, people: false);
        $this->em->clear();
        $this->refreshOwner();
        self::assertCount(1, $this->taskTitled('By mail')->getMembers());
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy([]);
        self::assertInstanceOf(TaskComment::class, $comment);
        $author = $comment->getAuthor();
        self::assertSame(self::EMAIL, $author instanceof AppUser ? $author->getEmail() : null);
    }
}
