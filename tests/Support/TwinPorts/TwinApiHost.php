<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiMembersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUpload;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Twin replay of the API host: the authenticator, time store and attachment store through their framework-free twins
 * (a request rebuilt from the {@see TaskApiRequestContext}, the task found again by id, the upload as an `UploadedFile`);
 * the other hooks (no twin) delegate as they are.
 */
final readonly class TwinApiHost implements TaskApiRequestAuthenticatorInterface, TaskApiTimeStoreInterface, TaskApiAttachmentStoreInterface, TaskApiRateLimiterInterface, TaskApiPlanGateInterface, TaskApiWorkspaceInterface, TaskApiUsersInterface, TaskApiMembersInterface
{
    public function __construct(private ApiTestHost $legacy, private TwinLookup $lookup)
    {
    }

    public function authenticate(TaskApiRequestContext $request): TaskApiPrincipal|TaskApiAuthFailure
    {
        $http = Request::create($request->path, $request->method, server: ['REMOTE_ADDR' => $request->clientIp ?? '127.0.0.1']);
        if ($request->credential !== null) {
            $http->headers->set('Authorization', 'Bearer ' . $request->credential);
        }

        return $this->legacy->authenticate($http);
    }

    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
    {
        return $this->legacy->list($principal, $query);
    }

    public function log(TaskApiPrincipal $principal, ?TaskSnapshot $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
    {
        return $this->legacy->log($principal, $task instanceof TaskSnapshot ? $this->lookup->task($task->id) : null, $entry);
    }

    public function store(TaskApiPrincipal $principal, TaskSnapshot $task, TaskApiUpload $file): TaskApiAttachment
    {
        return $this->legacy->store($principal, $this->lookup->task($task->id), new UploadedFile($file->pathname, $file->clientName, $file->clientMimeType, null, true));
    }

    public function consume(TaskApiPrincipal $principal): TaskApiRateLimitState
    {
        return $this->legacy->consume($principal);
    }

    public function failedTokenState(string $clientIp): TaskApiRateLimitState
    {
        return $this->legacy->failedTokenState($clientIp);
    }

    public function registerFailedToken(string $clientIp): void
    {
        $this->legacy->registerFailedToken($clientIp);
    }

    public function allowsTasks(object $user, ?string $tenantKey): bool
    {
        return $this->legacy->allowsTasks($user, $tenantKey);
    }

    public function teams(TaskApiPrincipal $principal): array
    {
        return $this->legacy->teams($principal);
    }

    public function spaces(TaskApiPrincipal $principal, string $teamId): array
    {
        return $this->legacy->spaces($principal, $teamId);
    }

    public function space(TaskApiPrincipal $principal, string $spaceId): ?TaskApiSpace
    {
        return $this->legacy->space($principal, $spaceId);
    }

    public function spaceOfTenant(TaskApiPrincipal $principal, ?string $tenantKey): ?TaskApiSpace
    {
        return $this->legacy->spaceOfTenant($principal, $tenantKey);
    }

    public function apiId(object $user): string
    {
        return $this->legacy->apiId($user);
    }

    public function find(string $apiId): ?object
    {
        return $this->legacy->find($apiId);
    }

    public function profiles(array $users, TaskApiPrincipal $viewer): array
    {
        return $this->legacy->profiles($users, $viewer);
    }

    /** Since 2.16.0: already framework-free; asked as the host asks it. */
    public function members(TaskApiPrincipal $viewer, string $teamId): array
    {
        return $this->legacy->members($viewer, $teamId);
    }
}
