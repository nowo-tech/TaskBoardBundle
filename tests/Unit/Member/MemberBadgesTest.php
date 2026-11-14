<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Member;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Member\MemberBadge;
use Nowo\TaskBoardBundle\Member\MemberBadgeProviderInterface;
use Nowo\TaskBoardBundle\Member\MemberBadges;
use Nowo\TaskBoardBundle\Member\MemberBadgeVariant;
use Nowo\TaskBoardBundle\Member\NoMemberBadges;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;

use function array_map;
use function str_repeat;

/**
 * Since 2.18.0 (Beacon spec 234): the badge value (plain text, allow-listed variant) and the request memo (one batch,
 * misses one by one, nothing without a provider, a failing provider gives nothing, reset between requests).
 */
#[CoversClass(MemberBadge::class)]
#[CoversClass(MemberBadges::class)]
#[CoversClass(NoMemberBadges::class)]
final class MemberBadgesTest extends TestCase
{
    public function testABadgeKeepsPlainTextAndAnAllowListedVariant(): void
    {
        $badge = new MemberBadge(' Agent ', 'agent', ' Service account ');
        self::assertSame('Agent', $badge->label);
        self::assertSame(MemberBadgeVariant::Agent, $badge->variant);
        self::assertSame('Service account', $badge->title);
        self::assertSame(MemberBadgeVariant::Neutral, (new MemberBadge('Guest'))->variant);
        self::assertNull((new MemberBadge('Guest', MemberBadgeVariant::Info, '  '))->title);
        // Markup is text: the templates escape it.
        self::assertSame('<script>alert(1)</script>', (new MemberBadge('<script>alert(1)</script>'))->label);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function refused(): iterable
    {
        yield 'empty label' => ['  ', 'neutral', null];
        yield 'long label' => [str_repeat('a', 41), 'neutral', null];
        yield 'control character' => ["Agent\u{0007}", 'neutral', null];
        yield 'format character' => ["Ag\u{200B}ent", 'neutral', null];
        yield 'invalid UTF-8' => ["\xC3\x28", 'neutral', null];
        yield 'unknown variant' => ['Agent', 'btn-danger', null];
        yield 'a CSS class is no variant' => ['Agent', 'badge bg-danger', null];
        yield 'long title' => ['Agent', 'agent', str_repeat('t', 121)];
        yield 'title with a line break' => ['Agent', 'agent', "a\nb"];
    }

    #[DataProvider('refused')]
    public function testABadgeRefusesAnythingButPlainText(string $label, string $variant, ?string $title): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MemberBadge($label, $variant, $title);
    }

    public function testTheDefaultProviderHasNoBadge(): void
    {
        $none = new NoMemberBadges();
        self::assertSame([], $none->badgesFor(new TaskBoardUserRef('7')));
        self::assertSame([], $none->badgesForMany([new TaskBoardUserRef('7')]));
    }

    public function testWithoutAProviderNothingIsAsked(): void
    {
        $badges = new MemberBadges();
        $badges->prime([$this->user('7')]);
        self::assertSame([], $badges->of($this->user('7')));
        self::assertSame([], $badges->of(null));
    }

    public function testOnePageIsOneCallAndAMissIsAskedOnce(): void
    {
        $provider = $this->provider(['7' => [new MemberBadge('Agent', 'agent')], 'u-8' => []]);
        $badges   = new MemberBadges($provider);

        $badges->prime([$this->user('7'), $this->user('7'), null, $this->user('u-8'), new TaskBoardUserRef('9'), new stdClass(), $this->user(' bad ')]);
        self::assertSame([['7', 'u-8', '9']], $provider->batches, 'distinct ids, the unusable ones skipped');
        self::assertSame('Agent', $badges->of($this->user('7'))[0]->label);
        self::assertSame([], $badges->of(new TaskBoardUserRef('u-8')));
        self::assertSame([], $badges->of(new stdClass()), 'no id: no badge');
        self::assertCount(1, $provider->batches, 'answered from the memo');

        self::assertSame([], $badges->of($this->user('10')));
        $badges->prime([$this->user('7'), $this->user('10')]);
        self::assertSame([['7', 'u-8', '9'], ['10']], $provider->batches, 'a miss primes that one member, once');

        $badges->reset();
        $badges->of($this->user('7'));
        self::assertSame(['7'], $provider->batches[2], 'nothing survives the request');
    }

    public function testAnythingButABadgeIsDroppedAndAFailureGivesNothing(): void
    {
        $provider = $this->provider(['7' => ['<b>raw</b>', new MemberBadge('Agent', 'agent'), null], '8' => 'Agent']);
        $badges   = new MemberBadges($provider);
        self::assertSame(['Agent'], array_map(static fn (MemberBadge $badge): string => $badge->label, $badges->of($this->user('7'))));
        self::assertSame([], $badges->of($this->user('8')));

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = $level . ': ' . $message;
            }
        };
        $failing = new class implements MemberBadgeProviderInterface {
            public function badgesFor(TaskBoardUserRef $member): array
            {
                throw new RuntimeException('down');
            }

            public function badgesForMany(array $members): array
            {
                throw new RuntimeException('down');
            }
        };
        $badges = new MemberBadges($failing, $logger);
        self::assertSame([], $badges->of($this->user('7')));
        self::assertSame([], $badges->of($this->user('7')), 'not asked again in the same request');
        self::assertSame(['warning: TaskBoard member badge provider failed'], $logger->messages);
    }

    private function user(string $id): object
    {
        return new class($id) {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function provider(array $answers): object
    {
        return new class($answers) implements MemberBadgeProviderInterface {
            /** @var list<list<string>> */
            public array $batches = [];

            /** @param array<string, mixed> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function badgesFor(TaskBoardUserRef $member): array
            {
                return [];
            }

            public function badgesForMany(array $members): array
            {
                $this->batches[] = array_map(static fn (TaskBoardUserRef $member): string => $member->id, $members);

                /* @phpstan-ignore return.type (a host answering garbage, on purpose) */
                return $this->answers;
            }
        };
    }
}
