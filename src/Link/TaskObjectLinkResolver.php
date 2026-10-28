<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Link;

use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;

use function is_string;

/**
 * Label, URL and badge of any task link: a host object link (`TaskLinkType::HostObject`) asks the host's
 * {@see TaskObjectLinkResolverInterface}; other links use their stored label and URL.
 */
final readonly class TaskObjectLinkResolver
{
    /**
     * @param iterable<TaskObjectLinkResolverInterface> $resolvers Services tagged `nowo_task_board.object_link_resolver`
     */
    public function __construct(
        private iterable $resolvers = [],
    ) {
    }

    /**
     * Text shown for the link: the resolver's label, else the stored label, else "type #id" / the URL.
     */
    public function label(TaskLink $link): string
    {
        $resolver = $this->resolverFor($link);
        $label    = $resolver?->label((string) $link->getObjectType(), (string) $link->getObjectId());
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $stored = $link->getLabel();
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return $link->isHostObject()
            ? $link->getObjectType() . ' #' . $link->getObjectId()
            : $link->getUrl();
    }

    /**
     * URL of the link: the resolver's URL for a host object (null when none), else the stored URL.
     */
    public function url(TaskLink $link): ?string
    {
        if (!$link->isHostObject()) {
            return $link->getUrl();
        }

        $url = $this->resolverFor($link)?->url((string) $link->getObjectType(), (string) $link->getObjectId());

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Status badge of a host object link; null for other links or without one.
     */
    public function badge(TaskLink $link): ?TaskObjectBadge
    {
        return $this->resolverFor($link)?->badge((string) $link->getObjectType(), (string) $link->getObjectId());
    }

    /**
     * The first resolver supporting the link's object type (null: not a host object link or none supports it).
     */
    private function resolverFor(TaskLink $link): ?TaskObjectLinkResolverInterface
    {
        $objectType = $link->getObjectType();
        if ($link->getLinkType() !== TaskLinkType::HostObject || $objectType === null || $link->getObjectId() === null) {
            return null;
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($objectType)) {
                return $resolver;
            }
        }

        return null;
    }
}
