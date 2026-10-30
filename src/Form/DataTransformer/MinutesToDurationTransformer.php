<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form\DataTransformer;

use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

use function is_int;
use function is_scalar;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function round;
use function str_replace;
use function strtolower;
use function trim;

use const PREG_SET_ORDER;

/**
 * Since 1.20: the task estimate as a duration ("2 h 30 min") instead of a bare number of minutes ("150").
 *
 * The field shows {@see TaskDeviation::formatMinutes()} ("45 min", "24 h", "1 h 30 min") and reads it back, plus the
 * usual shorthands: "90" (a bare number is minutes, as before 1.20), "90m", "2h", "1.5 h", "1,5h", "1h30", "1 h 30 min".
 * Anything else is a form error (`invalid_message`).
 *
 * @implements DataTransformerInterface<int, string>
 */
final class MinutesToDurationTransformer implements DataTransformerInterface
{
    private const UNIT_PATTERN = '/(\d+(?:[.,]\d+)?)\s*(hours?|hrs|hr|h|minutes?|mins|min|m)?/';

    public function transform(mixed $value): string
    {
        return is_int($value) && $value > 0 ? TaskDeviation::formatMinutes($value) : '';
    }

    public function reverseTransform(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }

        // A bare number is minutes (the field held minutes up to 1.19).
        if (preg_match('/^\d+$/', $text) === 1) {
            return (int) $text;
        }

        // "1h30" — minutes after hours without a unit.
        $text = (string) preg_replace('/^(\d+)\s*h\s*(\d+)$/', '$1 h $2 min', $text);

        // Every part must be "<number> <unit>": strip the parts and nothing but spaces may remain.
        if (preg_match_all(self::UNIT_PATTERN, $text, $parts, PREG_SET_ORDER) === 0 || trim((string) preg_replace(self::UNIT_PATTERN, '', $text)) !== '') {
            throw new TransformationFailedException('Invalid duration.');
        }

        $minutes = 0.0;
        foreach ($parts as $part) {
            $amount = (float) str_replace(',', '.', $part[1]);
            $unit   = $part[2] ?? '';
            if ($unit === '') {
                throw new TransformationFailedException('A duration part needs a unit.');
            }

            $minutes += $unit[0] === 'h' ? $amount * 60 : $amount;
        }

        return (int) round($minutes);
    }
}
