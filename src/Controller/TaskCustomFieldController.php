<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use LogicException;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskCustomFieldDefinitionFormType;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Provenance\Attribute\AsWriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function is_numeric;
use function is_object;
use function is_string;

/**
 * Since 3.3.0 (CU-3): the *Custom fields* page of a board — its native fields (the tenant's, read-only here, then the
 * board's own) and the forms to add, edit and delete the board's own (text, number, dropdown with options).
 *
 * Who may: whoever may see the board sees nothing here unless {@see TaskBoardRestructurePolicyInterface::mayRestructureBoard()}
 * (the board's manage right by default) allows them: 404 for a board they cannot see, 403 otherwise. Every write has
 * its CSRF token; an edit or a delete carries the definition's version (REQ-RUNTIME-003: a definition changed meanwhile
 * is refused with a message, nothing written). A field an import created keeps its provenance marker (shown as a
 * badge); editing it here is allowed (a re-import finds it by the marker, never by its name).
 */
#[AsWriteChannel(WriteChannel::Web)]
final class TaskCustomFieldController extends AbstractController
{
    private const FIELD_ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const DELETE_TOKEN = 'task_board_custom_field_delete';

    /** Since the audit ZCF-L2: *Import it again* of a deleted imported field. */
    private const RESTORE_TOKEN = 'task_board_custom_field_reimport';

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly TaskBoardRepositoryInterface $boardRepository,
        private readonly TaskCustomFieldManager $fields,
        private readonly TaskBoardRestructurePolicyInterface $policy,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/tools/task-board/board/{boardId}/fields', name: 'nowo_task_board_board_fields', methods: ['GET'])]
    public function fields(string $boardId): Response
    {
        $board  = $this->board($boardId);
        $fields = $this->fields->fieldsFor($board);
        $own    = [];
        foreach ($fields as $field) {
            if ($field->getBoardId() === $board->getId()) {
                $own[$field->getId()] = $this->formFactory()->createNamedBuilder('', CsrfActionType::class, null, [
                    'csrf_token_id' => self::DELETE_TOKEN,
                    'action'        => $this->generateUrl($this->routes['board_field_delete']['name'], ['boardId' => $board->getId(), 'fieldId' => $field->getId()]),
                ])->add('version', HiddenType::class, ['data' => (string) $field->getVersion()])->getForm()->createView();
            }
        }

        // Since the audit ZCF-L2: the imported fields deleted here, each with its *Import it again* form.
        $deleted = [];
        foreach ($this->fields->tombstones($board) as $tombstone) {
            $deleted[] = ['tombstone' => $tombstone, 'form' => $this->formFactory()->createNamedBuilder('', CsrfActionType::class, null, [
                'csrf_token_id' => self::RESTORE_TOKEN,
                'action'        => $this->generateUrl($this->routes['board_field_reimport']['name'], ['boardId' => $board->getId(), 'tombstoneId' => $tombstone->getId()]),
            ])->add('tombstone', HiddenType::class, ['data' => $tombstone->getId()])->getForm()->createView()];
        }

        return $this->render($this->templates['board_fields'], [
            'page'        => $this->showPage($board),
            'board'       => $board,
            'fields'      => $fields,
            'deleteForms' => $own,
            'deleted'     => $deleted,
        ]);
    }

