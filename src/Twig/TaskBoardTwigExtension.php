<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Board\BoardTenantLabelProviderInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;
use Nowo\TaskBoardBundle\Import\ImportHelpLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\IconNames;
use Nowo\TaskBoardBundle\Support\SubtaskProgress;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Sync\View\ConnectionBadgeProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncFieldViewProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncPersonView;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Text\PlainTaskTextRenderer;
use Nowo\TaskBoardBundle\Text\TaskTextRendererInterface;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\Twig\ThemeExtension;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Table;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\DeprecatedCallableInfo;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function array_keys;
use function array_map;
use function array_unshift;
use function count;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_replace;
use function strtolower;
use function strtr;
use function trigger_deprecation;
use function trim;
use function usort;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PHP_URL_SCHEME;

/**
 * Twig globals for the TaskBoard manage Web UI (REQ-UI-001).
 *
 * @phpstan-import-type HeaderSpec from TaskBoardPages
 */
final class TaskBoardTwigExtension extends AbstractExtension implements GlobalsInterface
{
    /** Since 1.26: the UiKit theme bundle key (`nowo_ui_kit.theme.bundles.NowoTaskBoardBundle`). */
    public const THEME_BUNDLE = 'NowoTaskBoardBundle';

    /**
     * @param array<string, array{path?: string, name?: string}> $routes Configured `routes` tree (logical key => path/name)
     */
    public function __construct(
        private readonly array $routes = [],
        private readonly TaskObjectLinkResolver $linkResolver = new TaskObjectLinkResolver(),
        private readonly ?string $filtersCollapse = null,
        private readonly string $filtersFormClass = '',
        private readonly TaskTextRendererInterface $textRenderer = new PlainTaskTextRenderer(),
        // Since 1.23: the provider connection of a board (null when `sync.enabled` is false).
        private readonly ?ConnectionBadgeProvider $connectionBadges = null,
        // Since 1.23: the avatar URLs of the request (null: no pictures).
        private readonly ?TaskMemberAvatars $avatars = null,
        // Since 1.25: remote people of synced tasks (null when `sync.enabled` is false).
        private readonly ?SyncPeopleViewProvider $syncPeople = null,
        // Since 1.25: templates.connection_badge (the board, list and Gantt headers include it).
        private readonly string $connectionBadgeTemplate = '@NowoTaskBoardBundle/manage/_connection_badge.html.twig',
        // Since 1.25.2: sync.badge.connect_link (false = the badge of an unlinked board renders nothing).
        private readonly bool $syncBadgeConnectLink = true,
        // Since 1.27.0 (TB-SYNC-3): typed custom fields of synced tasks (null when `sync.enabled` is false).
        private readonly ?SyncFieldViewProvider $syncFields = null,
        // Since 1.30.0 (spec 211).
        private readonly ?BoardTenantLabelProviderInterface $tenantLabels = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        // Since 2.0.0: `import.help_link`.
        private readonly ImportHelpLinkResolver $importHelpLink = new ImportHelpLinkResolver(),
        // Since 2.0.0: the layout of the pages and their headers.
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        // Since 2.0.0 (TB-3): templates.task_time_before, included before the task's Time section.
        private readonly ?string $taskTimeBefore = null,
        private readonly ?UrlGeneratorInterface $urls = null,
        // Since 2.0.0 (TB-10): templates.page_header, board.toolbar and task_panel.toolbar.
        private readonly bool $pageHeader = true,
        private readonly bool $boardToolbar = true,
        private readonly bool $panelToolbar = true,
        // Since 2.1.0 (spec 214): `task_numbers.enabled` and `calendar.enabled`.
        private readonly bool $taskNumbersEnabled = false,
        private readonly bool $calendarEnabled = false,
        // Since 2.3.0 (spec 217): `move.enabled`, and who may edit a task and its board.
        private readonly bool $moveEnabled = false,
        private readonly ?TaskBoardResourceAccessChecker $resourceAccess = null,
        // Since 2.3.0 (spec 215): set with `recurrence.enabled` only.
        private readonly ?TaskRecurrenceView $recurrence = null,
        // Since 2.3.0 (spec 216): `history.cause_labeler`.
        private readonly ?TaskChangeCauseLabelerInterface $causeLabeler = null,
        // Since 2.5.0 (TB-16): `sync.badge.style` (badge | strip).
        private readonly string $syncBadgeStyle = 'badge',
        // Since 2.10.0 (spec 216): `comments.cause_labeler`.
        private readonly ?TaskCommentCauseLabelerInterface $commentCauseLabeler = null,
    ) {
    }

