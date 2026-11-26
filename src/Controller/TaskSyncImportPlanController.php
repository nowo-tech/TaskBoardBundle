<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\ImportPlanStepFormType;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanPreviewer;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanRunnerInterface;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanService;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStep;
use Nowo\TaskBoardBundle\Sync\Plan\PlanRunRequest;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_object;
use function is_string;
use function json_encode;
use function preg_match;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Since 3.2.0 (CU-2, design §8): the guided import from ClickUp — connect → workspace → tree → boards → statuses →
 * fields → people → preview → run → summary. Every step is a full page without JavaScript (POST + redirect), with its
 * own CSRF token and the plan's version; the plan row holds the wizard's state. Only users who may import plans in the
 * tenant (`import_plan`, deny by default) reach these pages; everyone else gets 404, as for a plan of another tenant.
 * The progress endpoint is a read-only GET (counters only, `no-store`); with the inline runner it advances the plan.
 */
final class TaskSyncImportPlanController extends AbstractController
{
    public const PROVIDER = 'clickup';

    private const PLAN_ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** How many plans the list page shows. */
    private const LISTED = 50;

    /** How many levels a page keeps open in the tree. */
    private const MAX_OPEN = 20;

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly ImportPlanService $plans,
        private readonly ImportPlanPreviewer $previewer,
        private readonly ImportPlanStore $store,
        private readonly ImportPlanRunnerInterface $runner,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?string $dashboardRoute = null,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly ?TaskSyncPersonRepository $peopleRepository = null,
        private readonly bool $timeSink = false,
        private readonly int $pollInterval = 2000,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
    ) {
    }

    #[Route('/tools/task-board/import-plans', name: 'nowo_task_board_import_plans', methods: ['GET'])]
    public function plans(): Response
    {
        [$user, $tenant] = $this->requireImporter();

        return $this->render($this->templates['import_plans'], [
            'page'      => $this->page('task_board.import_plan.list.title', null),
            'plans'     => $this->store->forTenant($tenant?->key, self::LISTED),
            'newForm'   => $this->csrfForm('task_board_import_plan_new')->createView(),
            'templates' => $this->templates,
            'user'      => $user,
        ]);
    }

    #[Route('/tools/task-board/import-plans/new', name: 'nowo_task_board_import_plan_new', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        [$user, $tenant] = $this->requireImporter();
        $this->denyUnlessValidCsrf($request, 'task_board_import_plan_new');
        $plan = $this->plans->draft($tenant?->key, $user, self::PROVIDER);
        $this->logWrite('import_plan_new', ['plan_id' => $plan->getId()]);

        return $this->toStep($plan, ImportPlanStep::Connect);
    }

    #[Route('/tools/task-board/import-plans/{planId}', name: 'nowo_task_board_import_plan', requirements: ['planId' => self::PLAN_ID], methods: ['GET'])]
    public function show(string $planId, Request $request): Response
    {
        [$user, $tenant] = $this->requireImporter();
        $plan            = $this->findPlan($planId, $tenant);
        $step            = $this->viewStep($plan, (string) $request->query->get('step', ''));

        return $this->renderStep($plan, $step, $user, null, []);
    }

    #[Route('/tools/task-board/import-plans/{planId}/step/{step}', name: 'nowo_task_board_import_plan_step', requirements: ['planId' => self::PLAN_ID, 'step' => ImportPlanStep::REQUIREMENT], methods: ['POST'])]
    public function save(string $planId, string $step, Request $request): Response
    {
        [$user, $tenant] = $this->requireImporter();
        $plan            = $this->findPlan($planId, $tenant);
        $current         = ImportPlanStep::from($step);
        if ($plan->getStatus() !== ImportPlanStatus::Draft || $current->position() > $plan->getStep()->position()) {
            throw new NotFoundHttpException('Step not found.');
        }
        $posted = $request->request->all(ImportPlanStepFormType::NAME);
        $opened = $this->opened($posted);
        $open   = $request->request->get('open');
        if ($current === ImportPlanStep::Tree && is_string($open) && preg_match(ImportPlanService::CONTAINER_ID, $open) === 1) {
            // Without script, a folder or space opens by a POST answered with the page (no provider call on a GET).
            $this->denyUnlessValidCsrf($request, 'task_board_import_plan_tree', $posted);
            $opened = array_values(array_unique([...$opened, $open]));
            // The boxes ticked so far stay ticked on the page with the new level.
            $ticked = array_values(array_filter((array) ($posted['containers'] ?? []), static fn (mixed $id): bool => is_string($id) && preg_match(ImportPlanService::CONTAINER_ID, $id) === 1));

            return $this->renderStep($plan, $current, $user, null, array_slice($opened, 0, self::MAX_OPEN), Response::HTTP_OK, $ticked);
        }
        try {
            $form = $this->stepForm($plan, $current, $user, $opened);
        } catch (ImportPlanException $exception) {
            return $this->refused($plan, $current, $exception);
        }
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if (!$form->isSubmitted() || $form->getErrors()->count() > 0) {
                $this->logRefused('csrf_invalid');

                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            return $this->renderStep($plan, $current, $user, $form, $opened, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var array<string, mixed> $data */
        $data    = $form->getData();
        $version = (int) $data['version'];
        try {
            $this->apply($plan, $current, $version, $data, $user);
        } catch (ImportPlanException $exception) {
            return $this->refused($plan, $current, $exception);
        }
        $this->logWrite('import_plan_' . $current->value, ['plan_id' => $planId]);

        return $this->toStep($plan, $current === ImportPlanStep::Preview ? ImportPlanStep::Run : $current->next());
    }

    /** *Re-import now* (every item, idempotent) or *Retry failed lists* of a finished plan. */
    #[Route('/tools/task-board/import-plans/{planId}/start', name: 'nowo_task_board_import_plan_start', requirements: ['planId' => self::PLAN_ID], methods: ['POST'])]
    public function start(string $planId, Request $request): RedirectResponse
    {
        [$user, $tenant] = $this->requireImporter();
        $plan            = $this->findPlan($planId, $tenant);
        $form            = $this->container->get('form.factory')->createNamed(ImportPlanStepFormType::NAME, ImportPlanStepFormType::class, null, ['step' => 'preview', 'version' => $plan->getVersion(), 'csrf_token_id' => 'task_board_import_plan_start']);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->logRefused('csrf_invalid');

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        /** @var array{version: string, mode: string} $data */
        $data = $form->getData();
        try {
            $this->plans->start($plan, (int) $data['version'], $user, ReimportMode::from($data['mode']), $request->request->get('retry') === '1');
        } catch (ImportPlanException $exception) {
            $this->flashRefusal($exception);
        }
        $this->logWrite('import_plan_start', ['plan_id' => $planId]);

        return $this->toStep($plan, ImportPlanStep::Run);
    }

    /**
     * Progress of a plan as JSON (counters only). With the inline runner a due plan advances by one slice per poll.
     */
    #[Route('/tools/task-board/import-plans/{planId}/progress', name: 'nowo_task_board_import_plan_progress', requirements: ['planId' => self::PLAN_ID], methods: ['GET'])]
    public function progress(string $planId): JsonResponse
    {
        [$user, $tenant] = $this->requireImporter();
        $plan            = $this->findPlan($planId, $tenant);
        if ($this->runner->advancesOnPoll() && $plan->getStatus()->isActive()) {
            // The slice runs as the plan's starter, never as the one polling (audit ZBY-M1).
            $this->runner->dispatch(new PlanRunRequest($planId));
            $plan = $this->findPlan($planId, $tenant);
        }
        $view     = $this->progressView($plan);
        $response = new JsonResponse([
            'planId'    => $planId,
            'status'    => $plan->getStatus()->value,
            'terminal'  => !$plan->getStatus()->isActive(),
            'processed' => $view['processed'],
            'total'     => $view['total'],
            'summary'   => $view['summary'],
            'items'     => $view['items'],
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/tools/task-board/import-plans/{planId}/cancel', name: 'nowo_task_board_import_plan_cancel', requirements: ['planId' => self::PLAN_ID], methods: ['POST'])]
    public function cancel(string $planId, Request $request): RedirectResponse
    {
        [, $tenant] = $this->requireImporter();
        $plan       = $this->findPlan($planId, $tenant);
        $this->denyUnlessValidCsrf($request, 'task_board_import_plan_cancel');
        $this->plans->cancel($plan);
        $this->logWrite('import_plan_cancel', ['plan_id' => $planId]);
        $this->addFlash('success', $this->translator->trans('task_board.import_plan.flash.cancelled', [], 'NowoTaskBoardBundle'));

        return $this->toStep($plan, ImportPlanStep::Run);
    }

    #[Route('/tools/task-board/import-plans/{planId}/resume', name: 'nowo_task_board_import_plan_resume', requirements: ['planId' => self::PLAN_ID], methods: ['POST'])]
    public function resume(string $planId, Request $request): RedirectResponse
    {
        [, $tenant] = $this->requireImporter();
        $plan       = $this->findPlan($planId, $tenant);
        $this->denyUnlessValidCsrf($request, 'task_board_import_plan_resume');
        $this->plans->resume($plan);
        $this->logWrite('import_plan_resume', ['plan_id' => $planId]);

        return $this->toStep($plan, ImportPlanStep::Run);
    }

    #[Route('/tools/task-board/import-plans/{planId}/delete', name: 'nowo_task_board_import_plan_delete', requirements: ['planId' => self::PLAN_ID], methods: ['POST'])]
    public function delete(string $planId, Request $request): RedirectResponse
    {
        [, $tenant] = $this->requireImporter();
        $plan       = $this->findPlan($planId, $tenant);
        $this->denyUnlessValidCsrf($request, 'task_board_import_plan_delete');
        try {
            $this->plans->delete($plan);
        } catch (ImportPlanException $exception) {
            $this->flashRefusal($exception);

            return $this->toStep($plan, ImportPlanStep::Run);
        }
        $this->logWrite('import_plan_delete', ['plan_id' => $planId]);
        $this->addFlash('success', $this->translator->trans('task_board.import_plan.flash.deleted', [], 'NowoTaskBoardBundle'));

        return $this->redirectToRoute($this->routes['import_plans']['name']);
    }

    /** *Download report (JSON)*: counts and ids only (design §8.9). */
    #[Route('/tools/task-board/import-plans/{planId}/report', name: 'nowo_task_board_import_plan_report', requirements: ['planId' => self::PLAN_ID], methods: ['GET'])]
    public function report(string $planId): Response
    {
        [, $tenant] = $this->requireImporter();
        $plan       = $this->findPlan($planId, $tenant);
        $items      = [];
        foreach ($this->store->items($plan) as $item) {
            $items[] = ['itemId' => $item->getId(), 'sourceType' => $item->getSourceType(), 'sourceId' => $item->getSourceId(), 'boardId' => $item->getBoardId(), 'status' => $item->getStatus()->value, 'errorCode' => $item->getErrorCode(), 'counts' => $item->getReport()];
        }
        $body = json_encode([
            'planId'      => $plan->getId(),
            'provider'    => $plan->getProvider(),
            'workspaceId' => $plan->getWorkspaceId(),
            'status'      => $plan->getStatus()->value,
            'errorCode'   => $plan->getErrorCode(),
            'startedAt'   => $plan->getStartedAt()?->format(DATE_ATOM),
            'finishedAt'  => $plan->getFinishedAt()?->format(DATE_ATOM),
            'counts'      => $plan->getReport(),
            'items'       => $items,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = new Response($body, Response::HTTP_OK, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Content-Disposition' => 'attachment; filename="import-plan-' . $plan->getId() . '.json"']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function apply(TaskSyncImportPlan $plan, ImportPlanStep $step, int $version, array $data, UserInterface $user): void
    {
        switch ($step) {
            case ImportPlanStep::Connect:
                $this->plans->chooseConnection($plan, $version, $this->connection($plan, $user, (string) $data['connection']));
                break;
            case ImportPlanStep::Workspace:
                $this->plans->chooseWorkspace($plan, $version, (string) $data['workspace'], $user);
                break;
            case ImportPlanStep::Tree:
                $options = [];
                foreach ([TaskSyncImportPlan::OPTION_COMMENTS, TaskSyncImportPlan::OPTION_ATTACHMENTS, TaskSyncImportPlan::OPTION_CHECKLISTS, TaskSyncImportPlan::OPTION_TIME, TaskSyncImportPlan::OPTION_UNMATCHED] as $name) {
                    if (array_key_exists($name, $data)) {
                        $options[$name] = $data[$name] === true;
                    }
                }
                /** @var list<string> $containers */
                $containers = $data['containers'];
                $this->plans->chooseSources($plan, $version, $containers, ImportPlanLayout::from((string) $data['layout']), $options + [TaskSyncImportPlan::OPTION_PEOPLE => $plan->option(TaskSyncImportPlan::OPTION_PEOPLE, false)]);
                break;
            case ImportPlanStep::Boards:
                $choices = [];
                foreach ($this->store->items($plan) as $item) {
                    $choices[$item->getId()] = ['target' => (string) $data['t_' . $item->getId()], 'name' => (string) $data['n_' . $item->getId()]];
                }
                $this->plans->chooseBoards($plan, $version, $choices, $user);
                break;
            case ImportPlanStep::Statuses:
                $choices = [];
                foreach ($this->plans->statusGroups($plan) as $group) {
                    foreach ($group['statuses'] as $index => $status) {
                        $choices[$group['key']][$index] = (string) $data['s_' . $group['key'] . '_' . $index];
                    }
                }
                $this->plans->mapStatuses($plan, $version, $choices);
                break;
            case ImportPlanStep::Fields:
                /** @var list<string> $imported */
                $imported = $data['imported'];
                $all      = array_map(static fn (array $field): string => $field['id'], $this->plans->fields($plan));
                $this->plans->chooseFields($plan, $version, array_values(array_filter($all, static fn (string $id): bool => !in_array($id, $imported, true))));
                break;
            case ImportPlanStep::People:
                $this->plans->choosePeople($plan, $version, $data['peopleFirst'] === true);
                break;
            default:
                $mode = ReimportMode::from((string) $data['mode']);
                $this->plans->confirmPreview($plan, $version, $mode);
                $this->plans->start($plan, $plan->getVersion(), $user, $mode);
        }
    }

    /**
     * The form of a step, with the plan's current values (and the provider's choices for the step).
     *
     * @param list<string> $opened
     * @param list<string>|null $ticked the tree boxes ticked on the page that opened a level
     *
     * @return FormInterface<mixed>
     */
    private function stepForm(TaskSyncImportPlan $plan, ImportPlanStep $step, UserInterface $user, array $opened, ?array $ticked = null): FormInterface
    {
        $options = ['step' => $step->value, 'version' => $plan->getVersion(), 'action' => $this->generateUrl($this->routes['import_plan_step']['name'], ['planId' => $plan->getId(), 'step' => $step->value])];
        switch ($step) {
            case ImportPlanStep::Connect:
                $options['choices']  = $this->connectionChoices($plan, $user);
                $options['selected'] = $plan->getConnectionRef() !== '' ? $plan->getConnectionRef() : null;
                break;
            case ImportPlanStep::Workspace:
                $options['choices'] = [];
                foreach ($this->plans->workspaces($plan, $user) as $workspace) {
                    $options['choices'][$workspace->id] = $workspace->name;
                }
                $options['selected'] = $plan->getWorkspaceId() !== null ? 'w:' . $plan->getWorkspaceId() : null;
                break;
            case ImportPlanStep::Tree:
                $options['choices']      = $this->treeChoices($this->tree($plan, $opened));
                $options['selected']     = array_values(array_filter($ticked ?? array_map(static fn (TaskSyncImportPlanItem $item): string => $item->getSourceId(), $this->store->items($plan)), static fn (string $id): bool => array_key_exists($id, $options['choices'])));
                $options['opened']       = implode(',', $opened);
                $options['layout']       = $plan->getLayout()->value;
                $options['plan_options'] = $plan->getOptions();
                $options['time_option']  = $this->timeSink;
                break;
            case ImportPlanStep::Boards:
                $targets = [];
                foreach ($this->plans->targetBoards($plan, $user) as $board) {
                    $targets[$board->getId()] = $board->getName();
                }
                $options['items'] = [];
                foreach ($this->store->items($plan) as $item) {
                    $choices            = ['new' => $this->translator->trans('task_board.import_plan.boards.new', [], 'NowoTaskBoardBundle')] + $targets;
                    $options['items'][] = ['id' => $item->getId(), 'label' => $item->getSourceLabel(), 'name' => $item->getBoardName(), 'target' => $item->isCreateBoard() ? 'new' : (string) $item->getBoardId(), 'choices' => $choices];
                }
                break;
            case ImportPlanStep::Statuses:
                $options['groups'] = $this->statusTables($plan);
                break;
            case ImportPlanStep::Fields:
                $options['choices'] = [];
                foreach ($this->plans->fields($plan) as $field) {
                    $options['choices'][$field['id']] = $field['name'];
                }
                $options['selected'] = array_values(array_filter(array_map(strval(...), array_keys($options['choices'])), static fn (string $id): bool => !in_array($id, $plan->getExcludedFields(), true)));
                break;
            case ImportPlanStep::People:
                $options['people_first'] = $plan->option(TaskSyncImportPlan::OPTION_PEOPLE, false);
                break;
            default:
                $options['mode'] = $plan->getReimportMode()->value;
        }

        return $this->container->get('form.factory')->createNamed(ImportPlanStepFormType::NAME, ImportPlanStepFormType::class, null, $options);
    }

    /**
     * @param FormInterface<mixed>|null $form a submitted form with errors, or null (a new one)
     * @param list<string> $opened
     * @param list<string>|null $ticked the tree boxes ticked on the page that opened a level
     */
    private function renderStep(TaskSyncImportPlan $plan, ImportPlanStep $step, UserInterface $user, ?FormInterface $form, array $opened, int $status = Response::HTTP_OK, ?array $ticked = null): Response
    {
        $vars = [
            'page'      => $this->page('task_board.import_plan.title', $plan),
            'plan'      => $plan,
            'step'      => $step,
            'steps'     => ImportPlanStep::cases(),
            'items'     => $this->store->items($plan),
            'templates' => $this->templates,
            'error'     => null,
        ];
        try {
            $vars += $this->stepView($plan, $step, $user, $form, $opened, $ticked);
        } catch (ImportPlanException $exception) {
            $vars['error'] = $exception->reason;
            $vars['form']  = null;
        }

        return $this->render($this->templates['import_plan'], $vars, new Response(status: $status));
    }

    /**
     * @param FormInterface<mixed>|null $form
     * @param list<string> $opened
     * @param list<string>|null $ticked the tree boxes ticked on the page that opened a level
     *
     * @return array<string, mixed>
     */
    private function stepView(TaskSyncImportPlan $plan, ImportPlanStep $step, UserInterface $user, ?FormInterface $form, array $opened, ?array $ticked = null): array
    {
        if ($step === ImportPlanStep::Run) {
            return $this->runView($plan);
        }
        $vars = ['form' => ($form ?? $this->stepForm($plan, $step, $user, $opened, $ticked))->createView()];
        switch ($step) {
            case ImportPlanStep::Connect:
                $returnTo                  = $this->generateUrl($this->routes['import_plan']['name'], ['planId' => $plan->getId(), 'step' => ImportPlanStep::Connect->value]);
                $vars['connectUrl']        = $this->hostUrl($this->plans->connectUrl($plan, $returnTo));
                $vars['credentialFormUrl'] = $this->hostUrl($this->plans->credentialFormUrl($plan));
                break;
            case ImportPlanStep::Tree:
                $vars['tree']   = $this->tree($plan, $opened);
                $vars['opened'] = $opened;
                break;
            case ImportPlanStep::Statuses:
                $vars['tables'] = $this->statusTables($plan);
                break;
            case ImportPlanStep::Fields:
                $vars['fields'] = $this->plans->fields($plan);
                break;
            case ImportPlanStep::People:
                $vars['people'] = $this->peopleCounts($plan);
                break;
            case ImportPlanStep::Preview:
                $vars['preview']  = $this->previewer->preview($plan, $user);
                $vars['timeSink'] = $this->timeSink;
                break;
            default:
        }

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function runView(TaskSyncImportPlan $plan): array
    {
        $view        = $this->progressView($plan);
        $peopleBoard = $plan->getReport()['people_board'] ?? null;

        return [
            'form'         => null,
            'progress'     => $view,
            'progressUrl'  => $plan->getStatus()->isActive() ? $this->generateUrl($this->routes['import_plan_progress']['name'], ['planId' => $plan->getId()]) : null,
            'pollInterval' => $this->pollInterval,
            'cancelForm'   => $plan->getStatus()->isActive() ? $this->csrfForm('task_board_import_plan_cancel')->createView() : null,
            'resumeForm'   => in_array($plan->getStatus(), [ImportPlanStatus::Paused, ImportPlanStatus::RateLimited], true) ? $this->csrfForm('task_board_import_plan_resume')->createView() : null,
            'deleteForm'   => !$plan->getStatus()->isActive() ? $this->csrfForm('task_board_import_plan_delete')->createView() : null,
            'restartForm'  => $plan->getStatus()->isTerminal() ? $this->container->get('form.factory')->createNamed(ImportPlanStepFormType::NAME, ImportPlanStepFormType::class, null, ['step' => 'preview', 'version' => $plan->getVersion(), 'mode' => $plan->getReimportMode()->value, 'csrf_token_id' => 'task_board_import_plan_start'])->createView() : null,
            'peopleUrl'    => is_string($peopleBoard) && $peopleBoard !== '' && isset($this->routes['board_connection_people']) ? $this->generateUrl($this->routes['board_connection_people']['name'], ['boardId' => $peopleBoard]) : null,
            'failedItems'  => count(array_filter($this->store->items($plan), static fn (TaskSyncImportPlanItem $item): bool => $item->getStatus() === ImportPlanItemStatus::Failed)),
        ];
    }

    /**
     * @return array{processed: int, total: int, summary: string, items: list<array{id: string, status: string, processed: int, total: int, errorCode: string|null}>}
     */
    private function progressView(TaskSyncImportPlan $plan): array
    {
        $items = [];
        foreach ($this->store->items($plan) as $item) {
            $report  = $item->getReport();
            $done    = $item->getStatus() === ImportPlanItemStatus::Succeeded || $item->getStatus() === ImportPlanItemStatus::Failed;
            $total   = max($report['total'] ?? 0, $report['processed'] ?? 0, 1);
            $items[] = ['id' => $item->getId(), 'status' => $item->getStatus()->value, 'processed' => $done ? $total : min($report['processed'] ?? 0, $total), 'total' => $total, 'errorCode' => $item->getErrorCode()];
        }
        $processed = array_sum(array_map(static fn (array $item): int => $item['processed'], $items));
        $total     = array_sum(array_map(static fn (array $item): int => $item['total'], $items));

        return [
            'processed' => $processed,
            'total'     => $total,
            'items'     => $items,
            'summary'   => $this->translator->trans('task_board.import_plan.progress.summary', [
                '%status%'  => $this->translator->trans('task_board.import_plan.status.' . $plan->getStatus()->value, [], 'NowoTaskBoardBundle'),
                '%done%'    => (string) count(array_filter($items, static fn (array $item): bool => $item['status'] === ImportPlanItemStatus::Succeeded->value)),
                '%boards%'  => (string) count($items),
                '%created%' => (string) $plan->reportInt('created'),
                '%updated%' => (string) $plan->reportInt('updated'),
            ], 'NowoTaskBoardBundle'),
        ];
    }

    /**
     * The tables of the Statuses step, with the column choices and the current (or default) choice per status.
     *
     * @return list<array{key: string, statuses: list<string>, types: list<string|null>, columns: array<string, string>, selected: list<string>, items: list<string>}>
     */
    private function statusTables(TaskSyncImportPlan $plan): array
    {
        $tables = [];
        foreach ($this->plans->statusGroups($plan) as $group) {
            $columns = [StatusColumns::CREATE => $this->translator->trans('task_board.import_plan.statuses.create', [], 'NowoTaskBoardBundle')];
            $columns += $group['columns'];
            $columns[StatusColumns::SKIP] = $this->translator->trans('task_board.import_plan.statuses.skip', [], 'NowoTaskBoardBundle');
            $current                      = $group['items'][0]->getStatusMap();
            $selected                     = [];
            foreach ($group['statuses'] as $index => $status) {
                $chosen     = $current[$index]['column'] ?? null;
                $selected[] = is_string($chosen) && array_key_exists($chosen, $columns) ? $chosen : ImportPlanService::defaultColumn($status->name, $group['columns']);
            }
            $tables[] = [
                'key'      => $group['key'],
                'statuses' => array_map(static fn (RemoteStatus $status): string => $status->name, $group['statuses']),
                'types'    => array_map(static fn (RemoteStatus $status): ?string => $status->type?->value, $group['statuses']),
                'columns'  => $columns,
                'selected' => $selected,
                'items'    => array_map(static fn (TaskSyncImportPlanItem $item): string => $item->getBoardName(), $group['items']),
            ];
        }

        return $tables;
    }

    /**
     * The opened levels of the Tree step: the workspace's children, then each opened space or folder.
     *
     * @param list<string> $opened
     *
     * @return array<string, list<RemoteContainer>> parent id ('' = top) => children
     */
    private function tree(TaskSyncImportPlan $plan, array $opened): array
    {
        $tree = ['' => $this->plans->level($plan, null)];
        // Audit ZBY-L1: a level opens only when its space or folder was listed from the plan's workspace already (the
        // posted list of opened levels is rebuilt in order; anything else is ignored).
        $known = [];
        foreach ($tree[''] as $node) {
            $known[$node->id] = $node->type !== ContainerType::List;
        }
        foreach ($opened as $parent) {
            if (!($known[$parent] ?? false)) {
                continue;
            }
            $tree[$parent] = $this->plans->level($plan, $parent);
            foreach ($tree[$parent] as $node) {
                $known[$node->id] = $node->type !== ContainerType::List;
            }
        }

        return $tree;
    }

    /**
     * Every node of the opened levels a user may tick: lists, folders and spaces.
     *
     * @param array<string, list<RemoteContainer>> $tree
     *
     * @return array<string, string>
     */
    private function treeChoices(array $tree): array
    {
        $choices = [];
        foreach ($tree as $level) {
            foreach ($level as $node) {
                if ($node->type !== ContainerType::Workspace) {
                    $choices[$node->id] = $node->name;
                }
            }
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $posted
     *
     * @return list<string>
     */
    private function opened(array $posted): array
    {
        $raw    = is_string($posted['opened'] ?? null) ? $posted['opened'] : '';
        $opened = array_values(array_filter(explode(',', $raw), static fn (string $id): bool => preg_match(ImportPlanService::CONTAINER_ID, $id) === 1));

        return array_slice(array_values(array_unique($opened)), 0, self::MAX_OPEN);
    }

    /**
     * The tenant's connections as radio labels ("<label> · …a1b2 · OAuth").
     *
     * @return array<string, string>
     */
    private function connectionChoices(TaskSyncImportPlan $plan, UserInterface $user): array
    {
        $choices = [];
        foreach ($this->plans->connections($plan, $user) as $choice) {
            $choices[$choice->ref] = $this->translator->trans('task_board.import_plan.connect.choice', [
                '%label%' => $choice->label,
                '%last%'  => $choice->lastFour,
                '%kind%'  => $this->translator->trans('task_board.import_plan.connect.kind.' . $choice->kind->value, [], 'NowoTaskBoardBundle'),
            ], 'NowoTaskBoardBundle');
        }

        return $choices;
    }

    private function connection(TaskSyncImportPlan $plan, UserInterface $user, string $ref): ConnectionChoice
    {
        return array_values(array_filter($this->plans->connections($plan, $user), static fn (ConnectionChoice $choice): bool => $choice->ref === $ref))[0] ?? throw new ImportPlanException(ImportPlanException::CREDENTIAL);
    }

    /**
     * @return array{mapped: int, unreviewed: int, total: int, reviewed: bool}
     */
    private function peopleCounts(TaskSyncImportPlan $plan): array
    {
        $directory = $this->peopleRepository instanceof TaskSyncPersonRepository ? $plan->getDirectory() : null;
        $counts    = $directory instanceof TaskSyncDirectory && $this->peopleRepository instanceof TaskSyncPersonRepository ? $this->peopleRepository->mappingCounts($directory) : [];

        return [
            'mapped'     => ($counts[TaskSyncPersonMapping::Auto->value] ?? 0) + ($counts[TaskSyncPersonMapping::Manual->value] ?? 0),
            'unreviewed' => $directory instanceof TaskSyncDirectory && $this->peopleRepository instanceof TaskSyncPersonRepository ? $this->peopleRepository->unreviewedCount($directory) : 0,
            'total'      => array_sum($counts),
            'reviewed'   => $directory?->getReviewedAt() instanceof DateTimeImmutable,
        ];
    }

    /** The step a GET shows: the asked one when it was reached, else the furthest; a started plan shows its run. */
    private function viewStep(TaskSyncImportPlan $plan, string $asked): ImportPlanStep
    {
        if ($plan->getStatus() !== ImportPlanStatus::Draft) {
            return ImportPlanStep::Run;
        }
        // A draft's furthest page is its preview (Run is reached by starting it).
        $furthest = $plan->getStep() === ImportPlanStep::Run ? ImportPlanStep::Preview : $plan->getStep();
        $step     = ImportPlanStep::tryFrom($asked);

        return $step instanceof ImportPlanStep && $step->position() <= $furthest->position() ? $step : $furthest;
    }

    private function refused(TaskSyncImportPlan $plan, ImportPlanStep $step, ImportPlanException $exception): RedirectResponse
    {
        $this->flashRefusal($exception);

        return $this->toStep($plan, $step);
    }

    private function flashRefusal(ImportPlanException $exception): void
    {
        $this->logRefused('import_plan_' . $exception->reason);
        $this->addFlash('error', $this->translator->trans('task_board.import_plan.error.' . $exception->reason, ['%detail%' => (string) $exception->detail], 'NowoTaskBoardBundle'));
    }

    private function toStep(TaskSyncImportPlan $plan, ImportPlanStep $step): RedirectResponse
    {
        return $this->redirectToRoute($this->routes['import_plan']['name'], ['planId' => $plan->getId()] + ($step === ImportPlanStep::Run ? [] : ['step' => $step->value]));
    }

    /** The shared page model: the plan's workspace in the subtitle, back to the plans and to the boards. */
    private function page(string $title, ?TaskSyncImportPlan $plan): ShowPage
    {
        $twig      = $this->container->get('twig');
        $plans     = $this->generateUrl($this->routes['import_plans']['name']);
        $actions   = $plan instanceof TaskSyncImportPlan ? [new Action(label: TaskBoardPages::label('task_board.import_plan.list.back'), href: $plans)] : [];
        $actions[] = Action::route('custom', $this->routes['index']['name'], [], TaskBoardPages::label('task_board.import_plan.back_boards'));
        if ($this->dashboardRoute !== null) {
            $actions[] = Action::route('custom', $this->dashboardRoute, [], TaskBoardPages::label('task_board.action.back'));
        }

        return new ShowPage(
            new PageHeader(
                TaskBoardPages::label($title),
                TaskBoardPages::label($plan?->getWorkspaceName() !== null ? 'task_board.import_plan.subtitle_workspace' : 'task_board.import_plan.subtitle', ['%workspace%' => (string) $plan?->getWorkspaceName()]),
                $actions,
                $plan instanceof TaskSyncImportPlan ? [new Crumb(TaskBoardPages::label('task_board.import_plan.list.title'), $plans)] : [],
                breadcrumbsMode: 'own',
            ),
            bundle: TaskBoardPages::BUNDLE,
            layout: $twig instanceof Environment ? $this->pages->layout($twig) : null,
        );
    }

    /**
     * The user and the tenant, when the user may import plans there; 404 otherwise (and when the host offers no tenant
     * connections).
     *
     * @return array{0: UserInterface, 1: TaskBoardTenant|null}
     */
    private function requireImporter(): array
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }
        $tenant = $this->tenantContext?->require($user);
        if (!$this->plans->isAvailable()
            || ($tenant instanceof TaskBoardTenant && $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface && !$this->accessChecker->canListBoards($user, $tenant->key))
            || !$this->resourceAccessChecker->canImportPlan($user, $tenant?->key)) {
            throw new NotFoundHttpException('Not found.');
        }

        return [$user, $tenant];
    }

    /** A plan of the request's tenant (the key compared exactly); 404 otherwise. */
    private function findPlan(string $planId, ?TaskBoardTenant $tenant): TaskSyncImportPlan
    {
        // Read again: workers and the store's conditional updates change the row behind the manager's back.
        $plan = $this->store->reload($planId);
        if (!$plan instanceof TaskSyncImportPlan || $plan->getTenantKey() !== $tenant?->key) {
            throw new NotFoundHttpException('Plan not found.');
        }

        return $plan;
    }

    /** A host link the page shows: an `https://` URL or a same-origin path, else none. */
    private function hostUrl(?string $url): ?string
    {
        return $url !== null && preg_match('~^(?:https://[^\s/?#@\\\\]+|/(?![/\\\\]))[^\s\x00-\x1f\x7f\\\\]*$~Di', $url) === 1 ? $url : null;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function csrfForm(string $tokenId): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => $tokenId]);
    }

    /**
     * @param array<string, mixed>|null $values the posted values (default: the request's flat fields)
     */
    private function denyUnlessValidCsrf(Request $request, string $tokenId, ?array $values = null): void
    {
        $form = $this->csrfForm($tokenId);
        $form->submit($values === null ? $request->request->all() : ['_token' => $values['_token'] ?? null]);
        if (!$form->isValid()) {
            $this->logRefused('csrf_invalid');

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @param array<string, mixed> $ids
     */
    private function logWrite(string $action, array $ids): void
    {
        $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => $action] + $ids);
    }

    private function logRefused(string $reason): void
    {
        $this->logger->warning('TaskBoard write refused: {reason}', ['bundle' => 'nowo_task_board', 'reason' => $reason]);
    }
}
