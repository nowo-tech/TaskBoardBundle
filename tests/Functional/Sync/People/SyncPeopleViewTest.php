<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Since 1.25: how a synced task shows its remote people — the creator, comment authors, people assigned or watching
 * only in the provider, the *no access to this board* badge, anonymised names and the history tokens.
 */
final class SyncPeopleViewTest extends PeopleFunctionalTestCase
{
    public function testTheViewModelsOfARemoteCreatorCommentAuthorAndUnmatchedPeople(): void
    {
        [$task, $comment, $local, $people, $mallory] = $this->scenario();
        $views                                       = $this->views();

        $creator = $views->creatorOf($task);
        self::assertNotNull($creator);
        self::assertSame($people['creator']->getId(), $creator->personId);
        self::assertSame('User 1', $creator->name);
        self::assertSame('clickup', $creator->provider);
        self::assertTrue($creator->external, 'mapped to someone who cannot view the board');
        self::assertFalse($creator->boardAccess);
        self::assertFalse($creator->anonymised);

        $author = $views->commentAuthorOf($comment);
        self::assertNotNull($author);
        self::assertSame('User 2', $author->name);
        self::assertFalse($author->external, 'mapped to a viewer');
        self::assertTrue($author->boardAccess);
        self::assertNull($views->commentAuthorOf($local));

        $assignees = $views->unmatchedOf($task);
        self::assertCount(1, $assignees);
        self::assertSame('User 3', $assignees[0]->name);
        self::assertTrue($assignees[0]->external);
        self::assertNull($assignees[0]->boardAccess);
        self::assertSame([], $views->unmatchedOf($task, 'watcher'));
        self::assertSame([], $views->unmatchedOf($task, 'nope'));
        $plain = $this->task($task->getBoard(), 'Local');
        self::assertNull($views->creatorOf($plain));
        self::assertSame([], $views->unmatchedOf($plain));

        // History tokens: a person (anonymised: the translated "former" name), a user, anything else unchanged.
        $this->directories()->forget($people['creator'], null);
        $this->em->flush();
        self::assertSame('Former ClickUp user #1', $views->tokenLabel('person:' . $people['creator']->getId()));
        self::assertSame('Former clickup user #1', (new SyncPeopleViewProvider($this->em, $this->accessChecker()))->creatorOf($task)?->name);
        self::assertTrue($views->creatorOf($task)?->anonymised);
        self::assertSame('person:unknown', $views->tokenLabel('person:unknown'));
        self::assertSame((string) $mallory, $views->tokenLabel('user:' . $mallory->getId()));
        self::assertSame('user:999999', $views->tokenLabel('user:999999'));
        self::assertSame('user:1', (new SyncPeopleViewProvider($this->em, $this->accessChecker()))->tokenLabel('user:1'));
        self::assertSame('user:', $views->tokenLabel('user:'));
        self::assertSame('plain', $views->tokenLabel('plain'));
        // The task's sync row is read once per request.
        self::assertNotNull($views->creatorOf($task));
    }

    public function testTheTaskPageShowsTheRemotePeople(): void
    {
        [$task, , , $people, $mallory] = $this->scenario();
        $recorder                      = new TaskChangeRecorder();
        $recorder->record($task, $this->owner, TaskChangeType::SyncPersonReassociated, 'person:' . $people['assignee']->getId(), 'user:' . $mallory->getId(), 'clickup');
        $this->em->flush();
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/task/' . $task->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Created by User 1 in ClickUp', $html);
        self::assertStringContainsString('no access to this board', $html);
        self::assertStringContainsString('Also assigned in ClickUp:', $html);
        self::assertStringContainsString('User 3', $html);
        self::assertStringNotContainsString('User 2 (ClickUp)', $html, 'a projected author is the local user');
        self::assertStringContainsString('re-associated a person from the provider', $html);
        self::assertStringContainsString((string) $mallory, $html);
        self::assertStringNotContainsString('person:', $html);

        // An unmatched comment author shows as "<name> (ClickUp)".
        $people['author']->map(TaskSyncPersonMapping::External, null, null, new DateTimeImmutable());
        $this->em->flush();
        $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertStringContainsString('User 2 (ClickUp)', (string) $this->client->getResponse()->getContent());
    }

    private function views(): SyncPeopleViewProvider
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return new SyncPeopleViewProvider($this->em, $this->accessChecker(), $translator, AppUser::class);
    }

    /**
     * A synced task: the creator mapped to a user without board access, a comment by a person mapped to the owner, an
     * unmatched assignee; plus a local comment.
     *
     * @return array{0: Task, 1: TaskComment, 2: TaskComment, 3: array<string, TaskSyncPerson>, 4: AppUser}
     */
    private function scenario(): array
    {
        $board   = $this->board();
        $mallory = $this->user('mallory@example.test');
        $service = $this->directories();
        $dir     = $service->directoryForDraft($board, 'clickup', '9000001');
        $session = $service->session($dir, $board);
        $people  = [
            'creator'  => $session->person(new RemotePerson('9001', 'User 1'), TaskSyncPerson::KIND_CREATOR),
            'author'   => $session->person(new RemotePerson('9002', 'User 2'), TaskSyncPerson::KIND_COMMENT),
            'assignee' => $session->person(new RemotePerson('9003', 'User 3'), TaskSyncPerson::KIND_ASSIGNEE),
        ];
        $people['creator']->map(TaskSyncPersonMapping::Manual, $mallory, null, new DateTimeImmutable());
        $people['author']->map(TaskSyncPersonMapping::Manual, $this->owner, null, new DateTimeImmutable());
        $this->em->flush();
        $connection = $this->connection($board, $dir);
        $task       = $this->task($board, 'Imported');
        $item       = $this->item($connection, $task, '86a1x1');
        $item->setCreatorPerson($people['creator'])->setUnmatchedPeople('assignee', [$people['assignee']->getId(), 'gone']);
        $comment = new TaskComment($task, $this->owner, 'Remote words');
        $local   = new TaskComment($task, $this->owner, 'Local words');
        $this->em->persist($comment);
        $this->em->persist($local);
        $this->em->persist((new TaskSyncComment($item, $comment, 'k1', 'h', 'h'))->setAuthorPerson($people['author']));
        $this->em->flush();

        return [$task, $comment, $local, $people, $mallory];
    }
}