    /** Since 2.10.0 (spec 216, FR-014): the host's label of why a comment was written; null without a cause or a labeler. */
    public function commentCause(TaskComment $comment): ?string
    {
        $cause = $comment->getCause();

        return $cause !== null && $this->commentCauseLabeler instanceof TaskCommentCauseLabelerInterface ? $this->commentCauseLabeler->label($cause, $comment) : null;
    }

    /** Since 2.3.0 (spec 216, FR-013): the host's label of why a history row happened; null without a cause or a labeler. */
    public function changeCause(TaskChangeHistory $entry): ?string
    {
        $cause = $entry->getCause();

        return $cause !== null && $this->causeLabeler instanceof TaskChangeCauseLabelerInterface ? $this->causeLabeler->label($cause, $entry) : null;
    }

    /**
     * Since 2.3.0 (spec 215): the *Repeat* section of a task, or null with `recurrence.enabled: false` (nothing rendered).
     *
     * @return array{series: TaskRecurrence|null, upcoming: list<DateTimeImmutable>, canEdit: bool, synced: bool}|null
     */
    public function recurrence(Task $task): ?array
    {
        return $this->recurrence?->of($task);
    }

    /** Since 2.3.0 (spec 215): whether the card or row of a task shows the repeat icon. */
    public function repeats(Task $task): bool
    {
        return $this->recurrence instanceof TaskRecurrenceView && $this->recurrence->repeats($task);
    }

    /**
     * Since 2.3.0 (spec 217, FR-005): whether the task panel offers *Move to another board* and *Duplicate*. False with
     * `move.enabled: false`, without a signed-in user, or when the user may not edit the task and its board, so nothing is
     * rendered.
     */
    public function canCopy(Task $task): bool
    {
        if (!$this->moveEnabled) {
            return false;
        }
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface && $this->resourceAccess instanceof TaskBoardResourceAccessChecker
            && $this->resourceAccess->canEditTask($user, $task) && $this->resourceAccess->canEditBoard($user, $task->getBoard());
    }

    /**
     * Since 1.27.0: the typed custom fields of a synced task ([] without sync).
     *
     * @return list<array{name: string, type: string, value: mixed, text: string, choices: list<array{id: string, name: string, color: string|null}>, config: array<string, int|string>}>
     */
    public function syncFields(Task $task): array
    {
        return $this->syncFields?->fieldsOf($task) ?? [];
    }

    /**
     * Since 1.27.0: the provider's details of a synced task ([] without sync or for a local task).
     *
     * @return array<string, mixed>
     */
    public function syncDetails(Task $task): array
    {
        return $this->syncPeople?->detailsOf($task) ?? [];
    }

    /**
     * Since 1.27.0: what the list search finds for the tasks drawn (task id => line; [] without sync). Reads only these
     * tasks: call it once per group of rows.
     *
     * @param iterable<Task> $tasks
     *
     * @return array<string, string>
     */
    public function syncSearchTexts(iterable $tasks): array
    {
        return $this->syncFields?->searchTextsOf($tasks) ?? [];
    }

    /**
     * Since 1.27.0: thread and action item of an imported comment (null without sync or for a local comment).
     *
     * @return array{reply: bool, replyTo: SyncPersonView|null, resolved: bool|null, assignee: SyncPersonView|null, remoteEditedAt: DateTimeImmutable|null}|null
     */
    public function syncCommentMeta(TaskComment $comment): ?array
    {
        return $this->syncPeople?->commentMetaOf($comment);
    }

