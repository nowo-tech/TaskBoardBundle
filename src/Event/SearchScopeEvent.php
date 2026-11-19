<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_values;

/**
 * Since 2.25.0 (header search): dispatched as {@see TaskBoardEvents::SEARCH_SCOPE} before the header search reads the
 * boards or tasks of a viewer, so a host can leave scopes out — for instance the projects where its task feature is off.
 *
 * A listener can only narrow: {@see self::onlyTenants()} and {@see self::onlyBoards()} keep the given ones (a second call
 * keeps what both lists name), {@see self::excludeTenants()} and {@see self::excludeBoards()} leave some out, and
 * {@see self::deny()} leaves everything out. Nothing here grants access: every board found is still checked with
 * BOARD_ACCESS_CHECK and every task with TASK_ACCESS_CHECK. The bundle applies the lists in SQL, before the rows are cut
 * to the limit, so a scope left out never takes the place of a visible row and never shows in a count.
 *
 * With `tenancy.enabled` and no onlyTenants() answer, only the tenant of the current request (a `{tenantSlug}` page)
 * is searched, and nothing outside one: a host whose search box is on every page names the viewer's tenants here.
 *
 * The event lives for one search; nothing is kept between requests (FrankenPHP worker mode).
 */
final class SearchScopeEvent extends Event
{
    /** The task group of the header search (titles and descriptions). */
    public const GROUP_TASKS = 'tasks';

    /** The board group of the header search (board names). */
    public const GROUP_BOARDS = 'boards';

    /** @var array<string, true>|null */
    private ?array $onlyTenants = null;

    /** @var array<string, true> */
    private array $excludedTenants = [];

    /** @var array<string, true>|null */
    private ?array $onlyBoards = null;

    /** @var array<string, true> */
    private array $excludedBoards = [];

    private bool $denied = false;

    /**
     * @param object $subject The viewer (the host's user object)
     * @param string $group {@see self::GROUP_TASKS} or {@see self::GROUP_BOARDS}
     * @param string|null $scope The scope id the search box sent (client input: never trust it as a permission), or null
     * @param string|null $requestTenantKey With tenancy, the tenant key of the current request; null outside a tenant page
     */
    public function __construct(
        private readonly object $subject,
        private readonly string $group,
        private readonly ?string $scope = null,
        private readonly ?string $requestTenantKey = null,
    ) {
    }

    /** The viewer. */
    public function getSubject(): object
    {
        return $this->subject;
    }

    /** The viewer's `getId()` as a string ({@see ActorId}); null without one. */
    public function getSubjectId(): ?string
    {
        return ActorId::of($this->subject);
    }

    /** {@see self::GROUP_TASKS} or {@see self::GROUP_BOARDS}. */
    public function getGroup(): string
    {
        return $this->group;
    }

    /** The scope id of the search box, or null: client input, as typed. */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /** With tenancy, the tenant key of the current request; null outside a tenant page or without tenancy. */
    public function getRequestTenantKey(): ?string
    {
        return $this->requestTenantKey;
    }

    /**
     * Searches only these tenants (by key). A second call keeps the tenants both calls name.
     *
     * @param list<string> $tenantKeys
     */
    public function onlyTenants(array $tenantKeys): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->onlyTenants = self::narrow($this->onlyTenants, $tenantKeys);
    }

    /**
     * Leaves these tenants (by key) out.
     *
     * @param list<string> $tenantKeys
     */
    public function excludeTenants(array $tenantKeys): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->excludedTenants += self::set($tenantKeys);
    }

    /**
     * Searches only these boards (by id). A second call keeps the boards both calls name.
     *
     * @param list<string> $boardIds
     */
    public function onlyBoards(array $boardIds): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->onlyBoards = self::narrow($this->onlyBoards, $boardIds);
    }

    /**
     * Leaves these boards (by id) out.
     *
     * @param list<string> $boardIds
     */
    public function excludeBoards(array $boardIds): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->excludedBoards += self::set($boardIds);
    }

    /** Searches nothing for this viewer: the group is empty, as when nothing matches. */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->denied = true;
    }

    /** Whether a listener called {@see self::deny()}. */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * The tenants to search, or null when no listener restricted them.
     *
     * @return list<string>|null
     */
    public function getOnlyTenants(): ?array
    {
        return $this->onlyTenants === null ? null : array_map('strval', array_keys($this->onlyTenants));
    }

    /**
     * The tenants left out.
     *
     * @return list<string>
     */
    public function getExcludedTenants(): array
    {
        return array_map('strval', array_keys($this->excludedTenants));
    }

    /**
     * The boards to search, or null when no listener restricted them.
     *
     * @return list<string>|null
     */
    public function getOnlyBoards(): ?array
    {
        return $this->onlyBoards === null ? null : array_map('strval', array_keys($this->onlyBoards));
    }

    /**
     * The boards left out.
     *
     * @return list<string>
     */
    public function getExcludedBoards(): array
    {
        return array_map('strval', array_keys($this->excludedBoards));
    }

    /**
     * @param array<string, true>|null $current
     * @param list<string> $values
     *
     * @return array<string, true>
     */
    private static function narrow(?array $current, array $values): array
    {
        $given = self::set($values);

        return $current === null ? $given : array_intersect_key($current, $given);
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, true>
     */
    private static function set(array $values): array
    {
        $values = array_map('strval', $values);

        return array_fill_keys(array_values(array_filter($values, static fn (string $value): bool => $value !== '')), true);
    }
}
