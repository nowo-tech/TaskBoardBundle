<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Avatar;

use Nowo\TaskBoardBundle\Avatar\AvatarUrlPolicy;
use Nowo\TaskBoardBundle\Avatar\NullTaskMemberAvatarResolver;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Avatar\TaskMemberKey;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Twig\Markup;

use function bin2hex;
use function file_get_contents;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.23: the avatar URL policy, the user key and the per-request batch.
 */
final class TaskMemberAvatarsTest extends TestCase
{
    /**
     * The table shared with the front end (avatar-fallback.test.ts).
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function urls(): iterable
    {
        /** @var list<array{url: string, allowed: bool}> $table */
        $table = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/avatar-urls.json'), true, 4, JSON_THROW_ON_ERROR);
        foreach ($table as $index => $row) {
            yield $index . ': ' . json_encode($row['url']) => [$row['url'], $row['allowed']];
        }
    }

    #[DataProvider('urls')]
    public function testTheUrlPolicy(string $url, bool $allowed): void
    {
        self::assertSame($allowed ? $url : null, AvatarUrlPolicy::sanitize($url));
        self::assertNull(AvatarUrlPolicy::sanitize(null));
    }

    /** Audit EG-I1: invalid UTF-8 (JSON cannot carry it, so not in the shared table) is refused. */
    public function testInvalidUtf8IsRefused(): void
    {
        foreach (["/files/a\xff/s64", "https://h\xc3/x", "/files/\xed\xa0\x80", "/files/\xc0\xaf"] as $url) {
            self::assertNull(AvatarUrlPolicy::sanitize($url), bin2hex($url));
        }
    }

    public function testTheUserKey(): void
    {
        self::assertSame('7', TaskMemberKey::of(new TestUser('7', 'seven')));
        $object     = new stdClass();
        $object->id = 9;
        self::assertSame('9', TaskMemberKey::of($object));
        self::assertNull(TaskMemberKey::of(new stdClass()));
        $empty     = new stdClass();
        $empty->id = '';
        self::assertNull(TaskMemberKey::of($empty));
        $array     = new stdClass();
        $array->id = ['x'];
        self::assertNull(TaskMemberKey::of($array));
        $stringable     = new stdClass();
        $stringable->id = new class implements Stringable {
            public function __toString(): string
            {
                return 'uuid';
            }
        };
        self::assertSame('uuid', TaskMemberKey::of($stringable));
    }

    public function testOneBatchPerRequestWithAMemoAndAReset(): void
    {
        $resolver = new class implements TaskMemberAvatarResolverInterface {
            /** @var list<list<string>> */
            public array $calls = [];

            public function urlFor(object $user, int $size): ?string
            {
                throw new RuntimeException('never called');
            }

            public function urlsFor(array $users, int $size): array
            {
                $this->calls[] = array_map(static fn (object $u): string => (string) TaskMemberKey::of($u), $users);

                return ['1' => '/files/1', '2' => 'javascript:alert(1)', '3' => 42];
            }
        };
        $avatars = new TaskMemberAvatars($resolver);
        $one     = new TestUser('1', 'one');
        $avatars->prime([$one, null, new stdClass(), new TestUser('2', 'two'), $one, new TestUser('3', 'three')]);
        self::assertSame([['1', '2', '3']], $resolver->calls);
        self::assertSame('/files/1', $avatars->urlOf($one));
        self::assertNull($avatars->urlOf(new TestUser('2', 'two')), 'rejected');
        self::assertNull($avatars->urlOf(new TestUser('3', 'three')), 'not a string');
        self::assertNull($avatars->urlOf(null));
        self::assertNull($avatars->urlOf(new stdClass()));
        $avatars->prime([$one]);
        self::assertCount(1, $resolver->calls, 'memoised');

        self::assertNull($avatars->urlOf(new TestUser('4', 'four')));
        self::assertSame(['4'], $resolver->calls[1], 'a miss primes that one user');

        $avatars->reset();
        $avatars->prime([$one]);
        self::assertCount(3, $resolver->calls);
        self::assertSame(32, TaskMemberAvatars::SIZE);
    }

    public function testAMarkupUrlIsNeverTrusted(): void
    {
        $resolver = new class implements TaskMemberAvatarResolverInterface {
            public function urlFor(object $user, int $size): ?string
            {
                return null;
            }

            public function urlsFor(array $users, int $size): array
            {
                return ['1' => new Markup('/a"onerror="alert(1)', 'UTF-8'), '2' => new Markup('/files/2', 'UTF-8')];
            }
        };
        $avatars = new TaskMemberAvatars($resolver);
        self::assertNull($avatars->urlOf(new TestUser('1', 'one')), 'a Markup is not a string: dropped');
        self::assertNull($avatars->urlOf(new TestUser('2', 'two')));
    }

    public function testAFailingResolverGivesNullAndAWarning(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . ($context['exception'] ?? '');
            }
        };
        $resolver = new class implements TaskMemberAvatarResolverInterface {
            public function urlFor(object $user, int $size): ?string
            {
                return null;
            }

            public function urlsFor(array $users, int $size): array
            {
                throw new RuntimeException('storage down with secret');
            }
        };
        $avatars = new TaskMemberAvatars($resolver, $logger);
        self::assertNull($avatars->urlOf(new TestUser('1', 'one')));
        self::assertSame(['warning TaskBoard avatar resolver failed RuntimeException'], $logger->lines);

        $null = new NullTaskMemberAvatarResolver();
        self::assertNull($null->urlFor(new stdClass(), 32));
        self::assertSame([], $null->urlsFor([new stdClass()], 32));
        self::assertNull((new TaskMemberAvatars())->urlOf(new TestUser('1', 'one')));
    }
}
