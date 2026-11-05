<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use function preg_match;

/**
 * Since 1.25: a sync source (a list, a folder or a space) by kind and plain id. Container ids of the browser
 * ("s:<id>", "f:<id>", a plain list id) are parsed by {@see self::fromContainerId()}.
 */
final readonly class RemoteLocation
{
    public function __construct(
        public RemoteLocationKind $kind,
        public string $id,
    ) {
    }

    public static function list(string $id): self
    {
        return new self(RemoteLocationKind::List, $id);
    }

    /** "s:<id>" = space, "f:<id>" = folder, a plain id = list; null for anything else (workspaces included). */
    public static function fromContainerId(string $containerId): ?self
    {
        if (preg_match('/^([sf]):([A-Za-z0-9_-]{1,64})$/D', $containerId, $match) === 1) {
            return new self($match[1] === 's' ? RemoteLocationKind::Space : RemoteLocationKind::Folder, $match[2]);
        }

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $containerId) === 1 ? self::list($containerId) : null;
    }

    /** The browser's container id of this location. */
    public function containerId(): string
    {
        return match ($this->kind) {
            RemoteLocationKind::Space  => 's:' . $this->id,
            RemoteLocationKind::Folder => 'f:' . $this->id,
            RemoteLocationKind::List   => $this->id,
        };
    }
}
