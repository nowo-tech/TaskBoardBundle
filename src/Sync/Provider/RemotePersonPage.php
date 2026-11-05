<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: one page of {@see PeopleProviderInterface::fetchPeople()}.
 */
final readonly class RemotePersonPage
{
    /**
     * @param list<RemotePerson> $people
     */
    public function __construct(
        public array $people,
        public ?string $nextCursor = null,
    ) {
    }
}
