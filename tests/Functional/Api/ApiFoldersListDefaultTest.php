<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_column;
use function sort;

/**
 * Since 2.18.0: `api.lists_include_folders: true` makes `GET /v2/space/{id}/list` list every board by default;
 * `include_folders=false` still asks for the folderless ones.
 */
final class ApiFoldersListDefaultTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$api = ['lists_include_folders' => true];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->issueToken();
    }

    public function testTheOptionSetsTheDefault(): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);
        $this->board('Loose', tenantKey: 'tenant-a');
        $inside = $this->board('Inside', tenantKey: 'tenant-a');
        $service->moveBoardToFolder($this->owner, $inside->getId(), $service->createFolder($this->owner, 'tenant-a', 'Epic')->id);

        $this->get('/api/tasks/v2/space/space-tenant-a/list');
        $names = array_column($this->listOf('lists'), 'name');
        sort($names);
        self::assertSame(['Inside', 'Loose'], $names);
        $this->get('/api/tasks/v2/space/space-tenant-a/list?include_folders=false');
        self::assertSame(['Loose'], array_column($this->listOf('lists'), 'name'));
    }
}
