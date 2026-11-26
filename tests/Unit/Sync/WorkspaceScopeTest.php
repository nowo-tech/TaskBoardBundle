<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\WorkspaceScope;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\People\PeopleOnlyProvider;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\People\PlainTaskProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 3.2.0 (audit ZBYv-L1): the workspace check asks the provider's batch method, else `workspaceOf()` per
 * container (stopping at the first foreign one), else nothing (a provider without workspaces).
 */
final class WorkspaceScopeTest extends TestCase
{
    public function testTheBatchMethodThenWorkspaceOfThenNothing(): void
    {
        $credential = new ProviderCredential('pk_test_token', 'c');
        $memory     = (new InMemoryTaskProvider())->withWorkspace('1');

        self::assertSame([], WorkspaceScope::foreign($memory, $credential, '1', ['L1', 'L2']));
        self::assertSame(['L1', 'L2'], WorkspaceScope::foreign($memory, $credential, '2', ['L1', 'L2']));
        self::assertSame(['foreignContainers:1', 'foreignContainers:2'], $memory->calls, 'one call per check');

        $memory->calls = [];
        $people        = new PeopleOnlyProvider($memory);
        self::assertSame([], WorkspaceScope::foreign($people, $credential, '1', ['L1', 'L2']));
        self::assertSame(['L1'], WorkspaceScope::foreign($people, $credential, '2', ['L1', 'L2']));
        self::assertSame(['workspaceOf:L1', 'workspaceOf:L2', 'workspaceOf:L1'], $memory->calls, 'stops at the first foreign one');

        self::assertSame([], WorkspaceScope::foreign(new PlainTaskProvider($memory), $credential, '2', ['L1']));
    }
}
