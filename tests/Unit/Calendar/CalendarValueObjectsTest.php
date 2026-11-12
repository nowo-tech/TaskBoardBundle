<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Calendar\CalendarAnnotations;
use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotation;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotations;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotator;
use Nowo\TaskBoardBundle\Calendar\CalendarDayKind;
use Nowo\TaskBoardBundle\Calendar\CalendarTaskWarning;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/**
 * Since 2.14.0: the value objects of the calendar day annotations (REQ-ARCH-001 rule 3): validated plain values only.
 */
#[CoversClass(CalendarDayAnnotation::class)]
#[CoversClass(CalendarDayAnnotations::class)]
#[CoversClass(CalendarDateRange::class)]
#[CoversClass(CalendarAnnotations::class)]
#[CoversClass(CalendarTaskWarning::class)]
#[CoversClass(CalendarViewer::class)]
#[CoversClass(CalendarDayAnnotator::class)]
final class CalendarValueObjectsTest extends TestCase
{
    public function testAnnotationsKeepOnePerDayAndPreferTheNonWorkingOne(): void
    {
        $days = new CalendarDayAnnotations([
            new CalendarDayAnnotation('2030-03-04', false, 'Half day'),
            new CalendarDayAnnotation('2030-03-04', true, '  Holiday  ', CalendarDayKind::Holiday),
            new CalendarDayAnnotation('2030-03-04', true, 'Second'),
            new CalendarDayAnnotation('2030-03-05', false, 'Training'),
        ]);

        self::assertSame('Holiday', $days->for('2030-03-04')?->label);
        self::assertSame(CalendarDayKind::Holiday, $days->for('2030-03-04')?->kind);
        self::assertTrue($days->isNonWorking('2030-03-04'));
        self::assertFalse($days->isNonWorking('2030-03-05'));
        self::assertFalse($days->isNonWorking('2030-03-06'));
        self::assertNull($days->for('2030-03-06'));
        self::assertSame(['2030-03-04', '2030-03-05'], $days->dates());
        self::assertFalse($days->isEmpty());
        self::assertTrue(CalendarDayAnnotations::none()->isEmpty());
        self::assertSame(CalendarDayKind::Other, (new CalendarDayAnnotation('2030-03-04', true, 'x'))->kind);
    }

    public function testSomethingElseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CalendarDayAnnotations([new stdClass()]); // @phpstan-ignore argument.type
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAnnotations(): iterable
    {
        yield 'not a date' => ['04/03/2030', 'Holiday'];
        yield 'impossible date' => ['2030-02-30', 'Holiday'];
        yield 'empty label' => ['2030-03-04', '   '];
        yield 'long label' => ['2030-03-04', str_repeat('a', 81)];
        yield 'control character' => ['2030-03-04', "Holi\nday"];
    }

    #[DataProvider('invalidAnnotations')]
    public function testAnInvalidAnnotationIsRefused(string $date, string $label): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CalendarDayAnnotation($date, true, $label);
    }

    public function testTheRange(): void
    {
        $range = new CalendarDateRange('2030-03-01', '2030-03-31');

        self::assertTrue($range->contains('2030-03-01'));
        self::assertTrue($range->contains('2030-03-31'));
        self::assertFalse($range->contains('2030-04-01'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'backwards' => ['2030-03-02', '2030-03-01'];
        yield 'not a date' => ['2030-3-1', '2030-03-02'];
        yield 'impossible end' => ['2030-03-01', '2030-02-31'];
    }

    #[DataProvider('invalidRanges')]
    public function testAnInvalidRangeIsRefused(string $from, string $to): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CalendarDateRange($from, $to);
    }

    public function testTheResultAndTheViewer(): void
    {
        $warning     = new CalendarTaskWarning('7', new CalendarDayAnnotation('2030-03-04', true, 'Absent', CalendarDayKind::Absent));
        $annotations = new CalendarAnnotations(CalendarDayAnnotations::none(), ['t1' => [$warning]]);

        self::assertSame([$warning], $annotations->warningsFor('t1'));
        self::assertSame([], $annotations->warningsFor('t2'));
        $viewer = new CalendarViewer('7', 'b1', 'acme');
        self::assertSame(['7', 'b1', 'acme'], [$viewer->userId, $viewer->boardId, $viewer->tenantKey]);
    }

    public function testWithoutAProviderTheAnnotatorDoesNothing(): void
    {
        $annotator = new CalendarDayAnnotator($this->createStub(EntityManagerInterface::class));

        self::assertFalse($annotator->isEnabled());
        self::assertNull($annotator->annotate(new CalendarViewer('7', 'b1'), new CalendarDateRange('2030-03-01', '2030-03-31'), []));
    }
}
