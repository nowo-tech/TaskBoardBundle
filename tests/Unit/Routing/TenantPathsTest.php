<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Routing;

use Nowo\TaskBoardBundle\Routing\TenantPaths;
use PHPUnit\Framework\TestCase;

final class TenantPathsTest extends TestCase
{
    private const ROUTES = [
        'index' => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index'],
        'board' => ['path' => '/tools/task-board/board/{boardId}', 'name' => 'nowo_task_board_board'],
        'list'  => ['path' => '/tools/task-board/board/{boardId}/list', 'name' => ''],
    ];

    public function testDisabledHasNoCopies(): void
    {
        $paths = new TenantPaths([['path' => '/p/{tenantSlug}/tasks', 'name_prefix' => 'app_']], self::ROUTES);

        self::assertFalse($paths->isEnabled());
        self::assertSame([], $paths->all());
        self::assertNull($paths->namePrefixFor('42'));
        self::assertNull($paths->tenantRoute('nowo_task_board_board', '42'));
        self::assertSame('app_nowo_task_board_board', $paths->bundleRoute('app_nowo_task_board_board'));
        self::assertTrue($paths->isBundleRoute('nowo_task_board_board'));
        self::assertFalse($paths->isBundleRoute(''));
    }

    public function testPicksTheFirstPathWhoseSlugPrefixMatches(): void
    {
        $paths = new TenantPaths([
            ['path' => '/me/{tenantSlug}/tasks', 'name_prefix' => 'me_', 'slug_prefix' => 'user-', 'slug_requirement' => null],
            ['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_'],
        ], self::ROUTES, true);

        self::assertTrue($paths->isEnabled());
        self::assertCount(2, $paths->all());
        self::assertSame('me_', $paths->namePrefixFor('user-7'));
        self::assertSame('app_project_', $paths->namePrefixFor('42'));
        self::assertSame('app_project_nowo_task_board_board', $paths->tenantRoute('nowo_task_board_board', '42'));
        self::assertSame('me_nowo_task_board_index', $paths->tenantRoute('nowo_task_board_index', 'user-7'));
        self::assertNull($paths->tenantRoute('app_home', '42'));
    }

    public function testNoPathServesTheSlug(): void
    {
        $paths = new TenantPaths([['path' => '/me/{tenantSlug}/tasks', 'name_prefix' => 'me_', 'slug_prefix' => 'user-']], self::ROUTES, true);

        self::assertNull($paths->namePrefixFor('42'));
        self::assertNull($paths->tenantRoute('nowo_task_board_board', '42'));
    }

    public function testBundleRouteStripsTheNamePrefixOfACopy(): void
    {
        $paths = new TenantPaths([['path' => '/p/{tenantSlug}/tasks', 'name_prefix' => 'app_']], self::ROUTES, true);

        self::assertSame('nowo_task_board_board', $paths->bundleRoute('app_nowo_task_board_board'));
        self::assertTrue($paths->isTenantRoute('app_nowo_task_board_board'));
        self::assertSame('app_home', $paths->bundleRoute('app_home'));
        self::assertFalse($paths->isTenantRoute('app_home'));
        self::assertFalse($paths->isTenantRoute('nowo_task_board_board'));
    }

    public function testSlugRequirement(): void
    {
        self::assertSame('\d+', TenantPaths::slugRequirement(['slug_requirement' => '\d+']));
        self::assertSame('user\-[A-Za-z0-9_-]+', TenantPaths::slugRequirement(['slug_prefix' => 'user-', 'slug_requirement' => '']));
        self::assertSame('[A-Za-z0-9_-]+', TenantPaths::slugRequirement([]));
    }
}
