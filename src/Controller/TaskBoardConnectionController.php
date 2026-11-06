<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\ConnectionLinkFormType;
use Nowo\TaskBoardBundle\Form\ConnectionPeopleFormerFormType;
use Nowo\TaskBoardBundle\Form\ConnectionPeopleFormType;
use Nowo\TaskBoardBundle\Form\ConnectionRefreshFormType;
use Nowo\TaskBoardBundle\Form\ContainerLevelFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncRequest;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\SyncBusyException;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncRunnerInterface;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingDeniedException;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingRow;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingService;
use Nowo\TaskBoardBundle\Sync\People\PersonFilter;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\FieldCoverageProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\SyncTaskDetailsWriter;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\Sync\Time\TimeEntryPhase;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\InputBag;
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
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_numeric;
use function is_object;
use function is_string;
use function max;
use function min;
use function preg_match;
use function round;
use function serialize;
use function str_starts_with;
use function substr;
use function unserialize;
use function usort;

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
        // Since 1.27.0 (TB-SYNC-2): the People page / step.
        private readonly ?PeopleMappingService $peopleMapping = null,
        private readonly ?TaskSyncPeopleDirectoryService $peopleDirectories = null,
        private readonly ?TaskSyncPeopleScanner $peopleScanner = null,
        private readonly ?TaskSyncPersonRepository $peopleRepository = null,
        private readonly bool $peopleStep = false,
        private readonly int $peoplePageSize = 50,
        private readonly bool $peopleBulkFormer = false,
        // Since 1.28.0: the bundle's own link page offers `unmatchedColumns` (it is always accepted when posted).
        private readonly bool $unmatchedColumnsChoice = false,
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
            'linkForm'          => $this->linkFormView($linkForm),
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
        /** @var array{container: string, createMissingColumns: bool, fallbackColumnId: string|null, unmatchedColumns: string|null, importComments: bool, importAttachments: bool, archiveRemoteDeletions: bool} $values */
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

        $connection = $this->sync->link($board, $provider, $credential, $container, $user, [
            'createMissingColumns'   => (bool) $values['createMissingColumns'],
            'fallbackColumnId'       => $values['fallbackColumnId'],
            'importComments'         => (bool) $values['importComments'],
            'importAttachments'      => (bool) $values['importAttachments'],
            'archiveRemoteDeletions' => (bool) $values['archiveRemoteDeletions'],
            // Since 1.28.0: only the non-default choice is stored.
        ] + ($values['unmatchedColumns'] === TaskBoardConnection::UNMATCHED_DELETE ? ['unmatchedColumns' => TaskBoardConnection::UNMATCHED_DELETE] : []));
        $this->logWrite('sync_link', ['board_id' => $boardId, 'provider' => $provider->id()]);
        if ($this->peopleStepScan($board, $connection, $provider, $credential, $user)) {
            // Since 1.27.0 (TB-SYNC-2): the People step comes first; its save starts the import.
            $this->addFlash('success', $this->translator->trans('task_board.sync.people.flash.step', ['%list%' => $container->name], 'NowoTaskBoardBundle'));

            return $this->redirectToRoute($this->routes['board_connection_people']['name'], ['boardId' => $boardId]);
        }
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
            // Since 1.27.0 (TB-SYNC-2): people of the connection nobody mapped or decided yet.
            'unmappedPeople' => $this->peopleSummary($board, $connection)['unreviewed'] ?? 0,
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

    /**
     * Since 1.27.0 (TB-SYNC-2): the People page — every remote person of the connection's directory with a host-user
     * select (pre-filled by e-mail, then by name). Managers only (remote e-mails are shown here).
     */
    public function people(string $boardId, Request $request): Response
    {
        $user       = $this->requireUser();
        $board      = $this->findManagedBoard($boardId, $user);
        $connection = $this->linkedConnection($board);
        try {
            return $this->render($this->templates['connection_people'], $this->peopleView($board, $connection, $user, $request->query));
        } catch (PeopleMappingDeniedException) {
            throw new NotFoundHttpException('Board not found.');
        }
    }

    /**
     * Since 1.27.0 (TB-SYNC-2): saves the People page (changed rows are re-applied to the imported tasks); during the
     * People step it also starts the first import (`skip` starts it without changing anything).
     */
    public function peopleSave(string $boardId, Request $request): RedirectResponse
    {
        $user       = $this->requireUser();
        $board      = $this->findManagedBoard($boardId, $user);
        $connection = $this->linkedConnection($board);
        $directory  = $connection->getDirectory();
        if (!$directory instanceof TaskSyncDirectory || !$this->peopleMapping instanceof PeopleMappingService) {
            throw new NotFoundHttpException('No people for this connection.');
        }
        $query = ['filter' => $this->peopleFilterName($request->query), 'page' => $this->peoplePage($request->query)];
        try {
            $view = $this->peopleView($board, $connection, $user, $request->query);
            /** @var list<PeopleMappingRow> $rows */
            $rows = $view['rows'];
            /** @var array<string, string> $labels */
            $labels = $view['candidateLabels'];
            $form   = $this->peopleForm($board, $user, $rows, $labels, $query);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                if (!$form->isSubmitted() || $form->getErrors()->count() > 0) {
                    $this->logRefused('csrf_invalid');

                    throw $this->createAccessDeniedException('Invalid CSRF token.');
                }
                $this->addFlash('error', $this->translator->trans('task_board.sync.people.flash.invalid', [], 'NowoTaskBoardBundle'));

                return $this->redirectToRoute($this->routes['board_connection_people']['name'], ['boardId' => $boardId] + $query);
            }
            $choices = [];
            if ($request->request->get('skip') === null) {
                // Audit GW-L4: a row that was not on the page the manager saved stays as it is.
                $submitted = $request->request->all(ConnectionPeopleFormType::NAME);
                foreach ($rows as $row) {
                    if (!array_key_exists(ConnectionPeopleFormType::FIELD_PREFIX . $row->person->getId(), $submitted)) {
                        continue;
                    }
                    $value                          = $form->get(ConnectionPeopleFormType::FIELD_PREFIX . $row->person->getId())->getData();
                    $choices[$row->person->getId()] = is_string($value) ? $value : '';
                }
            }
            $result = $this->peopleMapping->save($directory, $board, $choices, $user);
        } catch (PeopleMappingDeniedException) {
            throw new NotFoundHttpException('Board not found.');
        }
        $this->logWrite('sync_people_save', ['board_id' => $boardId, 'changed' => count($result->changes), 'invited' => $result->invited, 'pending' => $result->pending, 'refused' => count($result->errors)]);
        $this->addFlash('success', $this->translator->trans('task_board.sync.people.flash.saved', ['%changed%' => (string) count($result->changes), '%invited%' => (string) $result->invited, '%pending%' => (string) $result->pending], 'NowoTaskBoardBundle'));
        if ($result->errors !== []) {
            $this->addFlash('error', $this->translator->trans('task_board.sync.people.flash.refused', ['%count%' => (string) count($result->errors), '%reasons%' => implode(', ', array_map(fn (string $code): string => $this->translator->trans('task_board.sync.people.error.' . $code, [], 'NowoTaskBoardBundle'), array_values(array_unique($result->errors))))], 'NowoTaskBoardBundle'));
        }
        if ($this->firstImportPending($connection)) {
            $this->runner->dispatch(new SyncRequest($boardId, $this->actorId($user), SyncTrigger::Manual));
            $this->addFlash('success', $this->translator->trans('task_board.sync.flash.refresh_started', [], 'NowoTaskBoardBundle'));

            return $this->backToPage($boardId);
        }

        return $this->redirectToRoute($this->routes['board_connection_people']['name'], ['boardId' => $boardId] + $query);
    }

    /**
     * Since 1.28.0 (`sync.people_bulk_former`): every person nobody decided yet becomes a former person. The rows stay
     * changeable person by person; during the People step the page's save still starts the import.
     */
    public function peopleFormer(string $boardId, Request $request): RedirectResponse
    {
        $user       = $this->requireUser();
        $board      = $this->findManagedBoard($boardId, $user);
        $connection = $this->linkedConnection($board);
        $directory  = $connection->getDirectory();
        if (!$this->peopleBulkFormer || !$directory instanceof TaskSyncDirectory || !$this->peopleMapping instanceof PeopleMappingService) {
            throw new NotFoundHttpException('No people for this connection.');
        }
        $form = $this->peopleFormerForm($board);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->logRefused('csrf_invalid');

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $changed = $this->peopleMapping->markUnmappedAsFormer($directory, $board, $user);
        } catch (PeopleMappingDeniedException) {
            throw new NotFoundHttpException('Board not found.');
        }
        $this->logWrite('sync_people_former', ['board_id' => $boardId, 'changed' => $changed]);
        $this->addFlash('success', $this->translator->trans('task_board.sync.people.flash.former', ['%count%' => (string) $changed], 'NowoTaskBoardBundle'));

        return $this->redirectToRoute($this->routes['board_connection_people']['name'], ['boardId' => $boardId]);
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
            'peopleSummary'    => $this->peopleSummary($board, $connection),
            // Since 1.27.0 (TB-SYNC-3): what the latest run imported, field by field.
            'completeness' => $this->completeness($connection, $report),
        ];
    }

    /**
     * Since 1.27.0 (TB-SYNC-3): per answer key of the latest run, the tasks that had it and whether it is imported
     * (`imported`), why not (the provider's reason) or `not_imported_yet` (a key the provider does not declare).
     *
     * @return list<array{key: string, tasks: int, status: string}>
     */
    private function completeness(TaskBoardConnection $connection, ?SyncReport $report): array
    {
        $provider = $this->providers->has($connection->getProvider()) ? $this->providers->get($connection->getProvider()) : null;
        $coverage = $provider instanceof FieldCoverageProviderInterface ? $provider->fieldCoverage() : [];
        $rows     = [];
        foreach ($report->stats ?? [] as $name => $tasks) {
            if (str_starts_with($name, 'keys.')) {
                $key    = substr($name, 5);
                $rows[] = ['key' => $key, 'tasks' => $tasks, 'status' => $coverage[$key] ?? 'not_imported_yet'];
            }
        }
        // Since 1.27.0: what the run could not read is said, whatever the provider declares for the key.
        $stats = $report->stats ?? [];
        // Audit GWd-L3: from what each task still has unread, so the next incremental run does not hide it.
        $pending = $this->store->pendingReads($connection);
        foreach ([
            [TaskSyncItem::PENDING_ATTACHMENTS, 'attachments', 'not_read'],
            [TaskSyncItem::PENDING_REPLIES, 'comment_replies', 'not_read'],
            [TaskSyncItem::PENDING_DETAIL, 'task_detail', 'not_read'],
            [TaskSyncItem::PENDING_DETAIL_REFUSED, 'task_detail', 'unavailable'],
        ] as [$bit, $key, $status]) {
            if (isset($pending[$bit])) {
                $rows[] = ['key' => $key, 'tasks' => $pending[$bit], 'status' => $status];
            }
        }
        if (($stats[SyncTaskDetailsWriter::STAT_STATUS_FORBIDDEN] ?? 0) > 0) {
            $rows[] = ['key' => 'time_in_status', 'tasks' => $stats[SyncTaskDetailsWriter::STAT_STATUS_FORBIDDEN] + ($stats[SyncTaskDetailsWriter::STAT_STATUS_SKIPPED] ?? 0), 'status' => 'unavailable'];
        }
        // Since 1.28.0: hours of people with no host user, when the sink takes mapped people only: never silent.
        if ($report instanceof SyncReport && $report->time->skippedUnmapped > 0) {
            $rows[] = ['key' => 'time_entries_unmapped', 'tasks' => $report->time->skippedUnmapped, 'status' => 'pending_person', 'hours' => round(($stats[TimeEntryPhase::STAT_UNMAPPED_SECONDS] ?? 0) / 3600, 2)];
        }
        // Not imported yet first, then the reasons, then what is imported; by key inside each.
        $rank = static fn (string $status): int => match ($status) {
            'not_imported_yet', 'not_read', 'unavailable', 'pending_person' => 0,
            FieldCoverageProviderInterface::IMPORTED                        => 2,
            default                                                         => 1,
        };
        usort($rows, static fn (array $a, array $b): int => [$rank($a['status']), $a['key']] <=> [$rank($b['status']), $b['key']]);

        return $rows;
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
     * @param FormInterface<array<string, mixed>>|null $form
     */
    private function linkFormView(?FormInterface $form): ?FormView
    {
        $view = $form?->createView();
        if ($view instanceof FormView && !$this->unmatchedColumnsChoice) {
            $view['unmatchedColumns']->setRendered();
        }

        return $view;
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
            'unmatchedColumns'       => TaskBoardConnection::UNMATCHED_KEEP,
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

    private function linkedConnection(TaskBoard $board): TaskBoardConnection
    {
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection || !$this->peopleMapping instanceof PeopleMappingService) {
            throw new NotFoundHttpException('The board is not linked.');
        }

        return $connection;
    }

    /** The first import has not started yet (People step): saving the People page starts it. */
    private function firstImportPending(TaskBoardConnection $connection): bool
    {
        return $this->peopleStep && !$connection->getLastRunAt() instanceof DateTimeImmutable && $connection->getRunningRunId() === null;
    }

    /**
     * Since 1.27.0: with `sync.people.step`, attaches the directory and scans the source's people after the link.
     * False (start the import at once) when the step is off, the provider has no people or the scan fails.
     */
    private function peopleStepScan(TaskBoard $board, TaskBoardConnection $connection, TaskProviderInterface $provider, ProviderCredential $credential, UserInterface $user): bool
    {
        if (!$this->peopleStep || !$this->peopleDirectories instanceof TaskSyncPeopleDirectoryService || !$this->peopleScanner instanceof TaskSyncPeopleScanner || !$provider instanceof PeopleProviderInterface) {
            return false;
        }
        try {
            $directory = $this->peopleDirectories->directoryFor($connection, $provider, $credential);
            if (!$directory instanceof TaskSyncDirectory) {
                return false;
            }
            $this->peopleScanner->scan($board, $provider, $credential, $directory->getWorkspaceId(), $connection->getLocation(), $user, true);
        } catch (ProviderException $exception) {
            $this->addFlash('error', $this->translator->trans('task_board.sync.error.' . $exception->errorCode->value, [], 'NowoTaskBoardBundle'));

            return false;
        }

        return true;
    }

    /**
     * Since 1.27.0: the People counts of the connection page and the progress endpoint (null without a directory).
     *
     * @return array{unreviewed: int, mapped: int, total: int, url: string}|null
     */
    private function peopleSummary(TaskBoard $board, TaskBoardConnection $connection): ?array
    {
        $directory = $connection->getDirectory();
        if (!$directory instanceof TaskSyncDirectory || !$this->peopleRepository instanceof TaskSyncPersonRepository || !$this->peopleMapping instanceof PeopleMappingService) {
            return null;
        }
        $counts = $this->peopleRepository->mappingCounts($directory);

        return [
            'unreviewed' => $this->peopleRepository->unreviewedCount($directory),
            'mapped'     => ($counts[TaskSyncPersonMapping::Auto->value] ?? 0) + ($counts[TaskSyncPersonMapping::Manual->value] ?? 0),
            'total'      => array_sum($counts),
            'url'        => $this->generateUrl($this->routes['board_connection_people']['name'], ['boardId' => $board->getId()]),
        ];
    }

    /**
     * @param InputBag<string> $query
     *
     * @return array<string, mixed>
     */
    private function peopleView(TaskBoard $board, TaskBoardConnection $connection, UserInterface $user, InputBag $query): array
    {
        $directory = $connection->getDirectory();
        $filter    = $this->peopleFilterName($query);
        $page      = $this->peoplePage($query);
        $rows      = [];
        $labels    = [];
        $hasNext   = false;
        $form      = null;
        if ($directory instanceof TaskSyncDirectory && $this->peopleMapping instanceof PeopleMappingService) {
            $this->peopleDirectories?->assertCanManage($directory, $user, $board);
            $candidates = $this->peopleMapping->candidates($board);
            foreach ($candidates as $id => $candidate) {
                $labels[$id] = UserLabel::option($candidate);
                // Since 1.28.0: said in the option, so a manager knows the user gets no access from this mapping.
                if ($this->peopleMapping->isAttributionOnly($board, $candidate)) {
                    $labels[$id] = $this->translator->trans('task_board.sync.people.attribution_only', ['%user%' => $labels[$id]], 'NowoTaskBoardBundle');
                }
            }
            $rows    = $this->peopleMapping->rows($directory, $candidates, $filter === 'unmapped' ? PersonFilter::mappings(TaskSyncPersonMapping::Unmatched, TaskSyncPersonMapping::Suggested) : new PersonFilter(), $this->peoplePageSize + 1, ($page - 1) * $this->peoplePageSize);
            $hasNext = count($rows) > $this->peoplePageSize;
            $rows    = array_slice($rows, 0, $this->peoplePageSize);
            $form    = $this->peopleForm($board, $user, $rows, $labels, ['filter' => $filter, 'page' => $page])->createView();
        }

        return [
            'board'              => $board,
            'connection'         => $connection,
            'provider'           => $this->providers->has($connection->getProvider()) ? $this->providers->get($connection->getProvider()) : null,
            'directory'          => $directory,
            'rows'               => $rows,
            'candidateLabels'    => $labels,
            'peopleForm'         => $form,
            'filter'             => $filter,
            'page'               => $page,
            'hasNext'            => $hasNext,
            'peopleSummary'      => $this->peopleSummary($board, $connection),
            'pendingReassigns'   => $directory instanceof TaskSyncDirectory && $this->peopleRepository instanceof TaskSyncPersonRepository ? count($this->peopleRepository->pendingReassigns($directory)) : 0,
            'firstImportPending' => $this->firstImportPending($connection),
            'peopleFormerForm'   => $this->peopleBulkFormer && $directory instanceof TaskSyncDirectory && $this->peopleRepository instanceof TaskSyncPersonRepository && $this->peopleRepository->unreviewedCount($directory) > 0 ? $this->peopleFormerForm($board)->createView() : null,
            'dashboardRoute'     => $this->dashboardRoute,
            'templates'          => $this->templates,
        ];
    }

    /**
     * @return FormInterface<mixed>
     */
    private function peopleFormerForm(TaskBoard $board): FormInterface
    {
        return $this->container->get('form.factory')->createNamed(ConnectionPeopleFormerFormType::NAME, ConnectionPeopleFormerFormType::class, null, [
            'action' => $this->generateUrl($this->routes['board_connection_people_former']['name'], ['boardId' => $board->getId()]),
        ]);
    }

    /**
     * @param list<PeopleMappingRow> $rows
     * @param array<string, string> $labels
     * @param array{filter: string, page: int} $query
     *
     * @return FormInterface<mixed>
     */
    private function peopleForm(TaskBoard $board, UserInterface $user, array $rows, array $labels, array $query): FormInterface
    {
        return $this->container->get('form.factory')->createNamed(ConnectionPeopleFormType::NAME, ConnectionPeopleFormType::class, null, [
            'action'           => $this->generateUrl($this->routes['board_connection_people_save']['name'], ['boardId' => $board->getId()] + $query),
            'rows'             => $rows,
            'candidates'       => $labels,
            'invite'           => $this->peopleMapping?->canInvite($board, $user) === true,
            'not_mapped_label' => $this->translator->trans('task_board.sync.people.not_mapped', [], 'NowoTaskBoardBundle'),
            'invite_label'     => $this->translator->trans('task_board.sync.people.invite', [], 'NowoTaskBoardBundle'),
        ]);
    }

    /**
     * @param InputBag<string> $query
     */
    private function peopleFilterName(InputBag $query): string
    {
        return $query->get('filter') === 'unmapped' ? 'unmapped' : 'all';
    }

    /**
     * @param InputBag<string> $query
     */
    private function peoplePage(InputBag $query): int
    {
        $page = $query->get('page');

        return is_numeric($page) ? max(1, min(10_000, (int) $page)) : 1;
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
