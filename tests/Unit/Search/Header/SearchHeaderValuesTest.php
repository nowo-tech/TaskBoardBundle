<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search\Header;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Search\Header\SearchableBoard;
use Nowo\TaskBoardBundle\Search\Header\SearchableBoards;
use Nowo\TaskBoardBundle\Search\Header\SearchHit;
use Nowo\TaskBoardBundle\Search\Header\SearchHitMapper;
use Nowo\TaskBoardBundle\Search\Header\SearchHits;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;
use Nowo\TaskBoardBundle\Search\Header\SearchViewers;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SearchHeaderValuesTest extends TestCase
{
    public function testValueObjects(): void
    {
        $board = new TaskBoard('Board', 'board', new stdClass());
        $set   = new SearchableBoards([$board->getId() => new SearchableBoard($board)], true);

        self::assertFalse($set->isEmpty());
        self::assertTrue($set->has($board->getId()));
        self::assertFalse($set->has('other'));
        self::assertSame([$board->getId()], $set->ids());
        self::assertTrue($set->capped);
        self::assertTrue((new SearchableBoards([]))->isEmpty());
        self::assertTrue((new SearchHits())->isEmpty());
        $viewer = new SearchViewer($board);
        self::assertFalse($viewer->manageRoles);
        self::assertNull($viewer->scope);
    }

    public function testViewersAreOnlyTheSignedInUser(): void
    {
        $user   = new TestUser('1', 'me@example.com');
        $tokens = new TokenStorage();

        self::assertNull((new SearchViewers())->forIdentifier('me@example.com'));
        self::assertNull((new SearchViewers($tokens))->forIdentifier('me@example.com'));
        $tokens->setToken(new UsernamePasswordToken($user, 'main', []));
        self::assertNull((new SearchViewers($tokens))->forIdentifier('someone@example.com'));
        self::assertNull((new SearchViewers($tokens))->forIdentifier(''));
        $viewer = (new SearchViewers($tokens))->forIdentifier('me@example.com', 'scope');
        self::assertNotNull($viewer);
        self::assertSame($user, $viewer->user);
        self::assertTrue($viewer->manageRoles);
        self::assertSame('scope', $viewer->scope);
    }

    public function testMapsTasksAndBoardsAsPlainRows(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/' . $name . '?' . http_build_query($parameters));
        $board = new TaskBoard("Board\u{202E}", 'board', new stdClass());
        $board->setDescription('<b>About</b> it');
        $task = new Task($board, "Fix\nlogin", new stdClass());
        $task->setDescription('Users cannot **login**');
        $tenant = new TaskBoardTenant('acme', 'acme-slug', 'Acme Inc');

        $mapper = new SearchHitMapper($urls, ['task' => ['name' => 'task_route'], 'board' => ['name' => 'board_route']]);
        $hit    = $mapper->task($task, $tenant, ['login']);
        self::assertSame(SearchHit::KIND_TASK, $hit->kind);
        self::assertSame($task->getId(), $hit->id);
        self::assertSame('Fix login', $hit->title);
        self::assertSame('/task_route?taskId=' . $task->getId() . '&tenantSlug=acme-slug', $hit->url);
        self::assertSame('Board · Acme Inc', $hit->subtitle);
        self::assertSame('Users cannot login', $hit->excerpt);
        self::assertSame($board->getId(), $hit->boardId);
        self::assertEquals($task->getUpdatedAt(), $hit->updatedAt);

        $row = $mapper->board($board, $tenant);
        self::assertSame(SearchHit::KIND_BOARD, $row->kind);
        self::assertSame('Board', $row->title);
        self::assertSame('Acme Inc', $row->subtitle);
        self::assertSame('About it', $row->excerpt);
        self::assertNull($row->boardId);

        $default = new SearchHitMapper($urls);
        self::assertSame('/nowo_task_board_task?taskId=' . $task->getId(), $default->task($task, null, [])->url);
        self::assertSame('Board', $default->task($task, null, [])->subtitle);
        self::assertSame('/nowo_task_board_board?boardId=' . $board->getId(), $default->board($board, null)->url);
        self::assertSame('', $default->board($board, null)->subtitle);
    }
}
