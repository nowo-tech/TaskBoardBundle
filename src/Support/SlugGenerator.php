<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function preg_replace;
use function strtolower;
use function trim;

/**
 * Generates URL-safe slugs from board names.
 */
final class SlugGenerator
{
    public static function fromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'board';
    }
}
