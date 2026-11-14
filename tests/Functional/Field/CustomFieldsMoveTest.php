<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;

/**
 * Since 2.19.0 (audit PW-L6, the proof of concept turned into a regression): a task moved to another board takes the
 * values of its old board's own fields to the new board's field of the same name and type, or loses them with a history
 * row (reported by the move); a value left behind never counts as the old board's any more.
 */
final class CustomFieldsMoveTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testBoardFieldValuesFollowOrAreDroppedWithHistory(): void
    {
        $source = $this->board('Source', tenantKey: 'acme');
        $target = $this->board('Target', tenantKey: 'acme');
        $task   = $this->task($source, 'T');
        $fields = $this->fields();
        $cost   = $fields->define($this->owner, $source, null, 'Cost', CustomFieldType::Number);
        $layer  = $fields->define($this->owner, $source, null, 'Layer', CustomFieldType::Dropdown, [['name' => 'infra', 'color' => null]]);
        $notes  = $fields->define($this->owner, $source, null, 'Notes', CustomFieldType::Text);
        $team   = $fields->define($this->owner, null, 'acme', 'Team', CustomFieldType::Text);
        $fields->define($this->owner, $target, null, 'Cost', CustomFieldType::Number);
        $fields->define($this->owner, $target, null, 'Layer', CustomFieldType::Dropdown, [['name' => 'domain', 'color' => null], ['name' => 'infra', 'color' => '#00ff00']]);
        $fields->define($this->owner, $target, null, 'Notes', CustomFieldType::Number);
        foreach ([[$cost, '12'], [$layer, 'infra'], [$notes, 'keep me'], [$team, 'core']] as [$field, $value]) {
            $fields->setField($task, $field->getId(), $value, $this->owner);
        }

        $result = $this->restructurer()->moveTasks($this->owner, [$task->getId()], $target->getId());

        self::assertSame([$task->getId() => ['Notes']], $result->droppedFieldValues, 'no number field may hold a text');
        $this->clear();
        $moved  = $this->reload($task);
        $values = $fields->valuesOf([$task->getId()])[$task->getId()];
        self::assertSame(['Team:core', 'Cost:12', 'Layer:infra'], array_map(static fn (TaskCustomFieldValue $v): string => $v->getField()->getName() . ':' . $fields->display($v), $values));
        foreach ($values as $value) {
            self::assertTrue($value->getField()->getBoardId() === null || $value->getField()->getBoardId() === $moved->getBoard()->getId());
        }
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => TaskChangeType::CustomField, 'newValue' => null]);
        self::assertSame('keep me', $row?->getOldValue());
        foreach ([$cost, $layer, $notes] as $field) {
            self::assertFalse($fields->hasValues($field), $field->getName() . ' has no value on its board any more');
        }
        self::assertTrue($fields->hasValues($team));
    }

    private function fields(): TaskCustomFieldManager
    {
        $service = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }
}
