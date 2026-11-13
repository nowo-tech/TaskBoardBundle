<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.17.0: folder writes need the restructure policy's folder right (by default the create-board roles,
 * `security.create_roles`), else 403 `ACCESS_002`.
 */
final class ApiFoldersPolicyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$hostConfig = ['security' => ['create_roles' => ['ROLE_ADMIN']]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->issueToken();
    }

    public function testWithoutTheFolderRightWritesAreRefused(): void
    {
        $admin  = $this->user('admin@example.com', ['ROLE_ADMIN', 'ROLE_USER']);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $folder = self::getContainer()->get(TaskBoardRestructureInterface::class)->createFolder($admin, 'tenant-a', 'Epic');

        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/folder', ['name' => 'New']);
        $this->assertRefused(403, 'ACCESS_002');
        $this->send('PUT', '/api/tasks/v2/folder/' . $folder->id, ['name' => 'Renamed']);
        $this->assertRefused(403, 'ACCESS_002');
    }
}
