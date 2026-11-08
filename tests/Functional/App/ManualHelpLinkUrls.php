<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Import\ImportHelpLinkUrlResolverInterface;

/**
 * Since 2.5.0 (TB-17): a host whose manual lives either in the app or on a public site (`import.help_link.url_resolver`).
 */
final class ManualHelpLinkUrls implements ImportHelpLinkUrlResolverInterface
{
    public static bool $inApp = true;

    public function resolveHelpLinkUrl(?string $configuredUrl): ?string
    {
        return self::$inApp ? $configuredUrl : 'https://manual.example.test/import';
    }
}
