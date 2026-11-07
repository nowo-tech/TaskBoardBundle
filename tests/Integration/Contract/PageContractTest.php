<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\Contract;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

use function dirname;

/**
 * Since 2.0.0: REQ-UI-003 for the pages on a kit scaffold whose contract needs no database: the index and the import page. The
 * task and connection pages run with real services in the functional suite, the unique pages are declared in docs/TEMPLATES.md.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $header = static fn (): PageHeader => new PageHeader(
            TaskBoardPages::label('task_board.index.title'),
            TaskBoardPages::label('task_board.index.subtitle'),
            [new Action(kind: 'create', label: TaskBoardPages::label('task_board.index.new_board'), dialogId: TaskBoardPages::BOARD_CREATE_DIALOG)],
        );
        $columns = [new Column('name', TaskBoardPages::label('task_board.index.your_boards')), new Column('views', TaskBoardPages::label('task_board.index.views'))];
        $board   = new TaskBoard('Roadmap', 'roadmap', new TestUser('1', 'owner'));

        yield 'index' => new PageFixture('@NowoTaskBoardBundle/manage/index.html.twig', [
            'page'           => new ListPage($header(), $columns, [$board], bundle: TaskBoardPages::BUNDLE),
            'boards'         => [$board],
            'canCreateBoard' => false,
        ]);
        yield 'index (empty)' => new PageFixture('@NowoTaskBoardBundle/manage/index.html.twig', [
            'page'           => new ListPage($header(), $columns, [], empty: new EmptyState(TaskBoardPages::label('task_board.index.no_boards')), bundle: TaskBoardPages::BUNDLE),
            'boards'         => [],
            'canCreateBoard' => false,
        ]);

        $form = self::importFormView();
        yield 'import' => new PageFixture('@NowoTaskBoardBundle/manage/import.html.twig', [
            'page'       => new FormPage(new PageHeader(TaskBoardPages::label('task_board.import.title'), breadcrumbs: [new Crumb('Roadmap', '/board')], breadcrumbsMode: 'own'), $form, TaskBoardPages::label('task_board.import.form.submit'), bundle: TaskBoardPages::BUNDLE),
            'board'      => $board,
            'importForm' => $form,
        ]);
    }

    /** A form without FormKit: the page only needs its view. */
    private static function importFormView(): FormView
    {
        $profile = ['convention_requires_translation' => true, 'translation_domain' => 'NowoTaskBoardBundle', 'defaults' => ['attr' => [], 'row_attr' => []], 'field_types' => []];
        $type    = new TaskImportFormType();
        $type->setFormOptionsMerger(new FormOptionsMerger(['default' => $profile, 'task_board' => $profile], 'default', new ConstraintDefinitionFactory(), translator: new Translator('en')));

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType($type)
            ->getFormFactory()
            ->create(TaskImportFormType::class, new TaskImportFormData(), ['action' => '/import'])
            ->createView();
    }

    protected static function templatePaths(): array
    {
        return ['NowoTaskBoardBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    /** The FormKit convention looks these up; the form fields need none. */
    protected static function ignoredMissingTranslations(): array
    {
        return ['/^NowoTaskBoardBundle:task_import_form\./'];
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addExtension(new TaskBoardTwigExtension());
    }
}
