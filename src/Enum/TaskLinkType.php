<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

use function array_filter;
use function array_values;

/**
 * External link types attached to a task (MR, PR, issue, etc.).
 */
enum TaskLinkType: string
{
    case Url           = 'url';
    case MergeRequest  = 'merge_request';
    case PullRequest   = 'pull_request';
    case Issue         = 'issue';
    case Documentation = 'documentation';
    case Other         = 'other';
    /** A host object (issue, order, ticket…) named by `objectType` / `objectId` (since 1.10). */
    case HostObject = 'host_object';

    /**
     * The types a user picks in the link form (host object links are created by the host).
     *
     * @return list<self>
     */
    public static function userChoices(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type !== self::HostObject));
    }
}
