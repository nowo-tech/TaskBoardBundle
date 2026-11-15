<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use const PHP_INT_MAX;

/**
 * Since 2.19.0, audit PW (proofs of concept turned into regressions): a number the Task API cannot answer exactly is
 * refused when it is written (PW-L1).
 */
final class CustomFieldsAuditPwTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testANumberAbovePhpIntMaxIsRefusedAndTheLargestOneIsAnsweredExactly(): void
    {
        $board  = $this->board('Board', tenantKey: 'acme');
        $task   = $this->task($board, 'T');
        $fields = $this->fields();
        $field  = $fields->define($this->owner, $board, null, 'Amount', CustomFieldType::Number);

        foreach (['9300000000000000000', '9223372036854775808', '-9223372036854775808.5', '10000000000000000000000'] as $tooLarge) {
            try {
                $fields->setField($task, $field->getId(), $tooLarge, $this->owner);
                self::fail($tooLarge . ' is refused');
            } catch (TaskCustomFieldRefusedException $refused) {
                self::assertSame(TaskCustomFieldRefusedException::INVALID, $refused->reason);
            }
        }
        $fields->setField($task, $field->getId(), '000123.50', $this->owner);
        $fields->setField($task, $field->getId(), (string) PHP_INT_MAX, $this->owner);
        $this->clear();
        $value = $this->em->getRepository(TaskCustomFieldValue::class)->findOneBy(['field' => $field->getId()]);
        self::assertInstanceOf(TaskCustomFieldValue::class, $value);
        if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            // SQLite keeps DECIMAL as REAL (documented): only an exact database answers the exact integer back.
            self::assertSame(PHP_INT_MAX, TaskApiReadSerializer::nativeValue($value));
        }
    }

    public function testARowAbovePhpIntMaxWrittenBeforeIsAnsweredAsAFloatNotSaturated(): void
    {
        $board  = $this->board('Board', tenantKey: 'acme');
        $task   = $this->task($board, 'T');
        $fields = $this->fields();
        $field  = $fields->define($this->owner, $board, null, 'Amount', CustomFieldType::Number);
        $value  = new TaskCustomFieldValue($task, $field);
        $value->change(null, '9300000000000000000');

        self::assertSame(9.3E18, TaskApiReadSerializer::nativeValue($value));
    }

    private function fields(): TaskCustomFieldManager
    {
        $service = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }
}