    /** Since 1.25: the remote creator of a synced task (null without sync or for a local task). */
    public function syncCreator(Task $task): ?SyncPersonView
    {
        return $this->syncPeople?->creatorOf($task);
    }

    /** Since 1.25: the remote author of an imported comment (null without sync or for a local comment). */
    public function syncCommentAuthor(TaskComment $comment): ?SyncPersonView
    {
        return $this->syncPeople?->commentAuthorOf($comment);
    }

    /** Since 1.25: a history token (`person:<id>` / `user:<id>`) as a label; the token itself without sync. */
    public function syncToken(?string $token): string
    {
        return $this->syncPeople?->tokenLabel((string) $token) ?? (string) $token;
    }

    /**
     * Since 1.25: people assigned (`assignee`) or watching (`watcher`) in the provider who are not task members.
     *
     * @return list<SyncPersonView>
     */
    public function syncUnmatched(Task $task, string $role = 'assignee'): array
    {
        return $this->syncPeople?->unmatchedOf($task, $role) ?? [];
    }

    /**
     * Since 1.23: the sanitized, memoised picture URL of a task member; null = the initial chip.
     */
    public function memberAvatarUrl(?object $user): ?string
    {
        return $this->avatars?->urlOf($user);
    }

    /**
     * Since 1.23: what the board header shows about the board's provider connection; null = nothing.
     *
     * @return array<string, mixed>|null
     */
    public function connection(TaskBoard $board): ?array
    {
        return $this->connectionBadges?->forBoard($board);
    }

    /**
     * Since 2.1.0 (spec 214, FR-009): a task's number as it is shown (`#123`); null with `task_numbers.enabled` off or no number yet.
     */
    public function taskNumber(Task|int|null $task): ?string
    {
        $number = $task instanceof Task ? $task->getNumber() : $task;

        return $this->taskNumbersEnabled && $number !== null ? '#' . $number : null;
    }

