<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

/**
 * Since 3.2.0 (CU-2, REQ-ARCH-001 rule 3): a host tenant as the framework-free ports see it — its key (null without
 * tenancy: the boards outside any tenant) and its slug when the request named one.
 */
final readonly class TenantRef
{
    public function __construct(
        public ?string $key,
        public ?string $slug = null,
    ) {
    }
}
