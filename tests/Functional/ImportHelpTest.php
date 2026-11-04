<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * Since 1.22.1: the host adds its own import help under the source select — the `import_help` block (board import
 * page) or the escaped `help_link` / `nowo_task_board_import_help_link` link (both import forms).
 */
final class ImportHelpTest extends FunctionalTestCase
{
    public function testTheBoardImportPageHasNoHelpByDefaultAndAHostBlockGoesUnderTheSource(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/import');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-import-help-link"]'));
        self::assertCount(1, $crawler->filter('select[name="task_import_form[source]"]'));
    }

    public function testTheSubtaskDialogRendersAnEscapedHelpLinkUnderTheSource(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Parent');
        $twig  = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $render = static fn (array $context): Crawler => new Crawler($twig->render('@NowoTaskBoardBundle/manage/_task_subtask_import.html.twig', $context + [
            'task'              => $task,
            'subtaskImportForm' => $factory->create(TaskImportFormType::class, new TaskImportFormData())->createView(),
        ]));

        self::assertCount(0, $render([])->filter('[data-testid="nowo-task-import-help-link"]'));

        $crawler = $render(['help_link' => ['url' => '/manual/import?x=1&y=2', 'label' => 'Format <b>and</b> examples']]);
        $link    = $crawler->filter('[data-testid="nowo-task-import-help-link"]');
        self::assertSame('/manual/import?x=1&y=2', $link->attr('href'));
        self::assertSame('Format <b>and</b> examples', $link->text(), 'the label is escaped');
        self::assertSame(['source', 'help', 'file'], self::order($crawler, 'select[name="task_import_form[source]"], [data-testid="nowo-task-import-help-link"], input[name="task_import_form[file]"]'));

        // The Twig global works the same; unsafe URLs become "#"; an incomplete link renders nothing.
        $global = $render(['nowo_task_board_import_help_link' => ['url' => 'https://docs.example.test/import', 'label' => 'Docs']]);
        self::assertSame('https://docs.example.test/import', $global->filter('[data-testid="nowo-task-import-help-link"]')->attr('href'));
        foreach (['javascript:alert(1)', '//evil.test/x', '/\evil.test', "/\t/evil.test", "/\n/evil.test", "/\r/evil.test", '/a b', "https://docs.example.test/\tx"] as $unsafe) {
            self::assertSame('#', $render(['help_link' => ['url' => $unsafe, 'label' => 'x']])->filter('[data-testid="nowo-task-import-help-link"]')->attr('href'), $unsafe);
        }
        self::assertCount(0, $render(['help_link' => ['url' => '/x']])->filter('[data-testid="nowo-task-import-help-link"]'));
        self::assertCount(0, $render(['help_link' => 'not a link'])->filter('[data-testid="nowo-task-import-help-link"]'));
        self::assertInstanceOf(Task::class, $task);
    }

    /**
     * The order of the matched elements in the document, by kind (source, help, file).
     *
     * @return list<string>
     */
    public static function order(Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector)->each(static fn (Crawler $node): string => match (true) {
            $node->nodeName() === 'select' => 'source',
            $node->nodeName() === 'input'  => 'file',
            default                        => 'help',
        });
    }
}
