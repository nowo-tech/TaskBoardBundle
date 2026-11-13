<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;
use function usort;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061, `task_templates.enabled`): task templates — create, change, delete, list and
 * apply. Every write asks {@see TaskTemplatePermissionInterface::canManage()} first ({@see AccessDeniedException} when
 * refused) and checks the input ({@see InvalidTaskTemplateException}) before anything is written.
 *
 * Which templates a board offers: its own and those of its tenant (the same `tenant_key`, byte for byte; null = the
 * boards without a tenant), each allowed by {@see TaskTemplatePermissionInterface::canUse()}. A template of another board
 * or tenant is never returned, whatever its id (REQ-SEC-006: every row read back is compared with `===`, before any cap).
 *
 * Stateless (worker mode): nothing is kept between calls; rows are read when asked.
 */
final readonly class TaskTemplateManager
{
    /** The most templates one board, or one tenant, holds (the picker and the API list them all). */
    public const MAX_PER_SCOPE = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskTemplatePermissionInterface $permission,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @throws AccessDeniedException when the actor may not manage the board's templates
     * @throws InvalidTaskTemplateException
     */
    public function createForBoard(object $actor, TaskBoard $board, TaskTemplateData $data): TaskTemplate
    {
        $this->requireManage($actor, TaskTemplateScope::board(Snapshots::board($board)));
        [$name, $body, $tags, $column] = $this->checked($data, $board);
        if (count($this->boardTemplates($board)) >= self::MAX_PER_SCOPE) {
            throw new InvalidTaskTemplateException('name', 'A board holds at most ' . self::MAX_PER_SCOPE . ' templates.');
        }

        $template = new TaskTemplate($board, null, $name, $body, $tags, $column, $this->clock->now());
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    /**
     * A template of a tenant (null: of the boards without a tenant). It has no default column: the boards of a tenant
     * do not share columns.
     *
     * @throws AccessDeniedException when the actor may not manage the tenant's templates
     * @throws InvalidTaskTemplateException
     */
    public function createForTenant(object $actor, ?string $tenantKey, TaskTemplateData $data): TaskTemplate
    {
        if ($tenantKey !== null && !$this->isExactKey($tenantKey)) {
            throw new InvalidTaskTemplateException('tenant_key', 'The tenant key must be valid UTF-8 of 1 to 191 characters, without whitespace at its edges and without control or format characters.');
        }
        $this->requireManage($actor, TaskTemplateScope::tenant($tenantKey));
        [$name, $body, $tags, $column] = $this->checked($data, null);
        if (count($this->tenantTemplates($tenantKey)) >= self::MAX_PER_SCOPE) {
            throw new InvalidTaskTemplateException('name', 'A tenant holds at most ' . self::MAX_PER_SCOPE . ' templates.');
        }

        $template = new TaskTemplate(null, $tenantKey, $name, $body, $tags, $column, $this->clock->now());
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    /**
     * @throws AccessDeniedException
     * @throws InvalidTaskTemplateException
     */
    public function update(object $actor, TaskTemplate $template, TaskTemplateData $data): void
    {
        $this->requireManage($actor, $this->scopeOf($template));
        [$name, $body, $tags, $column] = $this->checked($data, $template->getBoard());

        $template->change($name, $body, $tags, $column, $this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * @throws AccessDeniedException
     */
    public function delete(object $actor, TaskTemplate $template): void
    {
        $this->requireManage($actor, $this->scopeOf($template));

        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }

    /**
     * Every template of the board (null: of the tenant `$tenantKey`) with every field, as plain data — what a host's
     * configuration exporter (Beacon block W presets; TaskBoard 2.17 BoardConfiguration) reads. No permission is asked:
     * the caller decides who may export. {@see TaskTemplateSnapshot::toArray()} gives an array; the default column also
     * comes by name, so {@see self::replaceAll()} can apply the data to another board.
     *
     * @return list<TaskTemplateSnapshot>
     */
    public function export(?TaskBoard $board, ?string $tenantKey = null): array
    {
        $templates = $board instanceof TaskBoard ? $this->boardTemplates($board) : $this->tenantTemplates($tenantKey);

        return array_map(TaskTemplateSnapshot::of(...), $templates);
    }

    /**
     * Makes the templates of the board (null: of the tenant `$tenantKey`) exactly `$templates`, matched by name: a
     * template with a listed name is changed in place (its id stays), a new name is created, and a template whose name is
     * not listed is deleted. Asks {@see TaskTemplatePermissionInterface::canManage()} once and checks every entry before
     * the first change; then one flush. Names must be unique in the list (compared exactly).
     *
     * @param list<TaskTemplateData> $templates e.g. `array_map(TaskTemplateData::fromArray(...), $exported)`
     *
     * @throws AccessDeniedException
     * @throws InvalidTaskTemplateException nothing was written
     *
     * @return list<TaskTemplate> the templates of the scope, by name
     */
    public function replaceAll(object $actor, ?TaskBoard $board, ?string $tenantKey, array $templates): array
    {
        if (!$board instanceof TaskBoard && $tenantKey !== null && !$this->isExactKey($tenantKey)) {
            throw new InvalidTaskTemplateException('tenant_key', 'The tenant key must be valid UTF-8 of 1 to 191 characters, without whitespace at its edges and without control or format characters.');
        }
        $this->requireManage($actor, $board instanceof TaskBoard ? TaskTemplateScope::board(Snapshots::board($board)) : TaskTemplateScope::tenant($tenantKey));
        if (count($templates) > self::MAX_PER_SCOPE) {
            throw new InvalidTaskTemplateException('name', 'A board or a tenant holds at most ' . self::MAX_PER_SCOPE . ' templates.');
        }
        $checked = [];
        foreach ($templates as $data) {
            $values = $this->checked($data, $board);
            if (isset($checked[$values[0]])) {
                throw new InvalidTaskTemplateException('name', 'Two templates are named "' . $values[0] . '".');
            }
            $checked[$values[0]] = $values;
        }

        $now      = $this->clock->now();
        $existing = $board instanceof TaskBoard ? $this->boardTemplates($board) : $this->tenantTemplates($tenantKey);
        foreach ($existing as $template) {
            $values = $checked[$template->getName()] ?? null;
            if ($values === null) {
                $this->entityManager->remove($template);

                continue;
            }
            $template->change($values[0], $values[1], $values[2], $values[3], $now);
            unset($checked[$template->getName()]);
        }
        foreach ($checked as [$name, $body, $tags, $column]) {
            $this->entityManager->persist(new TaskTemplate($board, $board instanceof TaskBoard ? null : $tenantKey, $name, $body, $tags, $column, $now));
        }
        $this->entityManager->flush();

        return $board instanceof TaskBoard ? $this->boardTemplates($board) : $this->tenantTemplates($tenantKey);
    }

    /** Whether the actor may manage the templates of the board (null: of the tenant `$tenantKey`). */
    public function canManage(object $actor, ?TaskBoard $board, ?string $tenantKey = null): bool
    {
        return $this->permission->canManage($actor, $board instanceof TaskBoard ? TaskTemplateScope::board(Snapshots::board($board)) : TaskTemplateScope::tenant($tenantKey));
    }

    /**
     * The template with exactly this id, whatever its scope (for a host's settings screen, which checks the scope
     * itself); null when there is none.
     */
    public function find(string $id): ?TaskTemplate
    {
        if ($id === '') {
            return null;
        }
        $template = $this->entityManager->find(TaskTemplate::class, $id);

        // REQ-SEC-006: a folding collation could hand back a look-alike id.
        return $template instanceof TaskTemplate && $template->getId() === $id ? $template : null;
    }

    /**
     * The board's own templates, by name.
     *
     * @return list<TaskTemplate>
     */
    public function boardTemplates(TaskBoard $board): array
    {
        /** @var list<TaskTemplate> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(TaskTemplate::class, 't')
            ->where('t.board = :board')
            ->setParameter('board', $board->getId())
            ->getQuery()
            ->getResult();

        return $this->sorted(array_filter($rows, static fn (TaskTemplate $template): bool => $template->getBoard()?->getId() === $board->getId()));
    }

    /**
     * The templates of a tenant (null: of the boards without a tenant), by name. The key is compared byte for byte.
     *
     * @return list<TaskTemplate>
     */
    public function tenantTemplates(?string $tenantKey): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(TaskTemplate::class, 't')
            ->where('t.board IS NULL');
        if ($tenantKey === null) {
            $query->andWhere('t.tenantKey IS NULL');
        } else {
            $query->andWhere('t.tenantKey = :tenantKey')->setParameter('tenantKey', $tenantKey);
        }
        /** @var list<TaskTemplate> $rows */
        $rows = $query->getQuery()->getResult();

        // REQ-SEC-006: `Acme`, `acme` and `acme ` are three tenants, whatever the column's collation.
        return $this->sorted(array_filter($rows, static fn (TaskTemplate $template): bool => !$template->getBoard() instanceof TaskBoard && $template->getTenantKey() === $tenantKey));
    }

    /**
     * What the board's picker offers `$user`: the board's templates, then its tenant's, each by name, each allowed by
     * {@see TaskTemplatePermissionInterface::canUse()}.
     *
     * @return list<TaskTemplate>
     */
    public function usableOn(object $user, TaskBoard $board): array
    {
        $snapshot  = Snapshots::board($board);
        $templates = array_merge($this->boardTemplates($board), $this->tenantTemplates($board->getTenantKey()));

        return array_values(array_filter(
            $templates,
            fn (TaskTemplate $template): bool => $this->permission->canUse($user, $snapshot, TaskTemplateSnapshot::of($template)),
        ));
    }

    /**
     * The template `$id` when the board offers it to `$user` ({@see self::usableOn()}); null when it does not exist,
     * belongs to another board or tenant, or may not be used — the same answer for each, so nothing leaks.
     */
    public function usable(object $user, TaskBoard $board, string $id): ?TaskTemplate
    {
        $template = $this->find($id);
        if (!$template instanceof TaskTemplate || !self::belongsTo($template, $board)) {
            return null;
        }

        return $this->permission->canUse($user, Snapshots::board($board), TaskTemplateSnapshot::of($template)) ? $template : null;
    }

    /**
     * The template's default column on the board: a column of that board with exactly that id, else null (the board's
     * first column, as for a task without one). Always null for a tenant template.
     */
    public function defaultColumn(TaskTemplate $template, TaskBoard $board): ?BoardColumn
    {
        $id = $template->getDefaultColumnId();
        if ($id === null || $template->getBoard()?->getId() !== $board->getId()) {
            return null;
        }
        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $id) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Fills a new task's data with the template's defaults: the description body, the default tags and the default
     * column. Each `false` keeps what `$data` already says (a field the caller set explicitly wins over the template).
     *
     * Seam (Beacon spec 234): the column lands in `$data->columnId`, so it goes through `TaskManager::create()` like a
     * column the caller chose; a column transition policy applied there applies to it too.
     */
    public function prefill(TaskTemplate $template, TaskBoard $board, TaskFormData $data, bool $description = true, bool $tags = true, bool $column = true): void
    {
        if ($description) {
            $data->description = $template->getBody();
        }
        if ($tags) {
            $data->tags = $template->getDefaultTags();
        }
        if ($column) {
            $default = $this->defaultColumn($template, $board);
            if ($default instanceof BoardColumn) {
                $data->columnId = $default->getId();
            }
        }
    }

    /** Whether the template is the board's own or its tenant's (`tenant_key` compared byte for byte). */
    public static function belongsTo(TaskTemplate $template, TaskBoard $board): bool
    {
        $owner = $template->getBoard();
        if ($owner instanceof TaskBoard) {
            return $owner->getId() === $board->getId();
        }

        return $template->getTenantKey() === $board->getTenantKey();
    }

    /**
     * @throws AccessDeniedException
     */
    private function requireManage(object $actor, TaskTemplateScope $scope): void
    {
        if (!$this->permission->canManage($actor, $scope)) {
            throw new AccessDeniedException('The task templates may not be managed.');
        }
    }

    private function scopeOf(TaskTemplate $template): TaskTemplateScope
    {
        $board = $template->getBoard();

        return $board instanceof TaskBoard ? TaskTemplateScope::board(Snapshots::board($board)) : TaskTemplateScope::tenant($template->getTenantKey());
    }

    /**
     * The input, trimmed and checked: name, body (null when blank), tags (trimmed, once each) and the default column (a
     * column of `$board` with exactly that id; none for a tenant template).
     *
     * @throws InvalidTaskTemplateException
     *
     * @return array{0: string, 1: ?string, 2: list<string>, 3: ?string}
     */
    private function checked(TaskTemplateData $data, ?TaskBoard $board): array
    {
        $name = trim($data->name);
        if ($name === '' || mb_strlen($name) > TaskTemplate::NAME_MAX || preg_match('//u', $name) !== 1) {
            throw new InvalidTaskTemplateException('name', 'A template name has 1 to ' . TaskTemplate::NAME_MAX . ' characters.');
        }
        $body = is_string($data->body) && trim($data->body) !== '' ? $data->body : null;
        if ($body !== null && (preg_match('//u', $body) !== 1 || mb_strlen($body) > TaskTemplate::BODY_MAX)) {
            throw new InvalidTaskTemplateException('body', 'A template body is valid UTF-8 of at most ' . TaskTemplate::BODY_MAX . ' characters.');
        }
        $tags = array_values(array_unique(array_filter(array_map(trim(...), $data->defaultTags), static fn (string $tag): bool => $tag !== '')));
        foreach ($tags as $tag) {
            if (mb_strlen($tag) > TaskConstraints::TAG_MAX || preg_match('//u', $tag) !== 1) {
                throw new InvalidTaskTemplateException('default_tags', 'A tag has at most ' . TaskConstraints::TAG_MAX . ' characters.');
            }
        }
        if (count($tags) > TaskConstraints::TAGS_MAX) {
            throw new InvalidTaskTemplateException('default_tags', 'A template has at most ' . TaskConstraints::TAGS_MAX . ' tags.');
        }

        return [$name, $body, $tags, $this->checkedColumn($data->defaultColumnId, $data->defaultColumnName, $board)];
    }

    /**
     * @throws InvalidTaskTemplateException
     */
    private function checkedColumn(?string $columnId, ?string $columnName, ?TaskBoard $board): ?string
    {
        $byId   = $columnId !== null && $columnId !== '';
        $byName = $columnName !== null && $columnName !== '';
        if (!$byId && !$byName) {
            return null;
        }
        // The id first; data carried from another board finds its column by name.
        foreach ($board instanceof TaskBoard ? $board->getColumns() : [] as $column) {
            if ($byId && $column->getId() === $columnId) {
                return $column->getId();
            }
        }
        foreach ($board instanceof TaskBoard ? $board->getColumns() : [] as $column) {
            if ($byName && $column->getName() === $columnName) {
                return $column->getId();
            }
        }

        throw new InvalidTaskTemplateException('default_column_id', $board instanceof TaskBoard ? 'The default column must be a column of the template\'s board.' : 'A tenant template has no default column.');
    }

    /**
     * REQ-SEC-006 entry refusal, as the bundle's other string keys: no edge whitespace, control or format characters.
     */
    private function isExactKey(string $key): bool
    {
        if ($key === '' || preg_match('//u', $key) !== 1 || mb_strlen($key) > 191) {
            return false;
        }

        return preg_match('/^[\s\p{Z}]|[\s\p{Z}]$|\p{C}/u', $key) !== 1;
    }

    /**
     * @param array<array-key, TaskTemplate> $templates
     *
     * @return list<TaskTemplate>
     */
    private function sorted(array $templates): array
    {
        $templates = array_values($templates);
        usort($templates, static fn (TaskTemplate $a, TaskTemplate $b): int => [$a->getName(), $a->getId()] <=> [$b->getName(), $b->getId()]);

        return $templates;
    }
}
