<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\ConnectionLinkFormType;
use Nowo\TaskBoardBundle\Form\ConnectionRefreshFormType;
use Nowo\TaskBoardBundle\Form\ContainerLevelFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncRequest;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\SyncBusyException;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncRunnerInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function hash;
use function in_array;
use function is_object;
use function is_string;
use function preg_match;
use function serialize;
use function unserialize;

/**
 * Since 1.23: the provider connection of a board — connect (pick a provider, a stored connection and a remote list),
 * refresh, progress, history, unlink. Only users who may manage the board's connection reach it; everyone else gets
 * 404. Every action is a POST with its own CSRF token; the progress endpoint is a read-only GET.
 */
final class TaskBoardConnectionController extends AbstractController
{
    private const CONTAINER_ID = '/^[A-Za-z0-9_:-]{1,128}$/D';

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardRepositoryInterface $boardRepository,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly TaskProviderRegistry $providers,
        private readonly ProviderCredentialResolverInterface $credentials,
        private readonly TaskBoardSyncService $sync,
        private readonly SyncStore $store,
        private readonly SyncRunnerInterface $runner,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?string $dashboardRoute = null,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly ?CacheInterface $cache = null,
        private readonly int $containerCacheTtl = 300,
        private readonly int $historyShown = 20,
        private readonly int $pollInterval = 2000,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function connection(string $boardId, Request $request): Response
    {
        $user       = $this->requireUser();
        $board      = $this->findManagedBoard($boardId, $user);
        $connection = $this->store->findConnection($board);
        if ($connection instanceof TaskBoardConnection) {
            return $this->render($this->templates['connection'], $this->linkedView($board, $connection));
        }

        $provider = $this->pickProvider((string) $request->query->get('provider', ''));
        $choice   = $provider instanceof TaskProviderInterface ? $this->pickConnection($this->credentials->connectionsFor($board, $user, $provider->id()), (string) $request->query->get('connection', '')) : null;

        // Audit EG-L5: a GET (no CSRF token) reads the top level only, from the cache when it is there; the opened
        // levels are rendered by the browser's POST.
        return $this->connectionPage($board, $user, $provider, $choice, []);
    }

    /**
     * The connection page of a board not linked yet, with the levels in `$open` opened (each a provider call).
     *
     * @param list<string> $open
     */
    private function connectionPage(TaskBoard $board, UserInterface $user, ?TaskProviderInterface $provider, ?ConnectionChoice $choice, array $open): Response
    {
        $choices   = $provider instanceof TaskProviderInterface ? $this->credentials->connectionsFor($board, $user, $provider->id()) : [];
        $linkForm  = $provider instanceof TaskProviderInterface && $choice instanceof ConnectionChoice ? $this->linkForm($board, $provider, $choice) : null;
        $tree      = null;
        $treeError = null;
        if ($provider instanceof TaskProviderInterface && $choice instanceof ConnectionChoice) {
            try {
                $tree = $this->tree($board, $provider, $choice, $open);
            } catch (ProviderException $exception) {
                $treeError = $exception->errorCode->value;
            }
        }

        return $this->render($this->templates['connection'], [
            'board'             => $board,
            'connection'        => null,
            'providers'         => $this->providers->all(),
            'provider'          => $provider,
            'choices'           => $choices,
            'choice'            => $choice,
            'credentialFormUrl' => $provider instanceof TaskProviderInterface ? $this->credentialFormUrl($board, $provider) : null,
            'linkForm'          => $linkForm?->createView(),
            'tree'              => $tree,
            'levelForms'        => array_map(fn (ContainerPage $level): FormView => $this->levelForm($level)->createView(), $tree ?? []),
            'treeError'         => $treeError,
            'open'              => $open,
            'containersForm'    => $this->csrfForm('task_board_connection_containers')->createView(),
            'dashboardRoute'    => $this->dashboardRoute,
            'templates'         => $this->templates,
        ]);
    }

