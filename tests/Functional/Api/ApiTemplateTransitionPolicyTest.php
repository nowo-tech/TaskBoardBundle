<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Template\TaskTemplateData;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\RecordingTransitionPolicy;

use function array_keys;
use function array_map;

/**
 * Beacon spec 234, where FR-014 (the column transition policy) meets FR-061 (task templates): a template's default
 * column reaches `TaskManager::create()` through the same `TaskFormData::columnId` an explicit `status` uses, so the
 * policy decides on it too. A template pointing at a *humans only* column is refused for an `api` / `mcp` caller exactly
 * as that status would be — same 403, same code, same message — and nothing is written.
 */
final class ApiTemplateTransitionPolicyTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        self::$hostConfig = ['task_templates' => ['enabled' => true], 'column_transition_policy' => 'app.transition_policy'];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', ['To do', 'Doing', 'Approved']);
        $this->roadmap->setTenantKey('tenant-a');
        $this->column('Approved')->setHumansOnly(true);
        $this->em->flush();
        $this->issueToken();
        ApiTestHost::$tokens['bt_mcp'] = ['channel' => 'mcp'] + ApiTestHost::$tokens[self::TOKEN];
    }

    public function testATemplateWhoseColumnIsHumansOnlyIsRefusedLikeThatStatus(): void
    {
        $approved = $this->templates()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Aprobada', 'Listo para aprobar', ['repo:core'], $this->column('Approved')->getId()));
        $path     = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';

        // The explicit status, for the shape to compare against.
        $this->send('POST', $path, ['name' => 'By status', 'status' => 'Approved'], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);
        $byStatus = $this->json();

        // The same column, reached through the template instead: the same refusal.
        $this->send('POST', $path, ['name' => 'By template', 'template_id' => $approved->getId()], [], 'bt_mcp');
        self::assertResponseStatusCodeSame(403);
        self::assertSame($byStatus, $this->json());
        self::assertSame(['err', 'ECODE'], array_keys($this->json()));
        self::assertSame('STATUS_HUMANS_ONLY', $this->json()['ECODE']);

        // The policy saw the template's column and its tags, as a creation.
        $asked = RecordingTransitionPolicy::$asked;
        self::assertCount(2, $asked);
        self::assertSame(['Approved', 'create', 'mcp', true], [$asked[1]->to->name, $asked[1]->via->operation, $asked[1]->via->channel, $asked[1]->isCreation()]);
        self::assertSame(['repo:core'], $asked[1]->tags, 'the template\'s tags, as the task would have them');

        // Nothing was written by either attempt.
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Task::class)->findBy(['title' => ['By status', 'By template']]));
    }

    public function testAHostRuleRefusesATemplatesColumnWithItsOwnCodeAndMessage(): void
    {
        RecordingTransitionPolicy::$wipFull = ['Doing'];
        $doing                              = $this->templates()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('En curso', null, [], $this->column('Doing')->getId()));

        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'By template', 'template_id' => $doing->getId()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['err' => 'Only one task per repository in Doing', 'ECODE' => 'WIP_LIMIT'], $this->json());
    }

    public function testTheBodysStatusStillWinsOverTheTemplatesColumn(): void
    {
        // `status` in the body is what the policy is asked about: the template's column is not applied at all.
        $approved = $this->templates()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Aprobada', 'Listo', [], $this->column('Approved')->getId()));

        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Allowed', 'status' => 'Doing', 'template_id' => $approved->getId()], [], 'bt_mcp');

        self::assertResponseIsSuccessful();
        self::assertSame('Doing', $this->json()['status']['status']);
        self::assertSame('Listo', $this->json()['description'], 'the template still filled the description');
        self::assertSame(['Doing'], array_map(static fn ($transition): string => $transition->to->name, RecordingTransitionPolicy::$asked));
    }

    private function templates(): TaskTemplateManager
    {
        $manager = self::getContainer()->get(TaskTemplateManager::class);
        self::assertInstanceOf(TaskTemplateManager::class, $manager);

        return $manager;
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
