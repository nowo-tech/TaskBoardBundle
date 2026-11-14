<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Nowo\TaskBoardBundle\Member\MemberBadge;
use Nowo\TaskBoardBundle\Member\MemberBadgeProviderInterface;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

use function str_starts_with;

/**
 * Since 2.19.0 (Beacon spec 234): the DDD host's member badges and write provenance — plain PHP, the bundle's values
 * only: the ids starting with "bot-" are agents; the provenance is what the test's application layer set.
 */
final class DddProvenance implements MemberBadgeProviderInterface, WriteProvenanceProviderInterface
{
    public static ?WriteProvenance $current = null;

    public function badgesFor(TaskBoardUserRef $member): array
    {
        return str_starts_with($member->id, 'bot-') ? [new MemberBadge('Agent', 'agent')] : [];
    }

    public function badgesForMany(array $members): array
    {
        $answer = [];
        foreach ($members as $member) {
            $answer[$member->id] = $this->badgesFor($member);
        }

        return $answer;
    }

    public function current(): ?WriteProvenance
    {
        return self::$current;
    }
}
