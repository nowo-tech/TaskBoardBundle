<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Menu\TaskMenu;
use Nowo\TaskBoardBundle\Menu\TaskMenuBuilder;
use Nowo\TaskBoardBundle\Menu\TaskMenuContext;
use Nowo\TaskBoardBundle\Menu\TaskMenuOperation;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Since 2.22.0 (context menus): the menus of the board cards, list rows and column headers.
 *
 * - `nowo_task_board_menu_context(board)`: the page's decisions for the signed-in user, once per page (null: no user);
 * - `nowo_task_board_task_menu(task, context)`: the entries of one task's menu (null without a context);
 * - `nowo_task_board_context_menus()`: `web_ui.context_menus` — true / false forces the right-click, Shift+F10 and long
 *   press menu on or off; null follows UiKit's `theme.context_menus`;
 * - `nowo_task_board_menu_form(action)`: the page's one CSRF-only form (REQ-TWIG-005) the menu entries submit
 *   (`form="nowo-task-menu-form"`); null without the form factory.
 *
 * Stateless: every value is built per call and lives in the template's variables only.
 */
final class TaskMenuTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ?TaskMenuBuilder $menus = null,
        private readonly ?bool $contextMenus = null,
        private readonly ?FormFactoryInterface $formFactory = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_task_board_menu_context', $this->context(...)),
            new TwigFunction('nowo_task_board_task_menu', $this->taskMenu(...)),
            new TwigFunction('nowo_task_board_context_menus', fn (): ?bool => $this->contextMenus),
            new TwigFunction('nowo_task_board_menu_form', $this->menuForm(...)),
        ];
    }

    public function menuForm(string $action): ?FormView
    {
        return $this->formFactory?->createNamed('', CsrfActionType::class, null, [
            'csrf_token_id' => TaskMenuOperation::CSRF_TOKEN_ID,
            'action'        => $action,
        ])->createView();
    }

    public function context(TaskBoard $board): ?TaskMenuContext
    {
        return $this->menus?->context($board);
    }

    public function taskMenu(Task $task, ?TaskMenuContext $context): ?TaskMenu
    {
        return $context instanceof TaskMenuContext && $this->menus instanceof TaskMenuBuilder && $context->board->getId() === $task->getBoard()->getId()
            ? $this->menus->forTask($task, $context)
            : null;
    }
}
