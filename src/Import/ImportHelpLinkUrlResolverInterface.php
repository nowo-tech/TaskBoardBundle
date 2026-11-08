<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

/**
 * Since 2.5.0 (TB-17): the host's last word on the URL of the import forms' help link (`import.help_link.url_resolver`),
 * e.g. to follow where its manual lives (in the app or on a public site). Asked per render, after the access check.
 */
interface ImportHelpLinkUrlResolverInterface
{
    /**
     * @param string|null $configuredUrl the URL from `import.help_link.url` or `.route` (null when neither is set)
     *
     * @return string|null the URL to link (an http(s) URL or a path on this site), or null for no link
     */
    public function resolveHelpLinkUrl(?string $configuredUrl): ?string;
}