    /**
     * Since 2.1.0 (spec 214): the calendar's header tab, for the board, list and Gantt pages; none with `calendar.enabled` off.
     *
     * @return list<array<string, mixed>>
     */
    public function calendarTabs(TaskBoard $board, string $label): array
    {
        if (!$this->calendarEnabled || !$this->urls instanceof UrlGeneratorInterface) {
            return [];
        }

        return [['href' => $this->urls->generate($this->routeName('calendar'), ['boardId' => $board->getId()]), 'icon' => 'calendar', 'label' => $label]];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_task_board_route', $this->routeName(...)),
            new TwigFunction('nowo_task_board_link_label', $this->linkResolver->label(...)),
            new TwigFunction('nowo_task_board_link_url', $this->linkResolver->url(...)),
            new TwigFunction('nowo_task_board_link_badge', $this->linkBadge(...)),
            new TwigFunction('nowo_task_board_subtask_progress', $this->subtaskProgress(...)),
            new TwigFunction('nowo_task_board_deviation', TaskDeviation::of(...)),
            new TwigFunction('nowo_task_board_ancestors', self::ancestors(...)),
            new TwigFunction('nowo_task_board_comment_body', $this->renderCommentBody(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_task_board_text', $this->renderText(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_task_board_connection', $this->connection(...)),
            new TwigFunction('task_board_sync_creator', $this->syncCreator(...)),
            new TwigFunction('task_board_sync_comment_author', $this->syncCommentAuthor(...)),
            new TwigFunction('task_board_sync_unmatched', $this->syncUnmatched(...)),
            new TwigFunction('task_board_sync_token', $this->syncToken(...)),
            new TwigFunction('task_board_sync_fields', $this->syncFields(...)),
            new TwigFunction('task_board_sync_search_texts', $this->syncSearchTexts(...)),
            new TwigFunction('task_board_sync_comment_meta', $this->syncCommentMeta(...)),
            new TwigFunction('task_board_sync_details', $this->syncDetails(...)),
            new TwigFunction('nowo_task_board_member_avatar_url', $this->memberAvatarUrl(...)),
            new TwigFunction('nowo_task_board_btn_class', $this->buttonClass(...), ['needs_environment' => true, 'needs_context' => true, 'deprecation_info' => new DeprecatedCallableInfo('nowo-tech/task-board-bundle', '2.0', 'nowo_ui_class')]),
            // Since 2.0.0: the UiKit page contract.
            new TwigFunction('nowo_task_board_page_layout', $this->pages->layout(...), ['needs_environment' => true]),
            new TwigFunction('nowo_task_board_legacy_layout', $this->pages->legacyLayout(...), ['needs_environment' => true]),
            new TwigFunction('nowo_task_board_legacy_blocks', $this->pages->legacyBlocks(...), ['needs_environment' => true]),
            new TwigFunction('nowo_task_board_header', $this->pageHeader(...), ['needs_context' => true]),
            new TwigFunction('nowo_task_board_subtask_nodes', $this->subtaskNodes(...)),
            new TwigFunction('nowo_task_board_list_table', $this->listTable(...)),
            // Since 1.30.0 (spec 211).
            new TwigFunction('nowo_task_board_description', $this->boardDescription(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_task_board_label', self::boardLabel(...)),
            new TwigFunction('nowo_task_board_tenant_labels', $this->tenantLabelsOf(...)),
            // Since 2.0.0: `import.help_link`, resolved per render.
            new TwigFunction('nowo_task_board_configured_help_link', $this->importHelpLink->resolve(...)),
            // Since 2.1.0 (spec 214).
            new TwigFunction('nowo_task_board_number', $this->taskNumber(...)),
            new TwigFunction('nowo_task_board_numbers_enabled', fn (): bool => $this->taskNumbersEnabled),
            new TwigFunction('nowo_task_board_calendar_enabled', fn (): bool => $this->calendarEnabled),
            new TwigFunction('nowo_task_board_calendar_tabs', $this->calendarTabs(...)),
            new TwigFunction('nowo_task_board_can_copy', $this->canCopy(...)),
            new TwigFunction('nowo_task_board_recurrence', $this->recurrence(...)),
            new TwigFunction('nowo_task_board_repeats', $this->repeats(...)),
            new TwigFunction('nowo_task_board_change_cause', $this->changeCause(...)),
            new TwigFunction('nowo_task_board_comment_cause', $this->commentCause(...)),
            // Since 2.9.0 (audit JI-L3): a Tabler name as the icon set spells it (Bootstrap Icons names differ).
            new TwigFunction('nowo_task_board_icon_name', IconNames::forSet(...)),
            new TwigFunction('nowo_task_board_column_icon_name', IconNames::columnIcon(...)),
        ];
    }

    /**
     * The classes of a theme slot of the bundle.
     *
     * @deprecated since 2.0.0, use `nowo_ui_class(slot, 'NowoTaskBoardBundle')`. Removed in 3.0.
     *
     * @param array<string, mixed> $context
     */
    public function buttonClass(Environment $env, array $context, string $slot): string
    {
        trigger_deprecation('nowo-tech/task-board-bundle', '2.0', 'The Twig function "nowo_task_board_btn_class" is deprecated, use "nowo_ui_class(slot, \'%s\')".', self::THEME_BUNDLE);

        return $env->getExtension(ThemeExtension::class)->slotClass($context, $slot, self::THEME_BUNDLE);
    }

    /**
     * Since 2.0.0: the page header of a page (Twig `nowo_task_board_header`), see {@see TaskBoardPages::header()}.
     *
     * @param array<string, mixed> $context
     * @param HeaderSpec $spec
     */
    public function pageHeader(array $context, array $spec): PageHeader
    {
        $iconSet = $context['nowo_ui_kit_icon_set'] ?? null;

        return TaskBoardPages::header($spec, is_string($iconSet) ? $iconSet : 'tabler-icons');
    }

    /**
     * Since 2.0.0: subtasks as the nodes of UiKit's `tree` partial, every group expanded. `taskTree` avoids a query per node.
     *
     * @param iterable<Task> $tasks
     *
     * @return list<array<string, mixed>>
     */
    public function subtaskNodes(iterable $tasks, ?TaskTree $taskTree = null, bool $panel = false, string $panelTargetPath = ''): array
    {
        $nodes = [];
        foreach ($tasks as $task) {
            $link = ['class' => 'nowo-task-subtree__title', 'data-title' => $task->getTitle(), 'data-nowo-task-title' => $task->getTitle()];
            if ($panel && $this->urls instanceof UrlGeneratorInterface) {
                $link['data-task-panel-url'] = $this->urls->generate($this->routeName('task_panel'), ['taskId' => $task->getId(), '_target_path' => $panelTargetPath]);
            }
            $children = $taskTree instanceof TaskTree ? $taskTree->childrenOf($task) : $task->getSubtasks();
            $nodes[]  = [
                'id'        => $task->getId(),
                'label'     => $task->getTitle(),
                'href'      => $this->urls?->generate($this->routeName('task'), ['taskId' => $task->getId()]) ?? '#',
                'expanded'  => true,
                'children'  => $this->subtaskNodes($children, $taskTree, $panel, $panelTargetPath),
                'link_attr' => $link,
                'item_attr' => [
                    'class'        => 'nowo-task-subtree__item' . ($task->isCompleted() ? ' is-done' : ''),
                    'data-task-id' => $task->getId(),
                ] + ($task->getColumn() !== null ? ['data-nowo-task-column' => $task->getColumn()->getName()] : []),
                'task'     => $task,
                'progress' => $taskTree instanceof TaskTree ? $taskTree->progress($task) : SubtaskProgress::of($task),
            ];
        }

        return $nodes;
    }

    /**
     * Since 2.0.0 (K3): the table of one group of the list view. The row attributes carry what the client filters, sort and
     * totals read; the cells come from `_list_row_cells.html.twig`.
     *
     * @param iterable<Task> $tasks
     */
    public function listTable(iterable $tasks, ?string $panelTargetPath = null): Table
    {
        $search = $this->syncSearchTexts($tasks);
        $label  = static fn (string $key): Label => TaskBoardPages::label($key);
        $time   = 'nowo-task-list__time-col nowo-task-list__num';

        return new Table(
            [
                // Since 2.1.0 (spec 214, FR-009).
                ...($this->taskNumbersEnabled ? [new Column('number', $label('task_board.number.label'), class: 'nowo-task-list__num')] : []),
                new Column('title', $label('task_board.form.task.title')),
                new Column('people', $label('task_board.task.people')),
                new Column('due', $label('task_board.form.task.due')),
                new Column('priority', $label('task_board.form.task.priority')),
                new Column('tags', $label('task_board.form.task.tags')),
                new Column('estimate', $label('task_board.time.estimate'), class: $time),
                new Column('spent', $label('task_board.time.spent'), class: $time),
                new Column('deviation', $label('task_board.time.deviation'), class: $time),
            ],
            $tasks,
            responsive: 'scroll',
            bundle: TaskBoardPages::BUNDLE,
            rowAttributes: function (Task $task) use ($search, $panelTargetPath): array {
                $deviation = TaskDeviation::of($task);
                $column    = $task->getColumn()?->getName() ?? '';

                return [
                    'class'                                   => 'nowo-task-list__row' . ($task->isCompleted() ? ' is-done' : ''),
                    'data-nowo-task-board-list-filter-target' => 'row',
                    'data-title'                              => $task->getTitle(),
                    'data-nowo-task-title'                    => $task->getTitle(),
                    'data-nowo-task-column'                   => $column !== '' ? $column : null,
                    // Since 2.1.0 (spec 214, FR-013): the number too.
                    'data-search-extra'   => trim(($search[$task->getId()] ?? '') . ' ' . $this->taskNumber($task)),
                    'data-priority'       => $task->getPriority()->value,
                    'data-column'         => $column,
                    'data-column-name'    => $column,
                    'data-due'            => $task->getDueAt()?->format('Y-m-d') ?? '',
                    'data-task-id'        => $task->getId(),
                    'data-estimate'       => $deviation->hasEstimate() ? $deviation->estimatedMinutes : '',
                    'data-spent'          => $deviation->spentMinutes,
                    'data-over'           => $deviation->isOver() ? '1' : '0',
                    'data-task-panel-url' => $this->urls?->generate($this->routeName('task_panel'), ['taskId' => $task->getId()] + ($panelTargetPath !== null && $panelTargetPath !== '' ? ['_target_path' => $panelTargetPath] : [])),
                ] + ($this->taskNumber($task) !== null ? ['data-number' => $task->getNumber()] : []);
            },
        );
    }

    /**
     * Status badge of a host object link (since 1.10); null for other links.
     */
    public function linkBadge(TaskLink $link): ?TaskObjectBadge
    {
        return $this->linkResolver->badge($link);
    }

    /**
     * Done / total of a task's subtasks at any depth (since 1.10).
     */
    public function subtaskProgress(Task $task): SubtaskProgress
    {
        return SubtaskProgress::of($task);
    }

    /**
     * Since 1.15: the parents of a task from the top-level one down to its direct parent (at most 50 levels, so corrupted
     * cyclic data cannot loop).
     *
     * @return list<Task>
     */
    public static function ancestors(Task $task): array
    {
        $ancestors = [];
        $parent    = $task->getParent();
        while ($parent instanceof Task && count($ancestors) < 50) {
            array_unshift($ancestors, $parent);
            $parent = $parent->getParent();
        }

        return $ancestors;
    }

    /**
     * Since 1.16: a comment's text as HTML — escaped, line breaks kept, its stored mentions ("@Name") as chips
     * (`nowo-task-mention`, `nowo-task-mention--me` for the viewer). No other markup: the body is plain text.
     */
    public static function commentBody(TaskComment $comment, ?string $viewerId = null, TaskTextRendererInterface $renderer = new PlainTaskTextRenderer()): string
    {
        // Since 1.19 the body goes through the text renderer (plain by default, Markdown in a host): the mentions are
        // swapped for private-use placeholders first, so the renderer never sees (or breaks) them, then become chips.
        $text     = $comment->getBody();
        $mentions = $comment->getMentions();
        usort($mentions, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));
        $chips = [];
        foreach ($mentions as $index => $mention) {
            $placeholder         = "\u{E000}" . $index . "\u{E001}";
            $text                = str_replace('@' . $mention['label'], $placeholder, $text);
            $chips[$placeholder] = sprintf(
                '<span class="nowo-task-mention%s" data-user-id="%s">%s</span>',
                $mention['id'] === $viewerId ? ' nowo-task-mention--me' : '',
                htmlspecialchars($mention['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars('@' . $mention['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return strtr($renderer->render($text), $chips);
    }

    /**
     * Since 1.19: a comment body through the configured text renderer (Twig nowo_task_board_comment_body).
     */
    public function renderCommentBody(TaskComment $comment, ?string $viewerId = null): string
    {
        return self::commentBody($comment, $viewerId, $this->textRenderer);
    }

    /**
     * Since 1.30.0 (spec 211): a board's description as HTML, by its format. Markdown goes through the host's text renderer,
     * which must escape what the user typed; plain text is escaped here.
     */
    public function boardDescription(TaskBoard $board): string
    {
        $text = (string) $board->getDescription();

        return $board->getDescriptionFormat() === TaskBoard::FORMAT_MARKDOWN ? $this->textRenderer->render($text) : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Since 1.30.0 (spec 211): "🚀 Roadmap" as text, for a host's breadcrumbs and search. Escape it as any text. */
    public static function boardLabel(TaskBoard $board): string
    {
        return ($board->getIcon() !== null ? $board->getIcon() . ' ' : '') . $board->getName();
    }

    /**
     * Since 1.30.0 (spec 211): the tenants of a list of boards, one provider call. Empty without `board.tenant_label_provider`.
     *
     * @param iterable<TaskBoard> $boards
     *
     * @return array<string, TaskBoardTenant>
     */
    public function tenantLabelsOf(iterable $boards): array
    {
        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$this->tenantLabels instanceof BoardTenantLabelProviderInterface || !$user instanceof UserInterface) {
            return [];
        }
        $keys = [];
        foreach ($boards as $board) {
            if ($board->getTenantKey() !== null) {
                $keys[$board->getTenantKey()] = true;
            }
        }

        return $keys === [] ? [] : $this->tenantLabels->labelsFor($user, array_map(strval(...), array_keys($keys)));
    }

    /**
     * Since 1.19: user text (a plain-text description) through the configured text renderer (Twig nowo_task_board_text).
     */
    public function renderText(?string $text): string
    {
        return $text === null || trim($text) === '' ? '' : $this->textRenderer->render($text);
    }

    /**
     * Maps a logical route key (e.g. "board", "task_move") to the route name configured under
     * `nowo_task_board.routes.<key>.name`, so templates keep working when hosts rename routes.
     */
    public function routeName(string $key): string
    {
        $name = $this->routes[$key]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'nowo_task_board_' . $key;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('nowo_task_board_safe_url', self::safeUrl(...)),
            new TwigFilter('nowo_task_board_safe_color', self::safeColor(...)),
            new TwigFilter('nowo_task_board_tag_list', self::tagList(...)),
            // Since 1.15: minutes as "45 min", "2 h", "1 h 30 min" (signed: "+20 min", "−1 h").
            new TwigFilter('nowo_task_board_duration', TaskDeviation::formatMinutes(...)),
        ];
    }

    /**
     * Render-time guard for link hrefs: only absolute http(s) URLs pass, anything else becomes "#".
     */
    public static function safeUrl(mixed $url): string
    {
        if (!is_string($url)) {
            return '#';
        }

        $url    = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '#';
    }

    /**
     * Render-time guard for colours interpolated into style="…": hex colours only, otherwise null.
     */
    public static function safeColor(mixed $color): ?string
    {
        if (!is_string($color) || preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $color) !== 1) {
            return null;
        }

        return $color;
    }

    /**
     * Decodes a tags change-history value (JSON list written by TaskChangeRecorder) into a list of
     * strings; null, invalid JSON or non-list payloads yield an empty list.
     *
     * @return list<string>
     */
    public static function tagList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    public function getGlobals(): array
    {
        return [
            // Since 1.17: the host's filter fold partial and filter card classes for the list view.
            'nowo_task_board_filters_collapse'   => $this->filtersCollapse,
            'nowo_task_board_filters_form_class' => $this->filtersFormClass,
            // Since 1.25: the configured connection badge partial (templates.connection_badge).
            'nowo_task_board_connection_badge_template' => $this->connectionBadgeTemplate,
            // Since 1.25.2: sync.badge.connect_link.
            'nowo_task_board_sync_badge_connect_link' => $this->syncBadgeConnectLink,
            // Since 2.5.0 (TB-16): sync.badge.style.
            'nowo_task_board_sync_badge_style' => $this->syncBadgeStyle,
            // Since 2.0.0 (TB-3): templates.task_time_before.
            'nowo_task_board_task_time_before' => $this->taskTimeBefore,
            // Since 2.0.0 (TB-10): the switches of the chrome a host draws itself.
            'nowo_task_board_ui' => ['page_header' => $this->pageHeader, 'board_toolbar' => $this->boardToolbar, 'panel_toolbar' => $this->panelToolbar],
        ];
    }
}
