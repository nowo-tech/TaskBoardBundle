<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\IgnoredDecoratorOfAssignee;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 3.0 W1-1 fixture (autodiscovered, loadable): a host restriction of who may be assigned, decorating the removed
 * TaskAssigneeProviderInterface id with onInvalid: IGNORE (Symfony would drop it without a word).
 */
#[AsDecorator('Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class FewerAssignees implements TaskAssigneePolicyInterface
{
    public function assignableUsers(TaskBoardSnapshot $board): array
    {
        return [];
    }

    public function canBeAssigned(TaskBoardSnapshot $board, object $user): bool
    {
        return false;
    }
}
