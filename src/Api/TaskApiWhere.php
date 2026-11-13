<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

use function in_array;

/**
 * Since 2.2.0 (spec 213): where a token reaches, as plain data the host builds on each request. Everything the person
 * can reach, minus some tenants (an organization that forbids tokens), or a list of tenants and boards. The bundle does not
 * know projects or organizations: the host turns them into tenant keys.
 */
final readonly class TaskApiWhere
{
    /**
     * @param list<string> $exceptTenantKeys
     * @param list<string> $tenantKeys
     * @param list<string> $boardIds
     */
    private function __construct(
        private bool $everything,
        private array $exceptTenantKeys,
        private array $tenantKeys,
        private array $boardIds,
    ) {
    }

    /**
     * Everything the person can reach, minus the tenants the host removes. It grants the person's whole reach: a host maps a
     * token's explicit "everything I can reach" to it, and never an empty, missing or unreadable scope, which must be
     * {@see self::limitedTo()}`([], [])`. Where only narrows; access still comes from the checkers.
     *
     * @param list<string> $exceptTenantKeys tenants the host removes
     */
    public static function everything(array $exceptTenantKeys = []): self
    {
        return new self(true, $exceptTenantKeys, [], []);
    }

    /**
     * Each entry grants on its own: a named tenant reaches all its boards, and a named board is reached even when its tenant
     * is not listed (audit IA-I4, as intended: a token for one board of a project names the board, not the project). The
     * person's access, the host's workspace and the tenant check still apply to it.
     *
     * @param list<string> $tenantKeys
     * @param list<string> $boardIds
     */
    public static function limitedTo(array $tenantKeys, array $boardIds): self
    {
        return new self(false, [], $tenantKeys, $boardIds);
    }

    /**
     * @deprecated since 2.15.0, use {@see self::reachesBoardOf()} (ids, REQ-ARCH-001 rule 1); removed in 3.0
     */
    public function reachesBoard(TaskBoard $board): bool
    {
        return $this->reachesBoardOf($board->getId(), $board->getTenantKey());
    }

    /** Since 2.15.0: whether the board of this id and tenant is reached (its whole tenant, or the board by id). */
    public function reachesBoardOf(string $boardId, ?string $tenantKey): bool
    {
        return $this->reachesTenant($tenantKey) || (!$this->everything && in_array($boardId, $this->boardIds, true));
    }

    /** Whether the board's id is named, whatever its tenant (a board that no longer exists, for a replay). */
    public function namesBoard(string $boardId): bool
    {
        return !$this->everything && in_array($boardId, $this->boardIds, true);
    }

    /**
     * The whole tenant: creating a board there needs this. A board without a tenant is reached only by everything.
     */
    public function reachesTenant(?string $tenantKey): bool
    {
        if ($this->everything) {
            return $tenantKey === null || !in_array($tenantKey, $this->exceptTenantKeys, true);
        }

        return $tenantKey !== null && in_array($tenantKey, $this->tenantKeys, true);
    }

    /**
     * Whether a space may be listed: the whole tenant, or one of the given boards of it.
     *
     * @param list<string> $boardIdsOfTenant
     */
    public function touchesTenant(?string $tenantKey, array $boardIdsOfTenant): bool
    {
        if ($this->reachesTenant($tenantKey)) {
            return true;
        }
        if ($this->everything) {
            return false;
        }
        foreach ($boardIdsOfTenant as $boardId) {
            if (in_array($boardId, $this->boardIds, true)) {
                return true;
            }
        }

        return false;
    }
}
