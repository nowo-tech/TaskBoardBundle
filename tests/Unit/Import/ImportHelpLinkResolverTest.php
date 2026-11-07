<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Nowo\TaskBoardBundle\Import\ImportHelpLinkResolver;
use PHPUnit\Framework\TestCase;

final class ImportHelpLinkResolverTest extends TestCase
{
    private const LINK = ['url' => null, 'route' => null, 'route_params' => [], 'label' => 'Help', 'translation_domain' => 'messages', 'anchor_from' => null, 'access_attribute' => null, 'external' => 'auto'];

    public function testNothingConfiguredGivesNoLink(): void
    {
        self::assertNull((new ImportHelpLinkResolver())->resolve());
        self::assertNull((new ImportHelpLinkResolver(self::LINK))->resolve());
        self::assertNull((new ImportHelpLinkResolver(['url' => '/x', 'label' => null] + self::LINK))->resolve());
    }

    public function testARouteWithoutARouterGivesNoLink(): void
    {
        self::assertNull((new ImportHelpLinkResolver(['route' => 'r'] + self::LINK))->resolve());
    }

    public function testAnAttributeWithoutACheckerHidesTheLink(): void
    {
        self::assertNull((new ImportHelpLinkResolver(['url' => '/x', 'access_attribute' => 'ROLE_X'] + self::LINK))->resolve());
    }

    public function testExternalAuto(): void
    {
        $resolve = static fn (string $url, bool|string $external = 'auto'): ?array => (new ImportHelpLinkResolver(['url' => $url, 'external' => $external] + self::LINK))->resolve();

        self::assertFalse($resolve('/manual')['external'] ?? null);
        self::assertTrue($resolve('https://docs.example.test/x')['external'] ?? null, 'no request: any absolute URL');
        self::assertTrue($resolve('/manual', true)['external'] ?? null);
        self::assertFalse($resolve('https://docs.example.test/x', false)['external'] ?? null);
        self::assertSame('Help', $resolve('/manual')['label'] ?? null);
    }
}
