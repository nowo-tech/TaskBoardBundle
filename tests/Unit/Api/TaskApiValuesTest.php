<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use DateTimeImmutable;
use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiAttachments;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiPlanGate;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiRateLimiter;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiTimeEntries;
use Nowo\TaskBoardBundle\Api\Hook\EmptyTaskApiUsers;
use Nowo\TaskBoardBundle\Api\Hook\EmptyTaskApiWorkspace;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyClaim;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function in_array;

/**
 * Spec 213: the capability table, where, the credential, and that every default denies.
 */
final class TaskApiValuesTest extends TestCase
{
    /**
     * The whole table of the contract: anything not listed is denied.
     *
     * @return iterable<string, array{0: TaskApiLevel, 1: list<TaskApiCapability>}>
     */
    public static function levels(): iterable
    {
        yield 'my tasks' => [TaskApiLevel::MyTasks, [TaskApiCapability::ReadOwn, TaskApiCapability::WorkOnTask, TaskApiCapability::Comment]];
        yield 'read writes nothing, not even its own' => [TaskApiLevel::Read, [TaskApiCapability::ReadOwn, TaskApiCapability::ReadEverything]];
        yield 'tasks' => [TaskApiLevel::Tasks, [TaskApiCapability::ReadOwn, TaskApiCapability::ReadEverything, TaskApiCapability::WorkOnTask, TaskApiCapability::Comment, TaskApiCapability::EditTasks, TaskApiCapability::DeleteTask]];
        yield 'full' => [TaskApiLevel::Full, TaskApiCapability::cases()];
    }

    /**
     * @param list<TaskApiCapability> $allowed
     */
    #[DataProvider('levels')]
    public function testALevelMayExactlyWhatTheTableSays(TaskApiLevel $level, array $allowed): void
    {
        foreach (TaskApiCapability::cases() as $capability) {
            self::assertSame(in_array($capability, $allowed, true), $capability->allows($level), $level->value . ' ' . $capability->name);
        }
    }

    public function testWhereEverythingLeavesOutOnlyWhatTheHostRemoves(): void
    {
        $where = TaskApiWhere::everything(['forbidden']);

        self::assertTrue($where->reachesBoard($this->board('open')));
        self::assertTrue($where->reachesBoard($this->board(null)));
        self::assertFalse($where->reachesBoard($this->board('forbidden')));
        self::assertTrue($where->reachesTenant(null));
        self::assertFalse($where->reachesTenant('forbidden'));
        self::assertTrue($where->touchesTenant('open', []));
        self::assertFalse($where->touchesTenant('forbidden', ['any-board']));
    }

    public function testWhereLimitedReachesOnlyWhatItNames(): void
    {
        $named = $this->board('other');
        $where = TaskApiWhere::limitedTo(['tenant'], [$named->getId()]);

        self::assertTrue($where->reachesBoard($this->board('tenant')));
        self::assertTrue($where->reachesBoard($named));
        self::assertFalse($where->reachesBoard($this->board('other')));
        self::assertFalse($where->reachesBoard($this->board(null)));
        self::assertTrue($where->reachesTenant('tenant'));
        self::assertFalse($where->reachesTenant('other'), 'one board of it does not reach the whole tenant');
        self::assertFalse($where->reachesTenant(null));
        self::assertTrue($where->touchesTenant('tenant', []));
        self::assertTrue($where->touchesTenant('other', ['unrelated', $named->getId()]));
        self::assertFalse($where->touchesTenant('other', ['unrelated']));
        self::assertFalse(TaskApiWhere::limitedTo([], [])->reachesBoard($named));
    }

    public function testTheCredentialIsTheHeaderAndNothingElse(): void
    {
        self::assertSame('bt_x', TaskApiCredential::fromRequest($this->withHeader('bt_x')));
        self::assertSame('bt_x', TaskApiCredential::fromRequest($this->withHeader('Bearer bt_x')));
        self::assertSame('bt_x', TaskApiCredential::fromRequest($this->withHeader('bearer   bt_x ')));
        self::assertNull(TaskApiCredential::fromRequest($this->withHeader('Bearer ')));
        self::assertNull(TaskApiCredential::fromRequest($this->withHeader('')));
        self::assertNull(TaskApiCredential::fromRequest(Request::create('/?token=bt_x&access_token=bt_x&Authorization=bt_x')));
    }

