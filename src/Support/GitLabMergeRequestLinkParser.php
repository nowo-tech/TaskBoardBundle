<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function sprintf;

/**
 * Parses GitLab merge request URLs into external id and display label.
 */
final class GitLabMergeRequestLinkParser
{
    /**
     * @return array{externalId: string, label: string}|null
     */
    public function parse(string $url): ?array
    {
        if (!preg_match('~/-/merge_requests/(\d+)(?:[/?#]|$)~i', $url, $matches)
            && !preg_match('~/merge_requests/(\d+)(?:[/?#]|$)~i', $url, $matches)) {
            return null;
        }

        $iid = $matches[1];

        return [
            'externalId' => $iid,
            'label'      => sprintf('MR !%s', $iid),
        ];
    }
}
