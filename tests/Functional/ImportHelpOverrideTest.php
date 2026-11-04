<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

/**
 * Since 1.22.1: a host page for the board import (templates.import) that extends the bundle's page and fills the
 * `import_help` block only.
 */
final class ImportHelpOverrideTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['templates' => ['import' => 'import_with_help.html.twig']];
        parent::setUp();
    }

    public function testAHostTemplateOverridesTheBlock(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/import');
        self::assertResponseIsSuccessful();
        $help = $crawler->filter('[data-testid="host-import-help"]');
        self::assertSame('Format and examples', $help->text());
        self::assertSame(['source', 'help', 'file'], ImportHelpTest::order($crawler, 'select[name="task_import_form[source]"], [data-testid="host-import-help"], input[name="task_import_form[file]"]'));
    }
}