    /**
     * One level of the container browser: an HTML fragment for the browser's script, or (without script) a
     * redirect to the page with that level open.
     */
    public function containers(string $boardId, Request $request): Response
    {
        $user  = $this->requireUser();
        $board = $this->findManagedBoard($boardId, $user);
        $this->denyUnlessValidCsrf($request, 'task_board_connection_containers');
        if ($this->store->findConnection($board) instanceof TaskBoardConnection) {
            throw new NotFoundHttpException('The board is already linked.');
        }
        // Posted by the browser's script (flat fields) or, without script, by the link form's disclosure buttons.
        $link     = $request->request->all(ConnectionLinkFormType::NAME);
        $provider = $this->pickProvider((string) $request->request->get('provider', is_string($link['provider'] ?? null) ? $link['provider'] : ''));
        $choice   = $provider instanceof TaskProviderInterface ? $this->pickConnection($this->credentials->connectionsFor($board, $user, $provider->id()), (string) $request->request->get('connection', is_string($link['connection'] ?? null) ? $link['connection'] : '')) : null;
        $parent   = (string) $request->request->get('parent', '');
        $cursor   = (string) $request->request->get('cursor', '');
        if (!$provider instanceof TaskProviderInterface || !$choice instanceof ConnectionChoice || ($parent !== '' && preg_match(self::CONTAINER_ID, $parent) !== 1) || ($cursor !== '' && preg_match(self::CONTAINER_ID, $cursor) !== 1)) {
            throw new NotFoundHttpException('Unknown provider, connection or container.');
        }

        if (!$request->isXmlHttpRequest()) {
            // Without script: the page itself with that level open, answered to this POST (audit EG-L5: never a
            // redirect to a GET that would call the provider without a CSRF token).
            $open = array_values(array_filter($request->query->all('open'), static fn (mixed $id): bool => is_string($id) && preg_match(self::CONTAINER_ID, $id) === 1));
            if ($parent !== '' && !in_array($parent, $open, true)) {
                $open[] = $parent;
            }

            return $this->connectionPage($board, $user, $provider, $choice, $open);
        }

        try {
            $page  = $this->level($board, $provider, $choice, $parent === '' ? null : $parent, $cursor === '' ? null : $cursor);
            $error = null;
        } catch (ProviderException $exception) {
            $page  = new ContainerPage([]);
            $error = $exception->errorCode->value;
        }

        return $this->render($this->templates['connection_containers'], [
            'board'             => $board,
            'provider'          => $provider,
            'choice'            => $choice,
            'parent'            => $parent,
            'level'             => $page,
            'levelForm'         => $this->levelForm($page)->createView(),
            'error'             => $error,
            'credentialFormUrl' => $this->credentialFormUrl($board, $provider),
            'containersForm'    => $this->csrfForm('task_board_connection_containers')->createView(),
            'open'              => [],
            'templates'         => $this->templates,
        ]);
    }

