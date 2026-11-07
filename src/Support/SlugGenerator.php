<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Symfony\Component\String\Slugger\AsciiSlugger;

use function preg_replace;
use function strtolower;
use function substr;
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

    /** Since 1.30.0 (spec 211): "Año Nuevo" gives `ano-nuevo`, not `a-o-nuevo`. */
    public static function transliterated(string $name): string
    {
        $slug = (new AsciiSlugger('en'))->slug(trim($name))->lower()->toString();

        return $slug !== '' ? substr($slug, 0, 255) : 'board';
    }
}
