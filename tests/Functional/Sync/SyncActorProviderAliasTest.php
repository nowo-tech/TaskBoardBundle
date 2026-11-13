<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;

/**
 * Since 2.15.1 (Beacon request): the default actor resolver is also the framework-free {@see SyncActorProviderInterface},
 * autowirable under that interface, and answers the same through the twin as through the deprecated interface.
 */
final class SyncActorProviderAliasTest extends SyncFunctionalTestCase
{
    public function testTheTwinIsAutowirableAndAnswersAsTheDeprecatedPath(): void
    {
        $provider = self::getContainer()->get(SyncActorProviderInterface::class);
        self::assertInstanceOf(ConnectionCreatorActorResolver::class, $provider);
        self::assertInstanceOf(SyncActorResolverInterface::class, $provider, 'the deprecated path keeps working');

        $linked   = $this->board('Linked');
        $unlinked = $this->board('Unlinked');
        $this->em->persist(new TaskBoardConnection($linked, 'clickup', 'clickup', 'ref', 'list-1', 'List', $this->owner));
        $this->em->flush();
        $other = $this->user('other@example.com');

        foreach ([$linked, $unlinked] as $board) {
            $snapshot = Snapshots::board($board);
            foreach ([null, '', (string) $other->getId(), 'missing'] as $actorId) {
                self::assertSame($provider->actorFor($board, $actorId), $provider->actorFor($snapshot, $actorId), $board->getName() . ' / ' . ($actorId ?? 'null'));
            }
        }
        self::assertSame($this->owner, $provider->actorFor(Snapshots::board($linked)), 'no named actor: the user who linked the board');
        self::assertNull($provider->actorFor(Snapshots::board($unlinked)));
        self::assertSame($other, $provider->actorFor(Snapshots::board($unlinked), (string) $other->getId()));
        self::assertNull($provider->actorFor(Snapshots::board($linked), 'missing'), 'deny, do not substitute');
    }
}