    public function testEveryDefaultDenies(): void
    {
        $principal = new TaskApiPrincipal(new stdClass(), TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name');

        $limiter = new DenyingTaskApiRateLimiter();
        self::assertFalse($limiter->consume($principal)->accepted);
        self::assertFalse($limiter->failedTokenState('127.0.0.1')->accepted);
        $limiter->registerFailedToken('127.0.0.1');

        self::assertFalse((new DenyingTaskApiPlanGate())->allowsTasks(new stdClass(), null));
        self::assertFalse((new DenyingTaskApiPlanGate())->allowsTasks(new stdClass(), 'tenant'));

        $workspace = new EmptyTaskApiWorkspace();
        self::assertSame([], $workspace->teams($principal));
        self::assertSame([], $workspace->spaces($principal, 'team'));
        self::assertNull($workspace->space($principal, 'space'));
        self::assertNull($workspace->spaceOfTenant($principal, 'tenant'));

        $users = new EmptyTaskApiUsers();
        self::assertNull($users->find('id'));
        self::assertSame([], $users->profiles([new stdClass()], $principal));
        $this->assertRefused(404, 'ACCESS_001', static fn () => $users->apiId(new stdClass()));

        $time = new DenyingTaskApiTimeEntries();
        $this->assertRefused(403, 'TIME_001', static fn () => $time->list($principal, new TaskApiTimeQuery('team')));
        $this->assertRefused(403, 'TIME_001', static fn () => $time->log($principal, null, new TaskApiNewTimeEntry('team', new DateTimeImmutable(), 60)));

        $board = new TaskBoard('Board', 'board', null);
        $file  = new UploadedFile(__FILE__, 'a.txt', null, null, true);
        $this->assertRefused(403, 'FILE_001', static fn () => (new DenyingTaskApiAttachments())->store($principal, new Task(board: $board, title: 'T', creator: null), $file));
    }

    public function testTheTimeHookHasNoDelete(): void
    {
        self::assertFalse((new ReflectionClass(TaskApiTimeEntriesInterface::class))->hasMethod('delete'), 'owner: tracked time is never deleted through the API');
    }

    public function testValuesCarryWhatTheyAreGiven(): void
    {
        $start = new DateTimeImmutable('2026-11-07');
        $entry = new TaskApiTimeEntry('id', 'user', $start, 60, 'task', 'note');
        self::assertSame([$entry], (new TaskApiTimePage([$entry]))->entries);
        self::assertSame('https://example.com/f', (new TaskApiAttachment('id', 'f', 'https://example.com/f', 3))->url);
        $claim = new TaskApiIdempotencyClaim(TaskApiIdempotencyOutcome::Completed, 200, '{}', 'tenant', 'board');
        self::assertSame(200, $claim->status);

        $principal = new TaskApiPrincipal(new stdClass(), TaskApiLevel::Tasks, TaskApiWhere::everything(), 'id', 'name');
        $event     = new TaskApiWriteEvent($principal, 'task.update', 'tenant', 'board', 'task', ['other' => 'x'], ['name']);
        self::assertSame(['name'], $event->changedFields);
    }

    public function testAControllerOutsideThePipelineHasNoPrincipal(): void
    {
        $this->expectException(LogicException::class);
        TaskApiRequestListener::principalOf(Request::create('/'));
    }

    private function board(?string $tenantKey): TaskBoard
    {
        $board = new TaskBoard('Board', 'board', null);
        $board->setTenantKey($tenantKey);

        return $board;
    }

    private function withHeader(string $value): Request
    {
        $request = Request::create('/');
        $request->headers->set('Authorization', $value);

        return $request;
    }

    private function assertRefused(int $status, string $ecode, callable $call): void
    {
        try {
            $call();
            self::fail('TaskApiRefusal expected');
        } catch (TaskApiRefusal $refusal) {
            self::assertSame([$status, $ecode], [$refusal->status, $refusal->ecode]);
        }
    }
}
