<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Template;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Template\TaskTemplateData;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Beacon spec 234, FR-061: the Template picker of the add-task dialog pre-fills the description, the tags and the column
 * (all still editable) from a template the board offers; any other id is ignored.
 */
final class TaskTemplatePickerTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['task_templates' => ['enabled' => true]];
        parent::setUp();
        $this->roadmap = $this->board('Roadmap');
    }

    public function testThePickerPreFillsTheDialog(): void
    {
        $doing    = $this->roadmap->getColumns()->get(1);
        $template = $this->manager()->createForBoard($this->owner, $this->roadmap, new TaskTemplateData('AI Flow · Historia', 'Versión propuesta: 1', ['repo:'], $doing?->getId()));
        $foreign  = $this->manager()->createForBoard($this->owner, $this->board('Other'), new TaskTemplateData('Foreign', 'Secret body'));
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . '/board/' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-template-picker"] option[value="' . $template->getId() . '"]');
        self::assertSelectorNotExists('[data-nowo-task-board-add-task-modal-open-on-connect-value]');

        $this->client->request('GET', self::BASE . '/board/' . $this->roadmap->getId(), ['taskTemplate' => $template->getId(), 'taskTitle' => '  Nueva historia ', 'view' => 'board']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-nowo-task-board-add-task-modal-open-on-connect-value="true"]');
        self::assertSelectorExists('[data-testid="nowo-task-template-picker"] option[value="' . $template->getId() . '"][selected]');
        $form = $this->client->getCrawler()->filter('#nowo-task-board-add-task-form');
        self::assertSame('Nueva historia', $form->filter('input[name="task_form[title]"]')->attr('value'));
        self::assertStringContainsString('Versión propuesta: 1', $form->html());
        self::assertStringContainsString('repo:', $form->html());
        self::assertSame($doing?->getId(), $form->filter('select[name="task_form[columnId]"] option[selected]')->attr('value'));

        // Another board's template is ignored, as if none were chosen.
        $this->client->request('GET', self::BASE . '/board/' . $this->roadmap->getId(), ['taskTemplate' => $foreign->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-nowo-task-board-add-task-modal-open-on-connect-value]');
        self::assertStringNotContainsString('Secret body', (string) $this->client->getResponse()->getContent());
    }

    public function testNoPickerWithoutTemplatesOrTheOption(): void
    {
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . '/board/' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-template-picker"]');

        self::$taskBoardConfig = ['calendar' => ['enabled' => false]];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . '/board/' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-template-picker"]');
    }

    private function manager(): TaskTemplateManager
    {
        $manager = self::getContainer()->get(TaskTemplateManager::class);
        self::assertInstanceOf(TaskTemplateManager::class, $manager);

        return $manager;
    }
}
