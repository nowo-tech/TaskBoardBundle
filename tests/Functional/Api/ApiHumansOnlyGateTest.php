<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Connection;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\RecordingTransitionPolicy;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;

use function array_map;
use function array_reverse;
use function array_values;
use function in_array;
use function str_starts_with;

/**
 * Audit PC (TaskBoard 2.16.0): the *humans only* gate cannot be lifted by an `api` / `mcp` caller through the column
 * endpoints (PC-M1), a refusal decided while a bulk update writes is still `BULK_001` (PC-L1) and a tag change of a task
 * that stays in its column is asked to the column transition policy (PC-L2). Each probe of the audit is a test here.
 */
final class ApiHumansOnlyGateTest extends ApiTestCase
{
    /** The exact answer of a status the bundled humans-only rule refuses. */
    private const HUMANS_ONLY = ['err' => 'Only people can move tasks into this status', 'ECODE' => 'STATUS_HUMANS_ONLY'];

    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        self::$hostConfig = ['column_transition_policy' => 'app.transition_policy'];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', ['To do', 'Doing', 'Approved', 'Done'], tenantKey: 'tenant-a');
        $this->column('Approved')->setHumansOnly(true);
        $this->column('Done')->setHumansOnly(true);
        $this->em->flush();
        $this->issueToken();
        ApiTestHost::$tokens['bt_mcp'] = ['channel' => TaskApiPrincipal::CHANNEL_MCP] + ApiTestHost::$tokens[self::TOKEN];
        // A host's own channel label: the bundled rule does not treat it as a machine.
        ApiTestHost::$tokens['bt_cli'] = ['channel' => 'cli'] + ApiTestHost::$tokens[self::TOKEN];
    }

    /** Probe A: clearing the flag, moving in and setting it back. */
    public function testAMachineCannotChangeTheHumansOnlyFlag(): void
    {
        $task = $this->task($this->roadmap, 'Self approve', 1);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'approved'], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);
        $statusRefusal = $this->json();
        self::assertSame(self::HUMANS_ONLY, $statusRefusal);

        foreach ([self::TOKEN, 'bt_mcp'] as $token) {
            $this->send('PUT', $this->statusPath('Approved'), ['humans_only' => false], [], $token);
            self::assertResponseStatusCodeSame(403);
            // Byte for byte the answer of the status path.
            self::assertSame($statusRefusal, $this->json());
            $this->send('PUT', $this->statusPath('Doing'), ['humans_only' => true], [], $token);
            self::assertSame(self::HUMANS_ONLY, $this->json());
        }
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'approved'], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);

        // Its current value changes nothing and is accepted, with the other fields.
        $this->send('PUT', $this->statusPath('Approved'), ['humans_only' => true, 'color' => '#00aa00'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        $this->send('PUT', $this->statusPath('Doing'), ['status' => 'In progress'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        // A channel the bundled rule does not refuse may still change it.
        $this->send('PUT', $this->statusPath('In progress'), ['humans_only' => true], [], 'bt_cli');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $task->getId());
        self::assertSame('In progress', $reloaded?->getColumn()?->getName());
        self::assertTrue($this->em->find(BoardColumn::class, $this->columnId('Approved'))?->isHumansOnly());
        self::assertSame('#00aa00', $this->em->find(BoardColumn::class, $this->columnId('Approved'))?->getColor());
    }

    /** Probe B: deleting a column whose tasks would land in a humans-only one. */
    public function testDeletingAColumnAsksThePolicyForEveryTaskItMoves(): void
    {
        $task = $this->task($this->roadmap, 'In To do', 0);
        $this->column('Doing')->setHumansOnly(true);
        $this->em->flush();
        // The request loads the board and its tasks as a new process would.
        $this->em->clear();

        $this->send('DELETE', $this->statusPath('To do'), null, [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(self::HUMANS_ONLY, $this->json());
        self::assertCount(1, RecordingTransitionPolicy::$asked);
        $asked = RecordingTransitionPolicy::$asked[0];
        self::assertSame(['mcp', 'column_delete', 'To do', 'Doing', $task->getId()], [$asked->via->channel, $asked->via->operation, $asked->from?->name, $asked->to->name, $asked->task?->id]);
        self::assertFalse($asked->to->last);

        $this->em->clear();
        self::assertNotNull($this->em->find(BoardColumn::class, $this->columnId('To do')));
        self::assertSame('To do', $this->em->find(Task::class, $task->getId())?->getColumn()?->getName());
    }

    /** All or none: one refused task keeps the column and every task where they are. */
    public function testOneRefusedTaskKeepsTheColumnAndEveryTask(): void
    {
        $first  = $this->task($this->roadmap, 'First', 1);
        $second = $this->task($this->roadmap, 'Second', 1, position: 1);
        $this->task($this->roadmap, 'Elsewhere', 0);
        $this->em->clear();
        RecordingTransitionPolicy::$decider = static fn (ColumnTransition $transition): ?ColumnTransitionDecision => $transition->task?->title === 'Second'
            ? ColumnTransitionDecision::refuse('WIP_LIMIT', 'To do is full')
            : null;

        $this->send('DELETE', $this->statusPath('Doing'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['err' => 'To do is full', 'ECODE' => 'WIP_LIMIT'], $this->json());
        self::assertSame(['api'], array_values(array_unique(array_map(static fn (ColumnTransition $transition): string => $transition->via->channel, RecordingTransitionPolicy::$asked))));
        $this->em->clear();
        self::assertNotNull($this->em->find(BoardColumn::class, $this->columnId('Doing')));
        foreach ([$first, $second] as $task) {
            self::assertSame('Doing', $this->em->find(Task::class, $task->getId())?->getColumn()?->getName());
        }

        // Allowed: every task moves and the policy saw each one.
        RecordingTransitionPolicy::reset();
        $this->send('DELETE', $this->statusPath('Doing'), null, [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        self::assertSame(['First', 'Second'], array_map(static fn (ColumnTransition $transition): ?string => $transition->task?->title, RecordingTransitionPolicy::$asked));
        $this->em->clear();
        self::assertSame('To do', $this->em->find(Task::class, $second->getId())?->getColumn()?->getName());
    }

    /**
     * Audit PG-L2 (2.17.0): a column deletion asks the policy and moves the tasks in the column's order (position, then
     * id), not in the order the board's collection happens to load them: the first refusal reported and the order of the
     * moved cards are the same on every database.
     */
    public function testAColumnDeletionAsksAndMovesTheTasksInTheColumnsOrder(): void
    {
        // Created in the reverse of their order in the column.
        $third  = $this->task($this->roadmap, 'Third', 1, position: 2);
        $second = $this->task($this->roadmap, 'Second', 1, position: 1);
        $first  = $this->task($this->roadmap, 'First', 1, position: 0);
        $this->task($this->roadmap, 'Already there', 0, position: 0);
        $this->em->clear();
        RecordingTransitionPolicy::$decider = static fn (ColumnTransition $transition): ?ColumnTransitionDecision => $transition->task?->title !== 'First'
            ? ColumnTransitionDecision::refuse('WIP_LIMIT', $transition->task?->title . ' refused')
            : null;

        $this->send('DELETE', $this->statusPath('Doing'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['err' => 'Second refused', 'ECODE' => 'WIP_LIMIT'], $this->json());
        self::assertSame(['First', 'Second'], array_map(static fn (ColumnTransition $transition): ?string => $transition->task?->title, RecordingTransitionPolicy::$asked));

        RecordingTransitionPolicy::reset();
        $this->send('DELETE', $this->statusPath('Doing'));
        self::assertResponseIsSuccessful();
        self::assertSame(['First', 'Second', 'Third'], array_map(static fn (ColumnTransition $transition): ?string => $transition->task?->title, RecordingTransitionPolicy::$asked));
        $this->em->clear();
        $positions = [];
        foreach ([$first, $second, $third] as $task) {
            $moved = $this->em->find(Task::class, $task->getId());
            self::assertSame('To do', $moved?->getColumn()?->getName());
            $positions[$moved->getTitle()] = $moved->getPosition();
        }
        self::assertSame(['First' => 1, 'Second' => 2, 'Third' => 3], $positions);
    }

    /** A machine may not delete a flagged column: it lifts the gate as clearing the flag would. */
    public function testAMachineCannotDeleteAHumansOnlyColumn(): void
    {
        $this->send('DELETE', $this->statusPath('Approved'), null, [], 'bt_mcp');
        self::assertSame(self::HUMANS_ONLY, $this->json());
        self::assertResponseStatusCodeSame(403);
        $this->send('DELETE', $this->statusPath('Approved'), null, [], 'bt_cli');
        self::assertResponseIsSuccessful();
    }

    /** Probe C: the completion follows the last column; a flagged last column stays last. */
    public function testAMachineCannotMoveAHumansOnlyLastColumnAwayFromTheEnd(): void
    {
        $order = [$this->columnId('To do'), $this->columnId('Doing'), $this->columnId('Done'), $this->columnId('Approved')];
        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status/order', ['order' => $order], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(self::HUMANS_ONLY, $this->json());

        // Adding a column puts it last: refused too, and so is a new flagged column.
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status', ['status' => 'Shipped'], [], 'bt_mcp');
        self::assertSame(self::HUMANS_ONLY, $this->json());
        $this->em->clear();
        self::assertSame(4, $this->em->find(TaskBoard::class, $this->roadmap->getId())?->getColumns()->count());

        // Keeping the flagged column last is allowed.
        $order = [$this->columnId('Doing'), $this->columnId('To do'), $this->columnId('Approved'), $this->columnId('Done')];
        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status/order', ['order' => $order], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
    }

    public function testAMachineMayAddAPlainColumnAfterAPlainLastOne(): void
    {
        $board = $this->board('Plain', ['To do', 'Done'], tenantKey: 'tenant-a');
        $this->send('POST', '/api/tasks/v2/list/' . $board->getId() . '/status', ['status' => 'Gate', 'humans_only' => true], [], 'bt_mcp');
        self::assertSame(self::HUMANS_ONLY, $this->json());
        $this->send('POST', '/api/tasks/v2/list/' . $board->getId() . '/status', ['status' => 'Shipped', 'humans_only' => false], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/list/' . $board->getId() . '/status', ['status' => 'Gate', 'humans_only' => true], [], 'bt_cli');
        self::assertResponseIsSuccessful();
    }

    /**
     * Since 2.18.0 (FR-033): on a typed board the done columns are its done and closed ones, whatever their order; a
     * machine may not change them while one is flagged (a new done or closed column, a retype into or out of them).
     */
    public function testOnATypedBoardAMachineCannotChangeFlaggedDoneColumns(): void
    {
        $board = $this->board('Typed', ['To do', 'Doing', 'Done'], tenantKey: 'tenant-a');
        $types = [ColumnType::Open, ColumnType::Custom, ColumnType::Done];
        foreach (array_values($board->getColumns()->toArray()) as $index => $column) {
            $column->setType($types[$index]);
            $column->setHumansOnly($index === 2);
        }
        $this->em->flush();
        $path = '/api/tasks/v2/list/' . $board->getId() . '/status';
        $ids  = array_map(static fn (BoardColumn $column): string => $column->getId(), array_values($board->getColumns()->toArray()));

        $this->send('POST', $path, ['status' => 'Review', 'type' => 'custom'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        $this->send('POST', $path, ['status' => 'Cancelled', 'type' => 'closed'], [], 'bt_mcp');
        self::assertSame(self::HUMANS_ONLY, $this->json());
        $this->send('PUT', $path . '/' . $ids[1], ['type' => 'done'], [], 'bt_mcp');
        self::assertSame(self::HUMANS_ONLY, $this->json());
        $this->send('PUT', $path . '/' . $ids[2], ['type' => 'custom'], [], 'bt_mcp');
        self::assertSame(self::HUMANS_ONLY, $this->json());
        $this->send('PUT', $path . '/' . $ids[1], ['status' => 'Doing now'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $order = array_map(static fn (BoardColumn $column): string => $column->getId(), array_reverse(array_values($fresh->getColumns()->toArray())));
        $this->send('PUT', $path . '/order', ['order' => $order], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        $this->send('PUT', $path . '/' . $ids[1], ['type' => 'done'], [], 'bt_cli');
        self::assertResponseIsSuccessful();
    }

    /** Probe C of the audit (PC-L1): a stateful host rule refusing the second item once the first is written. */
    public function testARefusalWhileABulkUpdateWritesIsBulk001AndRollsBackEverything(): void
    {
        $connection = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        RecordingTransitionPolicy::$decider = static function (ColumnTransition $transition) use ($connection): ?ColumnTransitionDecision {
            if ($transition->to->name !== 'Doing') {
                return null;
            }
            $others = (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_tasks WHERE column_id = ? AND id <> ?', [$transition->to->id, $transition->task?->id ?? '']);

            return $others >= 1 ? ColumnTransitionDecision::refuse('WIP_LIMIT', 'WIP 1') : null;
        };
        $a = $this->task($this->roadmap, 'A');
        $b = $this->task($this->roadmap, 'B', position: 1);

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [
            ['id' => $a->getId(), 'status' => 'doing', 'description' => 'a'],
            ['id' => $b->getId(), 'status' => 'doing', 'description' => 'b'],
        ]], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(409);
        self::assertSame([
            'err'      => 'Bulk update refused: nothing was changed',
            'ECODE'    => 'BULK_001',
            'failures' => [['index' => 1, 'id' => $b->getId(), 'status' => 403, 'ECODE' => 'WIP_LIMIT', 'err' => 'WIP 1']],
        ], $this->json());
        $this->em->clear();
        foreach ([$a, $b] as $task) {
            $reloaded = $this->em->find(Task::class, $task->getId());
            self::assertSame(['To do', null, 1], [$reloaded?->getColumn()?->getName(), $reloaded?->getDescription(), $reloaded?->getRevision()]);
        }
    }

    /** Probe D (PC-L2): a tag added after the move is asked, as operation `retag`. */
    public function testATagChangeOfATaskInAColumnIsAskedToThePolicy(): void
    {
        RecordingTransitionPolicy::$decider = static fn (ColumnTransition $transition): ?ColumnTransitionDecision => $transition->to->name === 'Doing' && in_array('repo:core', $transition->tags, true)
            ? ColumnTransitionDecision::refuse('WIP_LIMIT', 'One task per repository in Doing')
            : null;
        $task     = $this->task($this->roadmap, 'No tag', 0);
        $approved = $this->task($this->roadmap, 'Approved one', 2);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'doing'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/tag/repo:core', null, [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['err' => 'One task per repository in Doing', 'ECODE' => 'WIP_LIMIT'], $this->json());
        $asked = RecordingTransitionPolicy::$asked[1];
        self::assertSame(['mcp', 'retag', 'Doing', 'Doing', ['repo:core']], [$asked->via->channel, $asked->via->operation, $asked->from?->name, $asked->to->name, $asked->tags]);

        // The same through a bulk item that only sets tags: BULK_001, nothing written.
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $task->getId(), 'tags' => ['repo:core']]]], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('WIP_LIMIT', $this->json()['failures'][0]['ECODE']);
        $this->em->clear();
        self::assertSame([], $this->em->find(Task::class, $task->getId())?->getTags());

        // Another tag is allowed; a retag in a humans-only column is not an entry.
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/tag/repo:docs', null, [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/task/' . $approved->getId() . '/tag/reviewed', null, [], 'bt_mcp');
        self::assertResponseIsSuccessful();
    }

    /** PC-L2 through the service: `addTag()` asks too, as `web` unless the caller says otherwise. */
    public function testAddTagAsksThePolicy(): void
    {
        RecordingTransitionPolicy::$decider = static fn (ColumnTransition $transition): ?ColumnTransitionDecision => in_array('blocked', $transition->tags, true)
            ? ColumnTransitionDecision::refuse('BLOCK_REASON_REQUIRED')
            : null;
        $task  = $this->task($this->roadmap, 'Tagged by a person', 1);
        $tasks = self::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $tasks);
        self::assertTrue($tasks->addTag($task, 'fine', $this->owner));
        try {
            $tasks->addTag($task, 'blocked', $this->owner, new ColumnTransitionVia(ColumnTransitionVia::MCP));
            self::fail('Refused');
        } catch (ColumnTransitionRefusedException $refused) {
            self::assertSame('BLOCK_REASON_REQUIRED', $refused->decision->code);
        }
        self::assertSame(['fine'], $task->getTags());
        self::assertSame([['web', 'retag'], ['mcp', 'retag']], array_map(static fn (ColumnTransition $transition): array => [$transition->via->channel, $transition->via->operation], RecordingTransitionPolicy::$asked));
        // A task without a column has nothing to ask.
        $loose = new Task(board: $this->roadmap, title: 'Loose', creator: $this->owner);
        $this->em->persist($loose);
        $this->em->flush();
        self::assertTrue($tasks->addTag($loose, 'blocked', $this->owner));
    }

    /** PC-L2: a checklist rename retags every item, all or none. */
    public function testARefusedChecklistRenameChangesNoItem(): void
    {
        $task = $this->task($this->roadmap, 'With steps', 1);
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/checklist', ['name' => 'Steps']);
        $checklist = $this->json()['checklist']['id'];
        foreach (['One', 'Two'] as $name) {
            $this->send('POST', '/api/tasks/v2/checklist/' . $checklist . '/checklist_item', ['name' => $name], [], 'bt_mcp');
            self::assertResponseIsSuccessful();
        }
        $refusedOnce                        = false;
        RecordingTransitionPolicy::$decider = static function (ColumnTransition $transition) use (&$refusedOnce): ?ColumnTransitionDecision {
            if ($transition->via->operation !== ColumnTransitionVia::OP_RETAG || $refusedOnce || $transition->task?->title !== 'Two') {
                return null;
            }
            $refusedOnce = true;

            return ColumnTransitionDecision::refuse('WIP_LIMIT', 'No');
        };
        $this->send('PUT', '/api/tasks/v2/checklist/' . $checklist, ['name' => 'Renamed'], [], 'bt_mcp');
        $this->assertRefused(403, 'WIP_LIMIT');
        $this->em->clear();
        $items = $this->em->getRepository(Task::class)->findBy(['parent' => $task->getId()]);
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame(['checklist:Steps'], array_values(array_filter($item->getTags(), static fn (string $tag): bool => str_starts_with($tag, 'checklist:'))));
        }
        $this->send('PUT', '/api/tasks/v2/checklist/' . $checklist, ['name' => 'Renamed'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
    }

    private function statusPath(string $column): string
    {
        return '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status/' . $this->columnId($column);
    }

    private function columnId(string $name): string
    {
        $board = $this->em->find(TaskBoard::class, $this->roadmap->getId()) ?? $this->roadmap;
        foreach ($board->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column->getId();
            }
        }
        self::fail('No column ' . $name);
    }

    private function column(string $name): BoardColumn
    {
        foreach ($this->roadmap->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }
        self::fail('No column ' . $name);
    }
}
