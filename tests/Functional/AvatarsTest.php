<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\App\CountingAvatarResolver;
use Symfony\Component\DomCrawler\Crawler;

use function preg_replace;
use function trim;

/**
 * Since 1.23: member pictures — one resolver call per page (only the 3 chips a card shows), the unchanged markup
 * without a picture, the markup and escaping with one, and the access filters before resolving.
 */
final class AvatarsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        CountingAvatarResolver::reset();
        parent::setUp();
    }

    public function testEachPageMakesOneCallWithTheVisibleMembersOnly(): void
    {
        [$board, $crowded] = $this->crowdedBoard();
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, CountingAvatarResolver::$batches);
        self::assertSame(['p1@example.com', 'p2@example.com', 'p3@example.com', 'owner@example.com'], CountingAvatarResolver::$batches[0], 'the first 3 of the crowded card, then the other card');

        CountingAvatarResolver::$batches = [];
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list');
        self::assertCount(1, CountingAvatarResolver::$batches);

        CountingAvatarResolver::$batches = [];
        $this->client->request('GET', '/tools/task-board/task/' . $crowded->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, CountingAvatarResolver::$batches);
        foreach (['p4@example.com', 'p5@example.com', 'c1@example.com', 'c2@example.com', 'h1@example.com', 'h2@example.com'] as $email) {
            self::assertContains($email, CountingAvatarResolver::$batches[0]);
        }

        CountingAvatarResolver::$batches = [];
        $this->client->request('GET', '/tools/task-board/task/' . $crowded->getId() . '/panel');
        self::assertCount(1, CountingAvatarResolver::$batches);
        self::assertSame(0, CountingAvatarResolver::$single);

        // Nobody is resolved for a board the viewer may not see.
        CountingAvatarResolver::$batches = [];
        $this->login($this->user('stranger@example.com'));
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], CountingAvatarResolver::$batches);
    }

    public function testWithoutAPictureTheChipsAreUnchanged(): void
    {
        [$board, $crowded] = $this->crowdedBoard();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertSame('p1@example.com', $crawler->filter('.nowo-task-avatar')->first()->attr('title'));
        self::assertSame('P', $crawler->filter('.nowo-task-avatar')->first()->text());
        self::assertSame('<span class="nowo-task-avatar nowo-task-avatar--more">+2</span>', $this->html($crawler->filter('.nowo-task-avatar--more')));
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list');
        self::assertSame('p1@example.com', $crawler->filter('.nowo-task-avatar')->first()->attr('title'));
        self::assertSame('P', $crawler->filter('.nowo-task-avatar')->first()->text());

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $crowded->getId());
        // Beside a printed name the chip is decorative.
        foreach (['.nowo-task-detail__member-chip-avatar' => 'P', '.nowo-task-members__avatar' => 'P', '.nowo-task-comment .nowo-task-detail__history-avatar' => 'C', '.nowo-task-detail__history-item .nowo-task-detail__history-avatar' => 'H'] as $selector => $initial) {
            self::assertSame($initial, $crawler->filter($selector)->first()->text(), $selector);
            self::assertSame('true', $crawler->filter($selector . ' > [aria-hidden]')->first()->attr('aria-hidden'), $selector);
        }
        self::assertCount(0, $crawler->filter('img.nowo-task-member-avatar__image'));
    }

    public function testAPictureIsDrawnOverTheInitialAndUnsafeUrlsAreDropped(): void
    {
        [$board, $crowded]            = $this->crowdedBoard();
        CountingAvatarResolver::$urls = [
            'p1@example.com' => '/files/p1/s64',
            'p2@example.com' => 'javascript:alert(1)',
            'p3@example.com' => 'https://cdn.example.test/p3.webp',
            'c1@example.com' => '/files/c1/s64',
        ];
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        $first   = $crawler->filter('.nowo-task-avatars')->first()->filter('.nowo-task-avatar');
        self::assertSame('p1@example.com', $first->eq(0)->attr('title'));
        self::assertSame('/files/p1/s64', $first->eq(0)->filter('img')->attr('src'));
        self::assertSame('', $first->eq(0)->filter('img')->attr('alt'));
        self::assertCount(0, $first->eq(1)->filter('img'), 'a rejected URL keeps the initial');
        self::assertSame('P', $first->eq(1)->text());
        self::assertSame('https://cdn.example.test/p3.webp', $first->eq(2)->filter('img')->attr('src'));

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $crowded->getId());
        $comment = $crawler->filter('.nowo-task-comment .nowo-task-detail__history-avatar img');
        self::assertSame('32', $comment->attr('width'));
        self::assertSame('true', $crawler->filter('.nowo-task-comment .nowo-task-detail__history-avatar > [aria-hidden]')->first()->attr('aria-hidden'));
        self::assertDoesNotMatchRegularExpression('/<img[^>]*\s(style|on\w+)=/i', (string) $this->client->getResponse()->getContent());
    }

    public function testTheLabelIsEscaped(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Escaped');
        $evil  = $this->user('"><script>alert(1)</script>@example.com');
        $task->addMember(new TaskMember($task, $evil, TaskMemberRole::Assignee));
        $this->em->flush();
        CountingAvatarResolver::$urls = [$evil->getUserIdentifier() => '/files/evil/s64'];
        $this->login($this->owner);

        $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('"><script>alert(1)</script>', $html);
        self::assertStringContainsString('aria-label="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;@example.com"', $html);
    }

    /**
     * A board with a card of 5 members (and comments and history by other people) and a card of its owner.
     *
     * @return array{0: TaskBoard, 1: Task}
     */
    private function crowdedBoard(): array
    {
        $board   = $this->board();
        $crowded = $this->task($board, 'Crowded', position: 0);
        foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $name) {
            $crowded->addMember(new TaskMember($crowded, $this->user($name . '@example.com'), TaskMemberRole::Assignee));
        }
        foreach (['c1', 'c2'] as $name) {
            $this->em->persist(new TaskComment($crowded, $this->user($name . '@example.com'), 'Comment by ' . $name));
        }
        foreach (['h1', 'h2'] as $name) {
            (new TaskChangeRecorder())->record($crowded, $this->user($name . '@example.com'), TaskChangeType::Title, 'a', 'b');
        }
        $own = $this->task($board, 'Own', position: 1);
        $own->addMember(new TaskMember($own, $this->owner, TaskMemberRole::Assignee));
        $this->em->flush();

        return [$board, $crowded];
    }

    private function html(Crawler $node): string
    {
        // The DOM serializer writes an empty attribute as `alt` (PHP < 8.4) or `alt=""` (PHP 8.4 HTML5 DOM): one form.
        return trim((string) preg_replace(['/\s+/', '/ alt=""/'], [' ', ' alt'], $node->outerHtml()));
    }
}
