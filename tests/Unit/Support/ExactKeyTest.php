<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\Tenancy\SlugTaskBoardTenantResolver;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/**
 * Since 2.15.1 (REQ-SEC-006 item 1, audit F5): a tenant key, scope or slug the bundle could not match exactly is refused
 * where it enters, never trimmed.
 */
final class ExactKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing space' => ['bob '];
        yield 'leading space' => [' bob'];
        yield 'trailing newline' => ["bob\n"];
        yield 'NBSP' => ["bob\u{00A0}"];
        yield 'ZWSP' => ["bo\u{200B}b"];
        yield 'BOM' => ["\u{FEFF}bob"];
        yield 'bidi mark' => ["bob\u{202E}"];
        yield 'control character' => ["bo\x07b"];
        yield 'invalid UTF-8' => ["bob\xC3"];
        yield 'too long' => [str_repeat('b', 192)];
    }

    #[DataProvider('unusableKeys')]
    public function testUnusableKeysAreRefusedEverywhereTheyEnter(string $key): void
    {
        self::assertFalse(ExactKey::isUsable($key));
        self::assertNull((new SlugTaskBoardTenantResolver())->resolve($key, new stdClass()), 'the default resolver names no tenant (404)');
        foreach ([
            static fn (): mixed => new TaskBoardTenant($key, 's', 'Label'),
            static fn (): mixed => (new TaskBoard('B', 'b', null))->setTenantKey($key),
            static fn (): mixed => new ColumnSet('Set', null, $key),
            static fn (): mixed => ExactKey::assertUsable($key, 'tenant key'),
        ] as $enter) {
            try {
                $enter();
                self::fail('refused');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('without whitespace at its edges', $exception->getMessage());
            }
        }
    }

    public function testLookAlikesAreUsableButNeverTheSame(): void
    {
        foreach (['bob', 'Bob', 'bób', "bo\u{0301}b", 'tenant:42', str_repeat('b', 191)] as $key) {
            self::assertTrue(ExactKey::isUsable($key), $key);
        }
        self::assertNull(ExactKey::assertUsable(null, 'tenant key'));
        self::assertSame('bob', (new TaskBoard('B', 'b', null))->setTenantKey('bob')->getTenantKey());
        self::assertNull((new TaskBoard('B', 'b', null))->setTenantKey(null)->getTenantKey());

        self::assertTrue(ExactKey::same('bob', 'bob'));
        self::assertTrue(ExactKey::same(null, null));
        foreach (['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}", null] as $lookAlike) {
            self::assertFalse(ExactKey::same($lookAlike, 'bob'));
            self::assertFalse(ExactKey::oneOf($lookAlike, ['bob', 'alice']));
        }
        self::assertFalse(ExactKey::same('bob', null));
        self::assertFalse(ExactKey::oneOf(1, ['1']));
        self::assertTrue(ExactKey::oneOf('alice', ['bob', 'alice']));
        self::assertTrue(ExactKey::isUsable(str_repeat('b', 255), 255));
    }
}
