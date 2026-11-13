<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Template;

use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use Nowo\TaskBoardBundle\Template\InvalidTaskTemplateException;
use Nowo\TaskBoardBundle\Template\TaskTemplateData;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Template\TaskTemplateSnapshot;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function array_merge;
use function range;
use function str_repeat;
use function strtoupper;
use function trim;

/**
 * Beacon spec 234, FR-061: task templates per board or per tenant — tenant isolation (REQ-SEC-006 look-alike keys),
 * checks, permission, defaults and the data export / replace a configuration preset uses.
 */
final class TaskTemplateManagerTest extends FunctionalTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        // Resource scoping off: anyone manages a tenant's templates (the default permission's fallback).
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['task_templates' => ['enabled' => true], 'security' => ['resource_scoping' => false]];
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'acme');
    }

    public function testABoardOffersItsOwnAndItsTenantsTemplatesOnly(): void
    {
        $sibling   = $this->board('Sibling', tenantKey: 'acme');
        $foreign   = $this->board('Foreign', tenantKey: 'globex');
        $noTenant  = $this->board('Loose');
        $own       = $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Story'));
        $shared    = $this->manager()->createForTenant($this->owner, 'acme', new TaskTemplateData('AI Flow'));
        $ofSibling = $this->manager()->createForBoard($this->owner, $sibling, new TaskTemplateData('Sibling only'));
        $ofGlobex  = $this->manager()->createForTenant($this->owner, 'globex', new TaskTemplateData('Globex'));
        $loose     = $this->manager()->createForTenant($this->owner, null, new TaskTemplateData('Loose'));
        $lookAlike = [];
        foreach (['Acme', 'acme ', "acme\u{200B}", 'ácme', '', str_repeat('k', 192)] as $key) {
            // The look-alike tenants are refused on entry or kept apart.
            try {
                $lookAlike[] = $this->manager()->createForTenant($this->owner, $key, new TaskTemplateData('Look-alike ' . $key));
            } catch (InvalidTaskTemplateException $refused) {
                self::assertSame('tenant_key', $refused->field);
            }
        }
        self::assertCount(2, $lookAlike);

        self::assertSame([$own->getId(), $shared->getId()], $this->ids($this->manager()->usableOn($this->owner, $this->roadmap)));
        self::assertSame([$ofSibling->getId(), $shared->getId()], $this->ids($this->manager()->usableOn($this->owner, $sibling)));
        self::assertSame([$ofGlobex->getId()], $this->ids($this->manager()->usableOn($this->owner, $foreign)));
        self::assertSame([$loose->getId()], $this->ids($this->manager()->usableOn($this->owner, $noTenant)));
        self::assertSame([$shared->getId()], $this->ids($this->manager()->tenantTemplates('acme')));

        foreach (array_merge([$ofSibling, $ofGlobex, $loose], $lookAlike) as $other) {
            self::assertNull($this->manager()->usable($this->owner, $this->roadmap, $other->getId()), $other->getName());
        }
        self::assertSame($own, $this->manager()->usable($this->owner, $this->roadmap, $own->getId()));
        self::assertSame($shared, $this->manager()->usable($this->owner, $this->roadmap, $shared->getId()));
        // REQ-SEC-006: the id is matched exactly.
        self::assertNull($this->manager()->usable($this->owner, $this->roadmap, strtoupper($own->getId())));
        self::assertNull($this->manager()->find(''));
        self::assertNull($this->manager()->usable($this->owner, $this->roadmap, 'missing'));
    }

    public function testTheInputIsChecked(): void
    {
        $other  = $this->board('Other', tenantKey: 'acme');
        $column = $other->getColumns()->first();
        self::assertNotFalse($column);
        $cases = [
            'name'               => [$this->roadmap, new TaskTemplateData('   ')],
            'name '              => [$this->roadmap, new TaskTemplateData(str_repeat('n', TaskTemplate::NAME_MAX + 1))],
            'body'               => [$this->roadmap, new TaskTemplateData('Body', str_repeat('b', TaskTemplate::BODY_MAX + 1))],
            'default_tags'       => [$this->roadmap, new TaskTemplateData('Tags', null, [str_repeat('t', 65)])],
            'default_tags '      => [$this->roadmap, new TaskTemplateData('Tags', null, array_map(static fn (int $i): string => 'tag' . $i, range(1, 21)))],
            'default_column_id'  => [$this->roadmap, new TaskTemplateData('Column', null, [], $column->getId())],
            'default_column_id ' => [null, new TaskTemplateData('Column', null, [], null, 'To do')],
        ];
        foreach ($cases as $field => [$board, $data]) {
            try {
                $board instanceof TaskBoard ? $this->manager()->createForBoard($this->owner, $board, $data) : $this->manager()->createForTenant($this->owner, 'acme', $data);
                self::fail('Accepted: ' . $field);
            } catch (InvalidTaskTemplateException $refused) {
                self::assertSame(trim($field), $refused->field);
            }
        }
        self::assertSame([], $this->em->getRepository(TaskTemplate::class)->findAll());

        $doing    = $this->roadmap->getColumns()->get(1);
        $template = $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('  Story  ', "  \n ", [' repo: ', 'repo:', '', 'capa:'], null, $doing?->getName()));
        self::assertSame('Story', $template->getName());
        self::assertNull($template->getBody());
        self::assertSame(['repo:', 'capa:'], $template->getDefaultTags());
        self::assertSame($doing?->getId(), $template->getDefaultColumnId());
    }

    public function testManagingNeedsThePermission(): void
    {
        self::$taskBoardConfig = ['task_templates' => ['enabled' => true]];
        self::ensureKernelShutdown();
        $this->setUp();
        $stranger = $this->user('stranger@example.com');
        $template = $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Story'));

        foreach ([
            fn (): TaskTemplate => $this->manager()->createForBoard($stranger, $this->roadmap, new TaskTemplateData('Nope')),
            fn () => $this->manager()->update($stranger, $template, new TaskTemplateData('Nope')),
            fn () => $this->manager()->delete($stranger, $template),
            // A tenant's templates: security.manage_roles (nobody is signed in here).
            fn (): TaskTemplate => $this->manager()->createForTenant($this->owner, 'acme', new TaskTemplateData('Nope')),
            fn (): array => $this->manager()->replaceAll($stranger, $this->roadmap, null, []),
        ] as $index => $call) {
            try {
                $call();
                self::fail('Allowed: ' . $index);
            } catch (AccessDeniedException) {
            }
        }
        self::assertFalse($this->manager()->canManage($stranger, $this->roadmap));
        self::assertTrue($this->manager()->canManage($this->owner, $this->roadmap));
        self::assertFalse($this->manager()->canManage($this->owner, null, 'acme'));
        // Using a template needs the right to add tasks to the board.
        self::assertSame([], $this->manager()->usableOn($stranger, $this->roadmap));
        self::assertSame('Story', $template->getName());
    }

    public function testUpdateDeleteAndDefaults(): void
    {
        $done     = $this->roadmap->getColumns()->get(2);
        $template = $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Story', '## Contexto', ['repo:'], $done?->getId()));
        self::assertTrue($template->isBoardTemplate());
        self::assertNull($template->getTenantKey());
        self::assertSame($this->roadmap, $template->getBoard());
        self::assertSame($done, $this->manager()->defaultColumn($template, $this->roadmap));
        self::assertNull($this->manager()->defaultColumn($template, $this->board('Other', tenantKey: 'acme')));

        $data = new TaskFormData(title: 'New', description: 'Typed', columnId: 'kept', tags: ['mine']);
        $this->manager()->prefill($template, $this->roadmap, $data, description: false, tags: false, column: false);
        self::assertSame(['Typed', ['mine'], 'kept'], [$data->description, $data->tags, $data->columnId]);
        $this->manager()->prefill($template, $this->roadmap, $data);
        self::assertSame(['## Contexto', ['repo:'], $done?->getId()], [$data->description, $data->tags, $data->columnId]);

        $this->manager()->update($this->owner, $template, new TaskTemplateData('Story v2', 'Body', []));
        self::assertSame('Story v2', $template->getName());
        self::assertNull($template->getDefaultColumnId());
        self::assertGreaterThanOrEqual($template->getCreatedAt(), $template->getUpdatedAt());
        $data = new TaskFormData(columnId: 'kept');
        $this->manager()->prefill($template, $this->roadmap, $data);
        self::assertSame('kept', $data->columnId);

        // A deleted default column is ignored, never followed.
        $this->manager()->update($this->owner, $template, new TaskTemplateData('Story v2', null, [], $done?->getId()));
        $this->roadmap->getColumns()->removeElement($done);
        self::assertNull($this->manager()->defaultColumn($template, $this->roadmap));

        $id = $template->getId();
        $this->manager()->delete($this->owner, $template);
        $this->em->clear();
        self::assertNull($this->manager()->find($id));
    }

    public function testTheDataOfAScopeIsExportedAndReplaced(): void
    {
        $doing = $this->roadmap->getColumns()->get(1);
        $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Historia', '## Contexto', ['repo:'], $doing?->getId()));
        $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('Tarea', 'Código commit'));
        $exported = $this->manager()->export($this->roadmap);
        self::assertSame(['Historia', 'Tarea'], array_map(static fn (TaskTemplateSnapshot $s): string => $s->name, $exported));
        $first = $exported[0]->toArray();
        self::assertSame(['board', $this->roadmap->getId(), 'acme', '## Contexto', ['repo:'], $doing?->getId(), 'Doing'], [$first['scope'], $first['board_id'], $first['tenant_key'], $first['body'], $first['default_tags'], $first['default_column_id'], $first['default_column_name']]);

        // Carried to another board: the column by name; an existing template is changed in place, others deleted.
        $target = $this->board('Target', tenantKey: 'acme');
        $stale  = $this->manager()->createForBoard($this->owner, $target, new TaskTemplateData('Stale'));
        $kept   = $this->manager()->createForBoard($this->owner, $target, new TaskTemplateData('Historia', 'old'));
        $result = $this->manager()->replaceAll($this->owner, $target, null, array_map(static fn (TaskTemplateSnapshot $s): TaskTemplateData => TaskTemplateData::fromArray($s->toArray()), $exported));
        self::assertSame(['Historia', 'Tarea'], array_map(static fn (TaskTemplate $t): string => $t->getName(), $result));
        self::assertSame($kept->getId(), $result[0]->getId());
        self::assertSame('## Contexto', $result[0]->getBody());
        self::assertSame($target->getColumns()->get(1)?->getId(), $result[0]->getDefaultColumnId());
        $this->em->clear();
        self::assertNull($this->manager()->find($stale->getId()));

        // Tenant scope; duplicates and too many are refused before anything changes.
        $tenant = $this->manager()->replaceAll($this->owner, null, 'acme', [new TaskTemplateData('Shared', 'x')]);
        self::assertSame('acme', $tenant[0]->getTenantKey());
        self::assertSame(['Shared'], array_map(static fn (TaskTemplateSnapshot $s): string => $s->name, $this->manager()->export(null, 'acme')));
        foreach ([
            [new TaskTemplateData('Same'), new TaskTemplateData(' Same ')],
            array_map(static fn (int $i): TaskTemplateData => new TaskTemplateData('T' . $i), range(1, TaskTemplateManager::MAX_PER_SCOPE + 1)),
        ] as $templates) {
            try {
                $this->manager()->replaceAll($this->owner, null, 'acme', $templates);
                self::fail('Accepted');
            } catch (InvalidTaskTemplateException $refused) {
                self::assertSame('name', $refused->field);
            }
        }
        try {
            $this->manager()->replaceAll($this->owner, null, ' acme', []);
            self::fail('Accepted');
        } catch (InvalidTaskTemplateException $refused) {
            self::assertSame('tenant_key', $refused->field);
        }
        self::assertCount(1, $this->manager()->tenantTemplates('acme'));
    }

    public function testAScopeHoldsABoundedNumberOfTemplates(): void
    {
        $this->manager()->replaceAll($this->owner, $this->roadmap, null, array_map(static fn (int $i): TaskTemplateData => new TaskTemplateData('T' . $i), range(1, TaskTemplateManager::MAX_PER_SCOPE)));
        $this->manager()->replaceAll($this->owner, null, 'acme', array_map(static fn (int $i): TaskTemplateData => new TaskTemplateData('T' . $i), range(1, TaskTemplateManager::MAX_PER_SCOPE)));
        foreach ([fn (): TaskTemplate => $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('One more')), fn (): TaskTemplate => $this->manager()->createForTenant($this->owner, 'acme', new TaskTemplateData('One more'))] as $call) {
            try {
                $call();
                self::fail('Accepted');
            } catch (InvalidTaskTemplateException $refused) {
                self::assertSame('name', $refused->field);
            }
        }
        self::assertCount(2 * TaskTemplateManager::MAX_PER_SCOPE, $this->manager()->usableOn($this->owner, $this->roadmap));
    }

    public function testWithoutTheOptionThereIsNoService(): void
    {
        self::$taskBoardConfig = ['calendar' => ['enabled' => false]];
        self::ensureKernelShutdown();
        $this->setUp();

        self::assertFalse(self::getContainer()->has(TaskTemplateManager::class));
    }

    private function manager(): TaskTemplateManager
    {
        $manager = self::getContainer()->get(TaskTemplateManager::class);
        self::assertInstanceOf(TaskTemplateManager::class, $manager);

        return $manager;
    }

    /**
     * @param list<TaskTemplate> $templates
     *
     * @return list<string>
     */
    private function ids(array $templates): array
    {
        return array_map(static fn (TaskTemplate $template): string => $template->getId(), $templates);
    }
}
