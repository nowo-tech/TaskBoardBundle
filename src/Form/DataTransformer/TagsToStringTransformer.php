<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

use function in_array;
use function is_array;
use function is_scalar;

/**
 * Maps the task tags list to a comma-separated string for the plain-text fallback (no TagInputBundle).
 *
 * @implements DataTransformerInterface<list<string>, string>
 */
final class TagsToStringTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        return implode(', ', array_filter(array_map(static fn (mixed $tag): string => is_scalar($tag) ? trim((string) $tag) : '', $value), static fn (string $tag): bool => $tag !== ''));
    }

    /** @return list<string> */
    public function reverseTransform(mixed $value): array
    {
        if (!is_scalar($value)) {
            return [];
        }

        $tags = [];
        foreach (explode(',', (string) $value) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