    #[Route('/tools/task-board/board/{boardId}/fields/new', name: 'nowo_task_board_board_field_new', methods: ['GET', 'POST'])]
    public function create(string $boardId, Request $request): Response
    {
        $board = $this->board($boardId);
        $form  = $this->form($board, null);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $type = CustomFieldType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '') ?? CustomFieldType::Text;
            try {
                $field = $this->fields->define($this->user(), $board, null, $this->text($data['name'] ?? null), $type, $type === CustomFieldType::Dropdown ? TaskCustomFieldDefinitionFormType::options($data, null) : []);
                $this->log('custom_field_create', $board, $field);
                $this->addFlash('success', $this->translator->trans('task_board.custom_field.flash.created', [], 'NowoTaskBoardBundle'));

                return $this->toList($board);
            } catch (TaskCustomFieldRefusedException $exception) {
                $form->addError(new FormError($this->refusal($exception)));
            }
        }

        return $this->renderForm($board, null, $form);
    }

    #[Route('/tools/task-board/board/{boardId}/fields/{fieldId}', name: 'nowo_task_board_board_field_edit', requirements: ['fieldId' => self::FIELD_ID], methods: ['GET', 'POST'])]
    public function edit(string $boardId, string $fieldId, Request $request): Response
    {
        $board = $this->board($boardId);
        $field = $this->ownField($board, $fieldId);
        $form  = $this->form($board, $field);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data    = $form->getData();
            $version = $data['version'] ?? null;
            try {
                $this->fields->update($this->user(), $field, $this->text($data['name'] ?? null), $field->getType() === CustomFieldType::Dropdown ? TaskCustomFieldDefinitionFormType::options($data, $field) : null, $field->getPosition(), is_numeric($version) ? (int) $version : -1);
                $this->log('custom_field_update', $board, $field);
                $this->addFlash('success', $this->translator->trans('task_board.custom_field.flash.saved', [], 'NowoTaskBoardBundle'));

                return $this->toList($board);
            } catch (TaskCustomFieldRefusedException $exception) {
                $form->addError(new FormError($this->refusal($exception)));
            }
        }

        return $this->renderForm($board, $field, $form);
    }

    #[Route('/tools/task-board/board/{boardId}/fields/{fieldId}/delete', name: 'nowo_task_board_board_field_delete', requirements: ['fieldId' => self::FIELD_ID], methods: ['POST'])]
    public function delete(string $boardId, string $fieldId, Request $request): RedirectResponse
    {
        $board = $this->board($boardId);
        $field = $this->ownField($board, $fieldId);
        $form  = $this->formFactory()->createNamedBuilder('', CsrfActionType::class, null, ['csrf_token_id' => self::DELETE_TOKEN])
            ->add('version', HiddenType::class, ['required' => false])
            ->getForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $version = $form->get('version')->getData();
        try {
            $this->fields->delete($this->user(), $field, is_numeric($version) ? (int) $version : -1);
            $this->log('custom_field_delete', $board, $field);
            $this->addFlash('success', $this->translator->trans('task_board.custom_field.flash.deleted', [], 'NowoTaskBoardBundle'));
        } catch (TaskCustomFieldRefusedException $exception) {
            $this->addFlash('error', $this->refusal($exception));
        }

        return $this->toList($board);
    }

    /**
     * Since the audit ZCF-L2: lets a re-import create a deleted imported field again (the tombstone goes).
     */
    #[Route('/tools/task-board/board/{boardId}/fields/deleted/{tombstoneId}/reimport', name: 'nowo_task_board_board_field_reimport', requirements: ['tombstoneId' => self::FIELD_ID], methods: ['POST'])]
    public function reimport(string $boardId, string $tombstoneId, Request $request): RedirectResponse
    {
        $board = $this->board($boardId);
        // An unnamed form is submitted when one of its fields is in the request: the tombstone id is that field.
        $form = $this->formFactory()->createNamedBuilder('', CsrfActionType::class, null, ['csrf_token_id' => self::RESTORE_TOKEN])
            ->add('tombstone', HiddenType::class, ['required' => false])
            ->getForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            // @igor-ignore - A call on the stateless field manager (it deletes a database row), not a change of the service.
            $this->fields->clearTombstone($this->user(), $board, $tombstoneId);
            $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => 'custom_field_reimport', 'board_id' => $board->getId(), 'tombstone_id' => $tombstoneId]);
            $this->addFlash('success', $this->translator->trans('task_board.custom_field.flash.reimport', [], 'NowoTaskBoardBundle'));
        } catch (TaskCustomFieldRefusedException $exception) {
            $this->addFlash('error', $this->refusal($exception));
        }

        return $this->toList($board);
    }

    /**
     * @return FormInterface<array<string, mixed>|null>
     */
    private function form(TaskBoard $board, ?TaskCustomField $field): FormInterface
    {
        return $this->formFactory()->createNamed('task_board_custom_field', TaskCustomFieldDefinitionFormType::class, null, [
            'field'  => $field,
            'action' => $field instanceof TaskCustomField
                ? $this->generateUrl($this->routes['board_field_edit']['name'], ['boardId' => $board->getId(), 'fieldId' => $field->getId()])
                : $this->generateUrl($this->routes['board_field_new']['name'], ['boardId' => $board->getId()]),
        ]);
    }

    /**
     * @param FormInterface<array<string, mixed>|null> $form
     */
    private function renderForm(TaskBoard $board, ?TaskCustomField $field, FormInterface $form): Response
    {
        $view = $form->createView();
        $twig = $this->container->get('twig');
        $list = $this->generateUrl($this->routes['board_fields']['name'], ['boardId' => $board->getId()]);

        return $this->render($this->templates['board_field'], [
            'page' => new FormPage(
                new PageHeader(
                    TaskBoardPages::label($field instanceof TaskCustomField ? 'task_board.custom_field.edit_title' : 'task_board.custom_field.new_title'),
                    $field?->getName() ?? TaskBoardTwigExtension::boardLabel($board),
                    [new Action(label: TaskBoardPages::label('task_board.custom_field.back'), href: $list)],
                    [
                        new Crumb(TaskBoardTwigExtension::boardLabel($board), $this->generateUrl($this->routes['board']['name'], ['boardId' => $board->getId()])),
                        new Crumb(TaskBoardPages::label('task_board.custom_field.title'), $list),
                    ],
                    breadcrumbsMode: 'own',
                ),
                $view,
                TaskBoardPages::label('task_board.action.save'),
                bundle: TaskBoardPages::BUNDLE,
                layout: $twig instanceof Environment ? $this->pages->layout($twig) : null,
            ),
            'board'     => $board,
            'field'     => $field,
            'fieldForm' => $view,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function showPage(TaskBoard $board): ShowPage
    {
        $twig = $this->container->get('twig');

        return new ShowPage(
            new PageHeader(
                TaskBoardPages::label('task_board.custom_field.title'),
                TaskBoardTwigExtension::boardLabel($board),
                [
                    new Action(label: TaskBoardPages::label('task_board.custom_field.add'), href: $this->generateUrl($this->routes['board_field_new']['name'], ['boardId' => $board->getId()]), attributes: ['data-testid' => 'nowo-task-field-add']),
                    Action::route('custom', $this->routes['board']['name'], ['boardId' => $board->getId()], TaskBoardPages::label('task_board.view.back_board')),
                ],
                [new Crumb(TaskBoardTwigExtension::boardLabel($board), $this->generateUrl($this->routes['board']['name'], ['boardId' => $board->getId()]))],
                breadcrumbsMode: 'own',
            ),
            bundle: TaskBoardPages::BUNDLE,
            layout: $twig instanceof Environment ? $this->pages->layout($twig) : null,
        );
    }

    /** The board the user may restructure: 404 when they cannot see it (or another tenant's), 403 without the right. */
    private function board(string $boardId): TaskBoard
    {
        $user  = $this->user();
        $board = $this->boardRepository->findById($boardId);
        if (!$board instanceof TaskBoard || $this->tenantContext?->owns($board, $user) === false || !$this->resourceAccessChecker->canViewBoard($user, $board)) {
            throw new NotFoundHttpException('Board not found.');
        }
        if (!$this->policy->mayRestructureBoard($user, Snapshots::board($board))) {
            throw $this->createAccessDeniedException('Custom field management denied.');
        }

        return $board;
    }

    /** One of the board's own fields (a tenant field is not edited from a board): 404 otherwise. */
    private function ownField(TaskBoard $board, string $fieldId): TaskCustomField
    {
        $field = $this->fields->fieldOfBoard($board, $fieldId);
        if (!$field instanceof TaskCustomField || $field->getBoardId() !== $board->getId()) {
            throw new NotFoundHttpException('Field not found.');
        }

        return $field;
    }

    private function formFactory(): FormFactoryInterface
    {
        $factory = $this->container->get('form.factory');
        if (!$factory instanceof FormFactoryInterface) {
            throw new LogicException('The form factory is missing.'); // @codeCoverageIgnore
        }

        return $factory;
    }

    private function user(): UserInterface
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function refusal(TaskCustomFieldRefusedException $exception): string
    {
        return $this->translator->trans('task_board.custom_field.refusal.' . $exception->reason, [], 'NowoTaskBoardBundle');
    }

    private function toList(TaskBoard $board): RedirectResponse
    {
        return $this->redirectToRoute($this->routes['board_fields']['name'], ['boardId' => $board->getId()]);
    }

    private function log(string $action, TaskBoard $board, TaskCustomField $field): void
    {
        $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => $action, 'board_id' => $board->getId(), 'field_id' => $field->getId()]);
    }
}
