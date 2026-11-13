<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\PrivateEntityManager;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * A host SQL filter on the boards' tenant, to check that the private manager mirrors the source's filters.
 */
final class BoardTenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== TaskBoard::class || !$this->hasParameter('tenant')) {
            return '';
        }

        return $targetTableAlias . '.tenant_key = ' . $this->getParameter('tenant');
    }
}
