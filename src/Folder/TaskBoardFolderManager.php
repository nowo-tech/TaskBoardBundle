<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function max;
use function mb_strlen;
use function strcmp;
use function trim;
use function usort;

/**
 * Since 2.19.0 (Beacon spec 234, FR-031): the folders of a tenant and the boards in them, on entities — what the Task API
 * and {@see TaskBoardRestructurer} call. Every write asks {@see TaskBoardRestructurePolicyInterface} first, then runs in a
 * transaction it opens and closes; a failed flush leaves the entity manager open ({@see RecoveringFlusher}).
 *
 * Tenant keys are compared byte for byte (`===`) after every lookup (REQ-SEC-006): a folder of another tenant is
 * `not_found`, as an unknown id. Stateless (REQ-RUNTIME-002).
 */
final readonly class TaskBoardFolderManager
{
    /** The longest Markdown description kept (64 KiB of text, as a board's). */
    public const DESCRIPTION_MAX = 65535;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardRestructurePolicyInterface $policy,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * The folders of the tenant, by position then name.
     *
     * @return list<TaskBoardFolder>
     */
    public function folders(?string $tenantKey): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('f')->from(TaskBoardFolder::class, 'f');
        $tenantKey === null ? $query->where('f.tenantKey IS NULL') : $query->where('f.tenantKey = :tenant')->setParameter('tenant', $tenantKey);
        /** @var list<TaskBoardFolder> $folders */
        $folders = $query->getQuery()->getResult();
        $folders = array_values(array_filter($folders, static fn (TaskBoardFolder $folder): bool => $folder->getTenantKey() === $tenantKey));
        usort($folders, static fn (TaskBoardFolder $a, TaskBoardFolder $b): int => [$a->getPosition(), $a->getName(), $a->getId()] <=> [$b->getPosition(), $b->getName(), $b->getId()]);

        return $folders;
    }

    /** A folder by id; null when unknown, or not exactly that id (REQ-SEC-006). */
    public function find(string $folderId): ?TaskBoardFolder
    {
        if ($folderId === '' || mb_strlen($folderId) > 36) {
            return null;
        }
        $folder = $this->entityManager->find(TaskBoardFolder::class, $folderId);

        return $folder instanceof TaskBoardFolder && $folder->getId() === $folderId ? $folder : null;
    }

    /**
     * The boards in the folder, archived ones included with `$archived`, by name.
     *
     * @return list<TaskBoard>
     */
    public function boardsIn(TaskBoardFolder $folder, bool $archived = false): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('b')->from(TaskBoard::class, 'b')
            ->where('IDENTITY(b.folder) = :folder')->setParameter('folder', $folder->getId());
        if (!$archived) {
            $query->andWhere('b.archivedAt IS NULL');
        }
        /** @var list<TaskBoard> $boards */
        $boards = $query->getQuery()->getResult();
        $boards = array_values(array_filter($boards, static fn (TaskBoard $board): bool => $board->getFolderId() === $folder->getId() && $board->getTenantKey() === $folder->getTenantKey()));
        usort($boards, static fn (TaskBoard $a, TaskBoard $b): int => strcmp($a->getName(), $b->getName()) ?: strcmp($a->getId(), $b->getId()));

        return $boards;
    }

    /**
     * @throws TaskBoardRestructureRefusedException `not_allowed`, `invalid`
     */
    public function create(object $actor, ?string $tenantKey, string $name, ?string $description = null, ?int $position = null): TaskBoardFolder
    {
        if (!$this->policy->mayManageFolders($actor, $tenantKey)) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
        }
        $name = $this->name($name);
        $this->description($description);
        $position ??= $this->nextPosition($tenantKey);
        $folder = new TaskBoardFolder($name, $tenantKey, $description, $position);
        $this->write(function () use ($folder): void {
            $this->entityManager->persist($folder);
        });

        return $folder;
    }

    /**
     * @throws TaskBoardRestructureRefusedException `not_allowed`, `invalid`
     */
    public function update(object $actor, TaskBoardFolder $folder, string $name, ?string $description, int $position): TaskBoardFolder
    {
        if (!$this->policy->mayManageFolders($actor, $folder->getTenantKey())) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
        }
        $name = $this->name($name);
        $this->description($description);
        $this->write(static function () use ($folder, $name, $description, $position): void {
            $folder->setName($name)->setDescription($description)->setPosition($position);
        });

        return $folder;
    }

    /**
     * @throws TaskBoardRestructureRefusedException `not_allowed`, `not_empty`
     */
    public function delete(object $actor, TaskBoardFolder $folder): void
    {
        if (!$this->policy->mayManageFolders($actor, $folder->getTenantKey())) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
        }
        $this->write(function () use ($folder): void {
            // Counted inside the transaction, so a board moved in meanwhile is seen (any tenant: SET NULL is never relied on).
            $count = (int) $this->entityManager->createQueryBuilder()->select('COUNT(b.id)')->from(TaskBoard::class, 'b')
                ->where('IDENTITY(b.folder) = :folder')->setParameter('folder', $folder->getId())->getQuery()->getSingleScalarResult();
            if ($count > 0) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_EMPTY, ['%count%' => $count]);
            }
            $this->entityManager->remove($folder);
        });
    }

    /**
     * Puts the board in the folder, or out of any (null). Needs the policy on the board, and on the folders' tenant when a
     * folder is named.
     *
     * @throws TaskBoardRestructureRefusedException `not_found` (another tenant), `not_allowed`
     */
    public function moveBoard(object $actor, TaskBoard $board, ?TaskBoardFolder $folder): void
    {
        if ($folder instanceof TaskBoardFolder && $folder->getTenantKey() !== $board->getTenantKey()) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
        }
        if (!$this->policy->mayRestructureBoard($actor, Snapshots::board($board))) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
        }
        if ($board->getFolderId() === $folder?->getId()) {
            return;
        }
        $this->write(static function () use ($board, $folder): void {
            $board->changeFolder($folder);
        });
    }

    /**
     * @return list<string>
     */
    public function boardIds(TaskBoardFolder $folder): array
    {
        return array_map(static fn (TaskBoard $board): string => $board->getId(), $this->boardsIn($folder, true));
    }

    /**
     * @throws TaskBoardRestructureRefusedException
     */
    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > TaskBoardFolder::NAME_MAX) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::INVALID, ['field' => 'name']);
        }

        return $name;
    }

    /**
     * @throws TaskBoardRestructureRefusedException
     */
    private function description(?string $description): void
    {
        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::INVALID, ['field' => 'description']);
        }
    }

    private function nextPosition(?string $tenantKey): int
    {
        $last = -1;
        foreach ($this->folders($tenantKey) as $folder) {
            $last = max($last, $folder->getPosition());
        }

        return $last + 1;
    }

    /**
     * Runs `$change` and flushes in a transaction of its own (a savepoint inside the caller's).
     *
     * @param callable(): void $change
     */
    private function write(callable $change): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $change();
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $failure;
        }
    }
}