    public function link(string $boardId, Request $request): RedirectResponse
    {
        $user     = $this->requireUser();
        $board    = $this->findManagedBoard($boardId, $user);
        $actorId  = $this->actorId($user);
        $data     = $request->request->all(ConnectionLinkFormType::NAME);
        $provider = $this->pickProvider(is_string($data['provider'] ?? null) ? $data['provider'] : '');
        $choice   = $provider instanceof TaskProviderInterface ? $this->pickConnection($this->credentials->connectionsFor($board, $user, $provider->id()), is_string($data['connection'] ?? null) ? $data['connection'] : '') : null;
        if (!$provider instanceof TaskProviderInterface || !$choice instanceof ConnectionChoice) {
            throw new NotFoundHttpException('Unknown provider or connection.');
        }
        $form = $this->linkForm($board, $provider, $choice);
        $form->submit($data);
        if (!$form->isValid()) {
            // Form-level errors are the CSRF token's; field errors are a missing or malformed list.
            if ($form->getErrors()->count() > 0) {
                $this->logRefused('csrf_invalid');

                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $this->addFlash('error', $this->translator->trans('task_board.sync.flash.pick_list', [], 'NowoTaskBoardBundle'));

            return $this->backToPage($boardId, $provider, $choice);
        }
        if ($this->store->findConnection($board) instanceof TaskBoardConnection) {
            return $this->backToPage($boardId);
        }
        /** @var array{container: string, createMissingColumns: bool, fallbackColumnId: string|null, importComments: bool, importAttachments: bool, archiveRemoteDeletions: bool} $values */
        $values     = $form->getData();
        $credential = $this->credentials->credentialFor($board, $choice->ref);
        $container  = null;
        if ($credential instanceof ProviderCredential) {
            try {
                $container = $provider->findContainer($credential, $values['container']);
            } catch (ProviderException $exception) {
                $this->addFlash('error', $this->translator->trans('task_board.sync.error.' . $exception->errorCode->value, [], 'NowoTaskBoardBundle'));

                return $this->backToPage($boardId, $provider, $choice);
            }
        }
        if (!$credential instanceof ProviderCredential || !$container instanceof RemoteContainer || !$container->selectable) {
            $this->addFlash('error', $this->translator->trans('task_board.sync.flash.pick_list', [], 'NowoTaskBoardBundle'));

            return $this->backToPage($boardId, $provider, $choice);
        }

        $this->sync->link($board, $provider, $credential, $container, $user, [
            'createMissingColumns'   => (bool) $values['createMissingColumns'],
            'fallbackColumnId'       => $values['fallbackColumnId'],
            'importComments'         => (bool) $values['importComments'],
            'importAttachments'      => (bool) $values['importAttachments'],
            'archiveRemoteDeletions' => (bool) $values['archiveRemoteDeletions'],
        ]);
        $this->logWrite('sync_link', ['board_id' => $boardId, 'provider' => $provider->id()]);
        $this->runner->dispatch(new SyncRequest($boardId, $actorId, SyncTrigger::Manual));
        $this->addFlash('success', $this->translator->trans('task_board.sync.flash.linked', ['%list%' => $container->name], 'NowoTaskBoardBundle'));

        return $this->backToPage($boardId);
    }

    public function refresh(string $boardId, Request $request): RedirectResponse
    {
        $user        = $this->requireUser();
        $board       = $this->findManagedBoard($boardId, $user);
        $massArchive = $request->request->all('task_board_sync_refresh')['allowMassArchive'] ?? null;
        $form        = $this->createForm(ConnectionRefreshFormType::class, null, ['allow_mass_archive' => $massArchive === '1']);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->logRefused('csrf_invalid');

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$this->store->findConnection($board) instanceof TaskBoardConnection) {
            throw new NotFoundHttpException('The board is not linked.');
        }
        $full = $form->has('full') && $form->get('full')->getData() === true;
        $this->runner->dispatch(new SyncRequest($boardId, $this->actorId($user), SyncTrigger::Manual, full: $full, allowMassArchive: $massArchive === '1'));
        $this->logWrite('sync_refresh', ['board_id' => $boardId, 'full' => $full, 'mass_archive' => $massArchive === '1']);
        $this->addFlash('success', $this->translator->trans('task_board.sync.flash.refresh_started', [], 'NowoTaskBoardBundle'));

        return $this->backToPage($boardId);
    }

