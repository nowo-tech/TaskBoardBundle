<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search\Header;

use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SearchScopeEventTest extends TestCase
{
    public function testCarriesTheRequestAndOnlyNarrows(): void
    {
        $user  = new TestUser('7', 'u@example.com');
        $event = new SearchScopeEvent($user, SearchScopeEvent::GROUP_TASKS, 'chip', 'acme');

        self::assertSame($user, $event->getSubject());
        self::assertSame('7', $event->getSubjectId());
        self::assertSame('tasks', $event->getGroup());
        self::assertSame('chip', $event->getScope());
        self::assertSame('acme', $event->getRequestTenantKey());
        self::assertNull($event->getOnlyTenants());
        self::assertNull($event->getOnlyBoards());
        self::assertSame([], $event->getExcludedTenants());
        self::assertSame([], $event->getExcludedBoards());
        self::assertFalse($event->isDenied());

        $event->onlyTenants(['acme', 'globex', '', 'acme', '42']);
        $event->onlyTenants(['globex', 'initech', '42']);
        self::assertSame(['globex', '42'], $event->getOnlyTenants());

        $event->onlyBoards(['b1', 'b2']);
        $event->onlyBoards(['b3']);
        self::assertSame([], $event->getOnlyBoards());

        $event->excludeTenants(['x', '']);
        $event->excludeTenants(['y', 'x', '1']);
        self::assertSame(['x', 'y', '1'], $event->getExcludedTenants());
        $event->excludeBoards(['b9']);
        self::assertSame(['b9'], $event->getExcludedBoards());

        $event->deny();
        self::assertTrue($event->isDenied());
    }

    public function testDefaults(): void
    {
        $event = new SearchScopeEvent(new stdClass(), SearchScopeEvent::GROUP_BOARDS);

        self::assertNull($event->getSubjectId());
        self::assertNull($event->getScope());
        self::assertNull($event->getRequestTenantKey());
        self::assertSame('boards', $event->getGroup());
    }
}
