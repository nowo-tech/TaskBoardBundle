<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search;

use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Search\Header\TaskHeaderSearch;
use Nowo\TaskBoardBundle\Search\Header\TaskSearchExtras;
use Nowo\TaskBoardBundle\Search\Header\TaskTextMatch;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 3.3.0: with `search_kit.custom_fields` a query word also matches the tasks' native **text** field values (not a
 * drop-down's option id, not a number); off by default.
 */
final class HeaderSearchCustomFieldsTest extends HeaderSearchTestCase
{
    public function testTextValuesAreFoundOnlyWhenTurnedOn(): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $board  = $this->board('Sales');
        $fields = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $fields);
        $contact = $fields->define($this->owner, $board, null, 'Contact', CustomFieldType::Text);
        $stage   = $fields->define($this->owner, $board, null, 'Stage', CustomFieldType::Dropdown, [['name' => 'Acmecorp']]);
        $task    = $this->described($board, 'Call back', null);
        $other   = $this->described($board, 'Quote', null);
        $fields->setField($task, $contact->getId(), 'Acme Corp, Ana', $this->owner);
        $fields->setField($other, $stage->getId(), 'Acmecorp', $this->owner);

        self::assertSame([], $this->search(false)->search($this->viewer($this->owner), 'acme', 10)->hits, 'off by default');
        self::assertSame(['Call back'], self::titles($this->search(true)->search($this->viewer($this->owner), 'acme', 10)->hits));
        self::assertSame([], $this->search(true)->search($this->viewer($this->owner), 'an', 10)->hits, 'short words never scan values');
        self::assertSame(['Call back'], self::titles($this->search(true)->search($this->viewer($this->owner), 'call acme', 10)->hits));
        $option = $stage->optionNamed('Acmecorp')['id'] ?? '';
        self::assertSame([], $this->search(true)->search($this->viewer($this->owner), $option, 10)->hits, 'a drop-down holds an option id, never matched');
    }

    private function search(bool $customFields): TaskHeaderSearch
    {
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        $users = self::getContainer()->get(TaskBoardUsers::class);
        self::assertInstanceOf(TaskBoardUsers::class, $users);

        return new TaskHeaderSearch($this->scope(), $this->em, $checker, $this->mapper(), 100, new TaskTextMatch(customFields: $customFields), new TaskSearchExtras($this->em, $users));
    }
}