    /**
     * Progress of a run as JSON (counters only, never a credential). With the inline runner a paused run advances by
     * one slice per poll.
     */
    public function progress(string $boardId, string $runId): JsonResponse
    {
        $user  = $this->requireUser();
        $board = $this->findManagedBoard($boardId, $user);
        $run   = $this->store->findRun($runId);
        if (!$run instanceof TaskSyncRun || $run->getBoardId() !== $board->getId()) {
            throw new NotFoundHttpException('Run not found.');
        }
        $connection = $run->getConnection();
        if ($this->runner->advancesOnPoll() && $connection->getRunningRunId() === null && $connection->getCursor()?->runId === $runId && $run->getStatus() === SyncStatus::Paused) {
            $this->runner->dispatch(new SyncRequest($boardId, $this->actorId($user), SyncTrigger::Manual));
            $run = $this->store->findRun($runId) ?? $run;
        }
        $report = $run->getReport();

        $response = new JsonResponse([
            'runId'     => $runId,
            'status'    => $run->getStatus()->value,
            'terminal'  => $run->getStatus()->isTerminal(),
            'pagesDone' => $report->pagesDone ?? 0,
            'total'     => $report?->total,
            'processed' => $report?->processed() ?? 0,
            'created'   => $report->created ?? 0,
            'updated'   => $report->updated ?? 0,
            'archived'  => $report->archived ?? 0,
            'restored'  => $report->restored ?? 0,
            'skipped'   => $report?->skipped() ?? 0,
            'conflicts' => count($report->conflicts ?? []),
            'files'     => $report?->files->toArray() ?? ['copied' => 0, 'reused' => 0, 'refused' => 0, 'bytes' => 0],
            'summary'   => $report instanceof SyncReport ? $this->summary($report) : '',
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function cancel(string $boardId, string $runId, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $board = $this->findManagedBoard($boardId, $user);
        $this->denyUnlessValidCsrf($request, 'task_board_connection_cancel');
        $this->sync->requestCancel($board, $runId);
        $this->logWrite('sync_cancel', ['board_id' => $boardId, 'run_id' => $runId]);

        return $this->backToPage($boardId);
    }

    public function unlink(string $boardId, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $board = $this->findManagedBoard($boardId, $user);
        $this->denyUnlessValidCsrf($request, 'task_board_connection_unlink');
        try {
            $this->sync->unlink($board, $user);
        } catch (SyncBusyException) {
            // Audit FP-L5: a running sync holds the lock; nothing was changed.
            $this->addFlash('error', $this->translator->trans('task_board.sync.status.busy', [], 'NowoTaskBoardBundle'));

            return $this->backToPage($boardId);
        }
        $this->logWrite('sync_unlink', ['board_id' => $boardId]);
        $this->addFlash('success', $this->translator->trans('task_board.sync.flash.unlinked', [], 'NowoTaskBoardBundle'));

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    /** The one-line summary of a report, in the user's language (also the live region text). */
    public function summary(SyncReport $report): string
    {
        return $this->translator->trans('task_board.sync.progress.summary', [
            '%created%'   => (string) $report->created,
            '%updated%'   => (string) $report->updated,
            '%unchanged%' => (string) $report->unchanged,
            '%archived%'  => (string) $report->archived,
            '%restored%'  => (string) $report->restored,
            '%skipped%'   => (string) $report->skipped(),
            '%deleted%'   => (string) $report->skips->locallyDeleted,
            '%conflicts%' => (string) count($report->conflicts),
            '%files%'     => (string) $report->files->copied,
        ], 'NowoTaskBoardBundle');
    }

    /**
     * @return array<string, mixed>
     */
    private function linkedView(TaskBoard $board, TaskBoardConnection $connection): array
    {
        $runs   = $this->store->latestRuns($connection, $this->historyShown);
        $latest = $runs[0] ?? null;
        $report = $latest?->getReport();
        $active = $latest instanceof TaskSyncRun && !$latest->getStatus()->isTerminal() ? $latest : null;

        return [
            'board'            => $board,
            'connection'       => $connection,
            'provider'         => $this->providers->has($connection->getProvider()) ? $this->providers->get($connection->getProvider()) : null,
            'runs'             => $runs,
            'latestReport'     => $report,
            'latestSummary'    => $report instanceof SyncReport ? $this->summary($report) : null,
            'activeRun'        => $active,
            'refreshForm'      => $this->createForm(ConnectionRefreshFormType::class)->createView(),
            'massArchiveForm'  => $report?->massArchiveHeld() === true ? $this->createForm(ConnectionRefreshFormType::class, null, ['allow_mass_archive' => true])->createView() : null,
            'massArchiveCount' => $this->heldCount($report),
            'unlinkForm'       => $this->csrfForm('task_board_connection_unlink')->createView(),
            'cancelForm'       => $active instanceof TaskSyncRun ? $this->csrfForm('task_board_connection_cancel')->createView() : null,
            'progressUrl'      => $active instanceof TaskSyncRun ? $this->generateUrl($this->routes['board_connection_progress']['name'], ['boardId' => $board->getId(), 'runId' => $active->getId()]) : null,
            'summaries'        => array_map(fn (TaskSyncRun $run): ?string => $run->getReport() instanceof SyncReport ? $this->summary($run->getReport()) : null, $runs),
            'dashboardRoute'   => $this->dashboardRoute,
            'templates'        => $this->templates,
            'pollInterval'     => $this->pollInterval,
        ];
    }

    /**
     * @return array{count: string, total: string}|null
     */
    private function heldCount(?SyncReport $report): ?array
    {
        foreach ($report->warnings ?? [] as $warning) {
            if ($warning->code === 'mass_archive_guard') {
                return ['count' => $warning->params['%count%'] ?? '?', 'total' => $warning->params['%total%'] ?? '?'];
            }
        }

        return null;
    }

    /**
     * The top level of the tree and every opened level (no-script browsing), each one call per level.
     *
     * @param list<string> $open
     *
     * @return array<string, ContainerPage> parent id ('' = top) => level
     */
    private function tree(TaskBoard $board, TaskProviderInterface $provider, ConnectionChoice $choice, array $open): array
    {
        $tree = ['' => $this->level($board, $provider, $choice, null, null)];
        foreach (array_slice($open, 0, 10) as $parent) {
            $tree[$parent] = $this->level($board, $provider, $choice, $parent, null);
        }

        return $tree;
    }

    /**
     * One level, cached for `container_cache_ttl` seconds per connection (the key never holds the credential).
     */
    private function level(TaskBoard $board, TaskProviderInterface $provider, ConnectionChoice $choice, ?string $parent, ?string $cursor): ContainerPage
    {
        $load = function () use ($board, $provider, $choice, $parent, $cursor): ContainerPage {
            $credential = $this->credentials->credentialFor($board, $choice->ref);
            if (!$credential instanceof ProviderCredential) {
                throw new NotFoundHttpException('Connection not found.');
            }

            return $provider->listContainers($credential, $parent, $cursor);
        };
        if (!$this->cache instanceof CacheInterface || $this->containerCacheTtl <= 0) {
            return $load();
        }
        $key  = 'nowo_task_board.sync.containers.' . hash('sha256', $provider->id() . "\x1F" . $choice->ref . "\x1F" . ($parent ?? '') . "\x1F" . ($cursor ?? '') . "\x1F" . $board->getId());
        $data = $this->cache->get($key, function (ItemInterface $item) use ($load): string {
            $item->expiresAfter($this->containerCacheTtl);

            return serialize($load());
        });
        $page = is_string($data) ? unserialize($data, ['allowed_classes' => [ContainerPage::class, RemoteContainer::class, ContainerType::class]]) : null;

        return $page instanceof ContainerPage ? $page : $load();
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    private function linkForm(TaskBoard $board, TaskProviderInterface $provider, ConnectionChoice $choice): FormInterface
    {
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $label = $column->getName();
            for ($n = 2; isset($columns[$label]); ++$n) {
                $label = $column->getName() . ' (' . $n . ')';
            }
            $columns[$label] = $column->getId();
        }

        return $this->container->get('form.factory')->createNamed(ConnectionLinkFormType::NAME, ConnectionLinkFormType::class, [
            'provider'               => $provider->id(),
            'connection'             => $choice->ref,
            'createMissingColumns'   => true,
            'importComments'         => true,
            'importAttachments'      => true,
            'archiveRemoteDeletions' => true,
        ], [
            'action'         => $this->generateUrl($this->routes['board_connection_link']['name'], ['boardId' => $board->getId()]),
            'column_choices' => $columns,
        ]);
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    private function levelForm(ContainerPage $page): FormInterface
    {
        $lists = [];
        foreach ($page->items as $item) {
            if ($item->selectable) {
                $lists[$item->id] = $item->taskCount === null
                    ? $item->name
                    : $this->translator->trans('task_board.sync.browser.list_count', ['%name%' => $item->name, '%count%' => (string) $item->taskCount], 'NowoTaskBoardBundle');
            }
        }

        return $this->container->get('form.factory')->createNamed(ConnectionLinkFormType::NAME, ContainerLevelFormType::class, null, ['lists' => $lists]);
    }

    private function pickProvider(string $id): ?TaskProviderInterface
    {
        if ($id !== '') {
            return $this->providers->has($id) ? $this->providers->get($id) : null;
        }

        return $this->providers->all()[0] ?? null;
    }

    /**
     * @param list<ConnectionChoice> $choices
     */
    private function pickConnection(array $choices, string $ref): ?ConnectionChoice
    {
        foreach ($choices as $choice) {
            if ($ref === '' || $choice->ref === $ref) {
                return $choice;
            }
        }

        return null;
    }

    private function backToPage(string $boardId, ?TaskProviderInterface $provider = null, ?ConnectionChoice $choice = null): RedirectResponse
    {
        $params = ['boardId' => $boardId];
        if ($provider instanceof TaskProviderInterface && $choice instanceof ConnectionChoice) {
            $params += ['provider' => $provider->id(), 'connection' => $choice->ref];
        }

        return $this->redirectToRoute($this->routes['board_connection']['name'], $params);
    }

    /**
     * The host's page to add or replace a token (audit EG-I10): an `https://` URL or a same-origin path, else none.
     */
    private function credentialFormUrl(TaskBoard $board, TaskProviderInterface $provider): ?string
    {
        $url = $this->credentials->credentialFormUrl($board, $provider->id());

        return $url !== null && preg_match('~^(?:https://[^\s/?#@\\\\]+|/(?![/\\\\]))[^\s\x00-\x1f\x7f\\\\]*$~Di', $url) === 1 ? $url : null;
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * The board, when the user may manage its connection; 404 otherwise (the connection pages do not reveal that a
     * board exists or is linked).
     */
    private function findManagedBoard(string $boardId, UserInterface $user): TaskBoard
    {
        $board = $this->boardRepository->findById($boardId);
        if (!$board instanceof TaskBoard || $this->tenantContext?->owns($board, $user) === false
            || !$this->resourceAccessChecker->canManageBoardConnection($user, $board)) {
            throw new NotFoundHttpException('Board not found.');
        }

        return $board;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function csrfForm(string $tokenId): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => $tokenId]);
    }

    private function denyUnlessValidCsrf(Request $request, string $tokenId): void
    {
        $form = $this->csrfForm($tokenId);
        $form->submit($request->request->all());
        if (!$form->isValid()) {
            $this->logRefused('csrf_invalid');

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Since 1.25 (audit EX-D1): the id a web-triggered run acts as. A logged-in user whose id cannot be read (no
     * `getId()`, or neither scalar nor `Stringable`) is refused instead of silently running as the board's linker.
     */
    private function actorId(UserInterface $user): string
    {
        $id = ConnectionCreatorActorResolver::idOf($user);
        if ($id === null || $id === '') {
            $this->logRefused('actor_id_missing');

            throw $this->createAccessDeniedException('The user has no id.');
        }

        return $id;
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
