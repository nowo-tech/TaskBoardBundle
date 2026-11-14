<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Folder\ManageRightRestructurePolicy;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ScriptedTenantResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.18.0: with tenancy, the default restructure policy also needs a tenant the person opens, as the UI does.
 */
final class ApiFoldersTenancyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        self::$hostConfig = ['tenancy' => [
            'enabled'      => true,
            'resolver'     => 'app.tenant_resolver',
            'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']],
        ]];
        ScriptedTenantResolver::$open = ['mine'];
        parent::setUp();
        ApiTestHost::$tenants = ['mine', 'theirs', null];
        $tokens               = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    protected function tearDown(): void
    {
        ScriptedTenantResolver::$open = [];
        parent::tearDown();
    }

    public function testFoldersOnlyInATenantThePersonOpens(): void
    {
        $policy = self::getContainer()->get(ManageRightRestructurePolicy::class);
        self::assertInstanceOf(TaskBoardRestructurePolicyInterface::class, $policy);

        self::assertTrue($policy->mayManageFolders($this->owner, 'mine'));
        self::assertFalse($policy->mayManageFolders($this->owner, 'theirs'));
        self::assertFalse($policy->mayManageFolders($this->owner, null), 'no tenant-less folders under tenancy');
    }
}
