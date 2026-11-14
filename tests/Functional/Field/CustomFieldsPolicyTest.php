<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.18.0 (FR-028): field definitions follow the restructure policy (the manage right by default).
 */
final class CustomFieldsPolicyTest extends FunctionalTestCase
{
    public function testDefinitionsNeedTheRight(): void
    {
        $board    = $this->board('Board', tenantKey: 'acme');
        $stranger = $this->user('stranger@example.com', ['ROLE_GUEST']);
        $tokens   = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($stranger, 'main', $stranger->getRoles()));
        $fields = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $fields);

        foreach ([null, $board] as $scope) {
            try {
                $fields->define($stranger, $scope, 'acme', 'Nope', CustomFieldType::Text);
                self::fail('no right');
            } catch (TaskCustomFieldRefusedException $refused) {
                self::assertSame(TaskCustomFieldRefusedException::NOT_ALLOWED, $refused->reason);
            }
        }
    }
}
