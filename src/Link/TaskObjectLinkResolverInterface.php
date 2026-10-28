<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Link;

/**
 * Describes a host object a task links to (`TaskLinkType::HostObject`): an issue, an order, a ticket… Services
 * implementing it are tagged `nowo_task_board.object_link_resolver` automatically (autoconfigure); the first one
 * that supports an object type answers for it.
 */
interface TaskObjectLinkResolverInterface
{
    /**
     * Whether this resolver describes objects of `$objectType` (e.g. "issue").
     */
    public function supports(string $objectType): bool;

    /**
     * Text shown for the object (e.g. "BEACON-12 Undefined index"); null: the object no longer exists or the user
     * may not see it (the stored label is shown instead).
     */
    public function label(string $objectType, string $objectId): ?string;

    /**
     * URL of the object page; null: no page (or not visible to the user).
     */
    public function url(string $objectType, string $objectId): ?string;

    /**
     * Status badge of the object (e.g. "Resolved", success); null: none.
     */
    public function badge(string $objectType, string $objectId): ?TaskObjectBadge;
}
