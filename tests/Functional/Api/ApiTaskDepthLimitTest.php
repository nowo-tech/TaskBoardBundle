<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

/**
 * Beacon spec 234, T004 (TaskBoard 2.20.0): `max_subtask_depth: 2` (a task has at most 2 ancestors). Every Task API write
 * that would put a task deeper answers 409 `PARENT_005` and writes nothing; a tree saved deeper before the limit stays
 * readable, editable and movable up.
 */
final class ApiTaskDepthLimitTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        self::$hostConfig = ['max_subtask_depth' => 2];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testASubtaskPastTheLimitIsRefused(): void
    {
        [$root, $level1, $level2] = $this->chain(3);
        $before                   = $this->taskCount();

        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Too deep', 'parent' => $level2->getId()]);
        $this->assertRefused(409, 'PARENT_005');
        self::assertSame($before, $this->taskCount());

        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Deepest allowed', 'parent' => $level1->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame($level1->getId(), $this->json()['parent']);
        self::assertSame($root->getId(), $level1->getParent()?->getId());
    }

    public function testAChecklistItemPastTheLimitIsRefused(): void
    {
        [, , $level2] = $this->chain(3);
        $this->send('POST', '/api/tasks/v2/task/' . $level2->getId() . '/checklist', ['name' => 'Steps']);
        self::assertResponseIsSuccessful();
        $checklist = (string) $this->json()['checklist']['id'];
        $before    = $this->taskCount();

        $this->send('POST', '/api/tasks/v2/checklist/' . $checklist . '/checklist_item', ['name' => 'Item']);

        $this->assertRefused(409, 'PARENT_005');
        self::assertSame($before, $this->taskCount());
    }

    public function testAMoveCountsTheMovedSubtree(): void
    {
        [$root, $level1] = $this->chain(2);
        $subtree         = $this->task($this->roadmap, 'Subtree');
        $this->task($this->roadmap, 'Its child', parent: $subtree);

        $this->send('PUT', '/api/tasks/v2/task/' . $subtree->getId(), ['parent' => $level1->getId(), 'name' => 'Renamed']);
        $this->assertRefused(409, 'PARENT_005');
        self::assertNull($this->parentInDatabase($subtree));
        self::assertSame('Subtree', $this->em->getConnection()->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$subtree->getId()]));

        $this->send('PUT', '/api/tasks/v2/task/' . $subtree->getId(), ['parent' => $root->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame($root->getId(), $this->parentInDatabase($subtree));
    }

    public function testATreeSavedDeeperStaysEditableAndMovableUp(): void
    {
        [$root, $level1, , $level3, $level4] = $this->chain(5); // saved before the limit: levels 3 and 4 are past it

        $this->send('PUT', '/api/tasks/v2/task/' . $level4->getId(), ['name' => 'Still editable']);
        self::assertResponseIsSuccessful();
        $this->get('/api/tasks/v2/task/' . $level4->getId());
        self::assertSame('Still editable', $this->json()['name']);

        // Up from depth 4 to depth 2 (still past nothing), and a subtree past the limit moved up but not out of it.
        $this->send('PUT', '/api/tasks/v2/task/' . $level4->getId(), ['parent' => $level1->getId()]);
        self::assertResponseIsSuccessful();
        $this->send('PUT', '/api/tasks/v2/task/' . $level3->getId(), ['parent' => $root->getId()]);
        self::assertResponseIsSuccessful();
        $this->send('PUT', '/api/tasks/v2/task/' . $level3->getId(), ['parent' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->parentInDatabase($level3));
    }

    /**
     * @return list<Task> a chain from the top level down
     */
    private function chain(int $length): array
    {
        $chain  = [];
        $parent = null;
        for ($i = 0; $i < $length; ++$i) {
            $parent  = $this->task($this->roadmap, 'Level ' . $i, parent: $parent);
            $chain[] = $parent;
        }

        return $chain;
    }

    private function taskCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_tasks');
    }

    private function parentInDatabase(Task $task): ?string
    {
        $parent = $this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$task->getId()]);

        return $parent === null || $parent === false ? null : (string) $parent;
    }
}
