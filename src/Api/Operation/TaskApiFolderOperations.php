<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiFolderList;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderManager;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;

use function array_map;
use function array_merge;
use function array_values;

/**
 * Since 2.17.0 (Beacon spec 234, FR-031): folders in the ClickUp v2 shape — `GET/POST /v2/space/{id}/folder`,
 * `GET/PUT/DELETE /v2/folder/{id}`, `GET/POST /v2/folder/{id}/list`.
 *
 * Reach, as for a board: a folder is out of reach (404, the same as an unknown id) unless the host answers a space for its
 * tenant, the token's where names that tenant as a whole or one of the folder's boards, and the plan holds the module.
 * Its lists are the boards in it the token may view. Writes need a `Full` token, a where that reaches the tenant as a
 * whole, and {@see TaskBoardRestructurePolicyInterface::mayManageFolders()} (403 `ACCESS_002`); a folder with boards in it
 * is not deleted (409 `FOLDER_001`).
 */
final readonly class TaskApiFolderOperations
{
    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskApiWorkspaceInterface $workspace,
        private TaskApiQueryRepository $queries,
        private TaskBoardFolderManager $folders,
        private TaskBoardRestructurePolicyInterface $policy,
        private TaskApiBoardOperations $boards,
    ) {
    }

    /**
     * `GET /v2/space/{id}/folder`: the tenant's folders the token reaches, by position.
     *
     * @throws TaskApiRefusal
     */
    public function folders(TaskApiPrincipal $principal, string $spaceId): TaskApiFolderList
    {
        $space   = $this->guard->space($principal, $spaceId, TaskApiCapability::ReadOwn);
        $visible = $this->guard->visibleBoards($principal, $space->tenantKey);
        $whole   = $principal->where->reachesTenant($space->tenantKey);
        $folders = [];
        $boards  = [];
        foreach ($this->folders->folders($space->tenantKey) as $folder) {
            $in = $this->inFolder($visible, $folder);
            if ($whole || $in !== []) {
                $folders[]                = $folder;
                $boards[$folder->getId()] = $in;
            }
        }

        return $this->list($principal, $space, $folders, $boards);
    }

    /**
     * `GET /v2/folder/{id}` and `GET /v2/folder/{id}/list`.
     *
     * @throws TaskApiRefusal
     */
    public function folder(TaskApiPrincipal $principal, string $folderId): TaskApiFolderList
    {
        return $this->reach($principal, $folderId, TaskApiCapability::ReadOwn);
    }

    /**
     * `POST /v2/space/{id}/folder`: `name` (required), `content` / `markdown_content` (Markdown), `orderindex`.
     *
     * @throws TaskApiRefusal
     */
    public function createFolder(TaskApiPrincipal $principal, string $spaceId, TaskApiInput $input): TaskBoardFolder
    {
        $this->audit->requireListener();
        $space = $this->guard->space($principal, $spaceId, TaskApiCapability::ManageBoards);
        if (!$principal->where->reachesTenant($space->tenantKey)) {
            throw TaskApiRefusal::notFound();
        }
        $folder = $this->refusals(fn (): TaskBoardFolder => $this->folders->create($principal->user, $space->tenantKey, (string) $input->string('name', true), $this->description($input), $input->int('orderindex')));
        $this->audit->written($principal, 'folder.create', null, null, ['folder' => $folder->getId()], [], $folder->getTenantKey());

        return $folder;
    }

    /**
     * `PUT /v2/folder/{id}`: what the body names changes; the rest stays.
     *
     * @throws TaskApiRefusal
     */
    public function updateFolder(TaskApiPrincipal $principal, string $folderId, TaskApiInput $input): TaskBoardFolder
    {
        $this->audit->requireListener();
        $folder      = $this->writable($principal, $folderId);
        $name        = $input->has('name') ? (string) $input->string('name', true) : $folder->getName();
        $description = $input->hasAny('content', 'markdown_content') ? $this->description($input) : $folder->getDescription();
        $position    = $input->int('orderindex') ?? $folder->getPosition();
        $this->refusals(fn (): TaskBoardFolder => $this->folders->update($principal->user, $folder, $name, $description, $position));
        $this->audit->written($principal, 'folder.update', null, null, ['folder' => $folder->getId()], [], $folder->getTenantKey());

        return $folder;
    }

    /**
     * `DELETE /v2/folder/{id}`: only an empty folder.
     *
     * @throws TaskApiRefusal
     */
    public function deleteFolder(TaskApiPrincipal $principal, string $folderId): ?string
    {
        $this->audit->requireListener();
        $folder = $this->writable($principal, $folderId);
        $id     = $folder->getId();
        $tenant = $folder->getTenantKey();
        $this->refusals(function () use ($principal, $folder): bool {
            $this->folders->delete($principal->user, $folder);

            return true;
        });
        $this->audit->written($principal, 'folder.delete', null, null, ['folder' => $id], [], $tenant);

        return $tenant;
    }

    /**
     * `POST /v2/folder/{id}/list`: a board created in the folder's tenant (as `POST /v2/space/{id}/list`), then put in the
     * folder. The folder right and the board's create right are both asked before anything is written.
     *
     * @throws TaskApiRefusal
     */
    public function createList(TaskApiPrincipal $principal, string $folderId, TaskApiInput $input): TaskBoard
    {
        $this->audit->requireListener();
        $folder = $this->writable($principal, $folderId);
        $space  = $this->workspace->spaceOfTenant($principal, $folder->getTenantKey()) ?? throw TaskApiRefusal::notFound();
        $board  = $this->boards->createBoard($principal, $space->id, $input);
        $this->refusals(function () use ($principal, $board, $folder): bool {
            $this->folders->moveBoard($principal->user, $board, $folder);

            return true;
        });
        $this->audit->written($principal, 'folder.list_create', $board, null, ['folder' => $folder->getId()]);

        return $board;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function writable(TaskApiPrincipal $principal, string $folderId): TaskBoardFolder
    {
        $list   = $this->reach($principal, $folderId, TaskApiCapability::ManageBoards);
        $folder = $list->folders[0];
        if (!$principal->where->reachesTenant($folder->getTenantKey())) {
            throw TaskApiRefusal::notFound();
        }
        if (!$this->policy->mayManageFolders($principal->user, $folder->getTenantKey())) {
            throw TaskApiRefusal::permissionRefused();
        }

        return $folder;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function reach(TaskApiPrincipal $principal, string $folderId, TaskApiCapability $capability): TaskApiFolderList
    {
        $folder = $this->folders->find($folderId);
        $space  = $folder instanceof TaskBoardFolder ? $this->workspace->spaceOfTenant($principal, $folder->getTenantKey()) : null;
        if (!$folder instanceof TaskBoardFolder || $space === null || $space->tenantKey !== $folder->getTenantKey()) {
            throw TaskApiRefusal::notFound();
        }
        $space = $this->guard->space($principal, $space->id, $capability);
        $in    = $this->inFolder($this->guard->visibleBoards($principal, $space->tenantKey), $folder);
        if (!$principal->where->reachesTenant($folder->getTenantKey()) && $in === []) {
            throw TaskApiRefusal::notFound();
        }

        return $this->list($principal, $space, [$folder], [$folder->getId() => $in]);
    }

    /**
     * @param list<TaskBoard> $boards
     *
     * @return list<TaskBoard>
     */
    private function inFolder(array $boards, TaskBoardFolder $folder): array
    {
        $in = [];
        foreach ($boards as $board) {
            if ($board->getFolderId() === $folder->getId()) {
                $in[] = $board;
            }
        }

        return $in;
    }

    /**
     * @param list<TaskBoardFolder> $folders
     * @param array<string, list<TaskBoard>> $boards
     */
    private function list(TaskApiPrincipal $principal, TaskApiSpace $space, array $folders, array $boards): TaskApiFolderList
    {
        $ids = [];
        foreach ($boards as $in) {
            $ids = array_merge($ids, array_map(static fn (TaskBoard $board): string => $board->getId(), $in));
        }
        $owner = $principal->level === TaskApiLevel::MyTasks ? $principal->user : null;

        return new TaskApiFolderList($space, array_values($folders), $boards, $this->queries->countByBoard($ids, $owner));
    }

    private function description(TaskApiInput $input): ?string
    {
        return $input->has('markdown_content') ? $input->string('markdown_content') : $input->string('content');
    }

    /**
     * The folder service's refusals as the API's.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @throws TaskApiRefusal
     *
     * @return T
     */
    private function refusals(callable $write): mixed
    {
        try {
            return $write();
        } catch (TaskBoardRestructureRefusedException $refused) {
            throw match ($refused->reason) {
                TaskBoardRestructureRefusedException::NOT_ALLOWED => TaskApiRefusal::permissionRefused(),
                TaskBoardRestructureRefusedException::NOT_EMPTY   => new TaskApiRefusal(409, 'FOLDER_001', 'The folder is not empty: move its lists out first'),
                TaskBoardRestructureRefusedException::INVALID     => new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . ($refused->params['field'] ?? 'input')),
                default                                           => TaskApiRefusal::notFound(),
            };
        }
    }
}
