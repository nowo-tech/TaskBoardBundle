<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Provenance;

use Nowo\TaskBoardBundle\Provenance\NoWriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function mb_strlen;
use function str_repeat;

/**
 * Since 2.18.0 (Beacon spec 234): the channel allow-list and the actor label as the bundle stores it — plain text,
 * cut to 120 characters (never refused), empty is null.
 */
#[CoversClass(WriteProvenance::class)]
#[CoversClass(NoWriteProvenance::class)]
final class WriteProvenanceTest extends TestCase
{
    public function testTheChannelsAreAnAllowList(): void
    {
        self::assertSame(['web', 'app', 'api', 'mcp'], array_map(static fn (WriteChannel $channel): string => $channel->value, WriteChannel::cases()));
        self::assertNull(WriteChannel::tryFrom('cli'));
    }

    public function testTheLabelIsPlainTextOfAtMost120Characters(): void
    {
        $long = new WriteProvenance(WriteChannel::Api, str_repeat('é', 130));
        self::assertSame(120, mb_strlen((string) $long->actorLabel), 'cut, not refused: a long token name never blocks a write');
        self::assertSame(WriteChannel::Api, $long->channel);

        self::assertSame('<script>alert(1)</script>', (new WriteProvenance(WriteChannel::Mcp, '<script>alert(1)</script>'))->actorLabel, 'kept as text, escaped where shown');
        self::assertSame('CI token', (new WriteProvenance(WriteChannel::Api, "  CI\u{200B} token\u{0000}\n "))->actorLabel);
        self::assertNull((new WriteProvenance(WriteChannel::Web, " \u{200B} "))->actorLabel);
        self::assertNull((new WriteProvenance(WriteChannel::Web))->actorLabel);
        self::assertNull(WriteProvenance::normalizeLabel("\xC3\x28"), 'invalid UTF-8: no label');
        self::assertNull((new NoWriteProvenance())->current());
    }
}
