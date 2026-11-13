<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiTemplateOperations;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use Nowo\TaskBoardBundle\Template\TaskTemplateData;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Template\TaskTemplateSnapshot;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

use function array_column;
use function array_map;
use function strtoupper;

/**
 * Beacon spec 234, FR-061: `GET /v2/list/{id}/taskTemplate` and `template_id` on `POST /v2/list/{id}/task` — tenant and
 * board isolation (the same 400 for every template the board does not offer) and explicit fields over the template.
 */
final class ApiTaskTemplateTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private TaskTemplate $story;

    private TaskTemplate $shared;

    /** @var array<string, TaskTemplate> */
    private array $foreign = [];

    protected function setUp(): void
    {
        static::$hostConfig = static::$hostConfig !== [] ? static::$hostConfig : ['task_templates' => ['enabled' => true]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->issueToken();
        if (!self::getContainer()->has(TaskTemplateManager::class)) {
            return;
        }
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
        $sibling       = $this->board('Sibling', tenantKey: 'tenant-a');
        $doing         = $this->roadmap->getColumns()->get(1);
        self::assertNotNull($doing);
        $this->story   = $this->templates()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Historia', "## Contexto\n\n- [ ] uno", ['repo:', 'capa:'], $doing->getId()));
        $this->shared  = $this->templates()->createForTenant($this->owner, 'tenant-a', new TaskTemplateData('Tarea', 'Código commit'));
        $this->foreign = [
            'other board'  => $this->templates()->createForBoard($this->owner, $sibling, new TaskTemplateData('Sibling')),
            'other tenant' => $this->templates()->createForTenant($this->owner, 'tenant-b', new TaskTemplateData('B')),
            'look-alike'   => $this->templates()->createForTenant($this->owner, 'Tenant-a', new TaskTemplateData('Look-alike')),
            'no tenant'    => $this->templates()->createForTenant($this->owner, null, new TaskTemplateData('Loose')),
        ];
    }

    public function testTheListHasTheBoardsAndTheTenantsTemplatesOnly(): void
    {
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/taskTemplate');
        self::assertResponseIsSuccessful();
        $templates = $this->listOf('templates');

        self::assertSame([$this->story->getId(), $this->shared->getId()], array_column($templates, 'id'));
        self::assertSame(['board', 'tenant'], array_column($templates, 'scope'));
        self::assertSame("## Contexto\n\n- [ ] uno", $templates[0]['description']);
        self::assertSame(['repo:', 'capa:'], array_column($templates[0]['tags'], 'name'));
        self::assertSame('Doing', $templates[0]['status']['status'] ?? null);
        self::assertNull($templates[1]['status']);

        // The same list for an in-process caller (the host's MCP template_list).
        $operations = self::getContainer()->get(TaskApiTemplateOperations::class);
        self::assertInstanceOf(TaskApiTemplateOperations::class, $operations);
        $snapshots = $operations->snapshots(new TaskApiPrincipal($this->owner, TaskApiLevel::Full, TaskApiWhere::everything(), 'mcp', 'MCP', TaskApiPrincipal::CHANNEL_MCP), $this->roadmap->getId());
        self::assertSame(['Historia', 'Tarea'], array_map(static fn (TaskTemplateSnapshot $s): string => $s->name, $snapshots));
        self::assertSame('Doing', $snapshots[0]->defaultColumnName);

        // A read-only token cannot create tasks, so it gets no templates.
        $this->issueToken(TaskApiLevel::Read, token: 'bt_read');
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/taskTemplate', 'bt_read');
        self::assertResponseStatusCodeSame(403);
        $this->get('/api/tasks/v2/list/missing/taskTemplate');
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testATaskFromATemplate(): void
    {
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Story', 'template_id' => $this->story->getId()]);
        self::assertResponseIsSuccessful();
        $task = $this->created('Story');
        self::assertSame("## Contexto\n\n- [ ] uno", $task->getDescription());
        self::assertSame(['capa:', 'repo:'], $task->getTags());
        self::assertSame('Doing', $task->getColumn()?->getName());
        self::assertArrayNotHasKey('warnings', $this->json());
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);
        self::assertSame($this->story->getId(), $audit->events[0]->targetIds['template'] ?? null);

        // Without template_id nothing is pre-filled.
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Plain']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->created('Plain')->getTags());

        // A tenant template has no column: the board's first.
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Shared', 'template_id' => $this->shared->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('To do', $this->created('Shared')->getColumn()?->getName());
    }

    public function testExplicitFieldsWinOverTheTemplate(): void
    {
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Mine', 'template_id' => $this->story->getId(), 'markdown_content' => 'Own text', 'tags' => ['own'], 'status' => 'done']);
        self::assertResponseIsSuccessful();
        $task = $this->created('Mine');
        self::assertSame('Own text', $task->getDescription());
        self::assertSame(['own'], $task->getTags());
        self::assertSame('Done', $task->getColumn()?->getName());

        // An explicit empty list of tags or an empty description wins too.
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Empty', 'template_id' => $this->story->getId(), 'description' => '', 'tags' => []]);
        self::assertResponseIsSuccessful();
        $task = $this->created('Empty');
        self::assertSame('', (string) $task->getDescription());
        self::assertSame([], $task->getTags());
        self::assertSame('Doing', $task->getColumn()?->getName());
    }

    public function testATemplateTheBoardDoesNotOfferIsTheSame400(): void
    {
        $ids = array_map(static fn (TaskTemplate $template): string => $template->getId(), $this->foreign) + ['upper case' => strtoupper($this->story->getId()), 'unknown' => 'missing', 'empty' => ''];
        foreach ($ids as $case => $id) {
            $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Leak ' . $case, 'template_id' => $id]);
            $this->assertRefused(400, 'INPUT_002');
            self::assertSame('Invalid template_id', $this->json()['err'], $case);
        }
        self::assertSame([], $this->em->getRepository(Task::class)->findAll());
    }

    public function testWithoutTheOptionTheFieldIsUnknownAndTheRouteMissing(): void
    {
        static::$hostConfig = ['calendar' => ['enabled' => false]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board('Plain', tenantKey: 'tenant-a');

        $this->send('POST', '/api/tasks/v2/list/' . $board->getId() . '/task', ['name' => 'Plain', 'template_id' => 'whatever']);
        self::assertResponseIsSuccessful();
        self::assertSame([['field' => 'template_id', 'reason' => 'unknown']], $this->json()['warnings']);
        $this->get('/api/tasks/v2/list/' . $board->getId() . '/taskTemplate');
        self::assertResponseStatusCodeSame(404);
    }

    private function created(string $title): Task
    {
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }

    private function templates(): TaskTemplateManager
    {
        $manager = self::getContainer()->get(TaskTemplateManager::class);
        self::assertInstanceOf(TaskTemplateManager::class, $manager);

        return $manager;
    }
}
