<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use RuntimeException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function htmlspecialchars;
use function sprintf;
use function str_starts_with;

/**
 * Since 2.0.2: `ux_icon()` of a host that imported Tabler only. Any other name fails the way symfony/ux-icons does when
 * an icon is not there, so a page that asks for one answers 500.
 */
final class TablerOnlyUxIcons extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('ux_icon', $this->icon(...), ['is_safe' => ['html']])];
    }

    public function icon(string $name): string
    {
        if (!str_starts_with($name, 'tabler:')) {
            throw new RuntimeException(sprintf('Icon "%s" not found.', $name));
        }

        return '<svg data-icon="' . htmlspecialchars($name) . '"></svg>';
    }
}
