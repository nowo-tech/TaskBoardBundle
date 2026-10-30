<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Form;

use Nowo\TaskBoardBundle\Form\DataTransformer\MinutesToDurationTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class MinutesToDurationTransformerTest extends TestCase
{
    public function testShowsMinutesAsADuration(): void
    {
        $transformer = new MinutesToDurationTransformer();

        self::assertSame('24 h', $transformer->transform(1440));
        self::assertSame('1 h 30 min', $transformer->transform(90));
        self::assertSame('45 min', $transformer->transform(45));
        self::assertSame('', $transformer->transform(0));
        self::assertSame('', $transformer->transform(null));
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function durations(): iterable
    {
        yield 'bare minutes' => ['90', 90];
        yield 'empty' => ['  ', null];
        yield 'null' => [null, null];
        yield 'not scalar' => [['1'], null];
        yield 'hours' => ['24 h', 1440];
        yield 'hours word' => ['2 hours', 120];
        yield 'decimal hours' => ['1.5h', 90];
        yield 'comma hours' => ['1,5 h', 90];
        yield 'shown format' => ['1 h 30 min', 90];
        yield 'compact' => ['1h30m', 90];
        yield 'hours then bare minutes' => ['1h30', 90];
        yield 'minutes' => ['45 min', 45];
        yield 'minutes word' => ['45 Minutes', 45];
    }

    #[DataProvider('durations')]
    public function testReadsDurations(mixed $input, ?int $minutes): void
    {
        self::assertSame($minutes, (new MinutesToDurationTransformer())->reverseTransform($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'words' => ['soon'];
        yield 'trailing text' => ['2 h later'];
        yield 'number without unit after a part' => ['2 h 30 15'];
        yield 'negative' => ['-2 h'];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingElse(string $input): void
    {
        $this->expectException(TransformationFailedException::class);
        (new MinutesToDurationTransformer())->reverseTransform($input);
    }
}
