<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

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
}
