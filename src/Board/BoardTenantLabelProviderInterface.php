<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Board;

use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

/**
 * Since 1.30.0 (spec 211, `board.tenant_label_provider`): the tenant label and icon of boards in a list. Called once per
 * list, with every key, so a host spends one query. A key it leaves out shows no label.
 */
interface BoardTenantLabelProviderInterface
{
    /**
     * @param list<string> $tenantKeys
     *
     * @return array<string, TaskBoardTenant> by tenant key
     */
    public function labelsFor(object $user, array $tenantKeys): array;
}
