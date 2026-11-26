<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Form\TaskCustomFieldDefinitionFormType;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_column;
use function array_map;

/**
 * Since 3.3.0: the board's Custom fields page — list (tenant fields read-only, the board's own with edit and delete),
 * add, edit (options as rows, new ones one per line), delete; the restructure policy decides; every write has its CSRF
 * token and the definition's version.
 */
final class CustomFieldPagesTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $this->board = $this->board('Fields');
    }

    public function testAManagerAddsEditsAndDeletesTheBoardsFields(): void
    {
        $this->fields()->define($this->owner, null, null, 'Everywhere', CustomFieldType::Text);
        $imported = $this->fields()->defineFromSource($this->owner, $this->fresh(), 'clickup:f1', 'From ClickUp', CustomFieldType::Text);
        self::assertInstanceOf(TaskCustomField::class, $imported);
        $this->login($this->owner);

        // The board and list views link the page.
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId());
        self::assertSame(self::BASE . $this->board->getId() . '/fields', $crawler->filter('a[href$="/fields"]')->attr('href'));
        $this->client->request('GET', self::BASE . $this->board->getId() . '/list');
        self::assertSelectorExists('a[href$="/fields"]');

        $crawler = $this->client->request('GET', self::BASE . $this->board->getId() . '/fields');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-testid="nowo-task-field-row"]'));
        self::assertSelectorExists('[data-testid="nowo-task-field-tenant"]');
        self::assertSelectorTextContains('[data-testid="nowo-task-field-source"]', 'ClickUp');
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-field-edit"]'), 'only the board\'s own fields');

        // Add a dropdown with options (colour after a space).
        $crawler = $this->client->request('GET', self::BASE . $this->board->getId() . '/fields/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="task_board_custom_field"]')->form();
        $this->client->submit($form, ['task_board_custom_field[name]' => 'Stage', 'task_board_custom_field[type]' => 'dropdown', 'task_board_custom_field[newOptions]' => "Draft #ff0000\n\nLive"]);
        self::assertResponseRedirects(self::BASE . $this->board->getId() . '/fields');
        $stage = $this->named('Stage');
        self::assertSame([['Draft', '#ff0000'], ['Live', null]], array_map(static fn (array $o): array => [$o['name'], $o['color']], $stage->getOptions()));

        // Duplicate options: refused with the message, nothing written.
        $crawler = $this->client->request('GET', self::BASE . $this->board->getId() . '/fields/new');
        $this->client->submit($crawler->filter('form[name="task_board_custom_field"]')->form(), ['task_board_custom_field[name]' => 'Twice', 'task_board_custom_field[type]' => 'dropdown', 'task_board_custom_field[newOptions]' => "A\nA"]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'names must be unique');

        // Edit: rename an option, recolour, remove one, add one.
        $crawler = $this->client->request('GET', self::BASE . $this->board->getId() . '/fields/' . $stage->getId());
        self::assertCount(2, $crawler->filter('[data-testid="nowo-task-field-option-row"]'));
        $this->client->submit($crawler->filter('form[name="task_board_custom_field"]')->form(), [
            'task_board_custom_field[name]'       => 'Stage',
            'task_board_custom_field[o_0]'        => 'Borrador',
            'task_board_custom_field[c_0]'        => '',
            'task_board_custom_field[r_1]'        => true,
            'task_board_custom_field[newOptions]' => 'Done #00ff00',
        ]);
        self::assertResponseRedirects();
        $edited = $this->named('Stage');
        self::assertSame([['Borrador', null], ['Done', '#00ff00']], array_map(static fn (array $o): array => [$o['name'], $o['color']], $edited->getOptions()));
        self::assertSame($stage->getOptions()[0]['id'] ?? null, $edited->getOptions()[0]['id'], 'a renamed option stays the same option');
        self::assertSame(2, $edited->getVersion());

        // A stale form (version 1) is refused; a bad colour is a form error.
        $this->client->request('POST', self::BASE . $this->board->getId() . '/fields/' . $stage->getId(), ['task_board_custom_field' => ['name' => 'Old', 'version' => '1', 'o_0' => 'x', 'o_1' => 'y', '_token' => PredictableCsrfTokenManager::value(TaskCustomFieldDefinitionFormType::CSRF_TOKEN_ID)]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'changed this field meanwhile');
        $this->client->request('POST', self::BASE . $this->board->getId() . '/fields/' . $stage->getId(), ['task_board_custom_field' => ['name' => 'Stage', 'version' => '2', 'o_0' => 'x', 'c_0' => 'red', 'o_1' => 'y', '_token' => PredictableCsrfTokenManager::value(TaskCustomFieldDefinitionFormType::CSRF_TOKEN_ID)]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Stage', $this->named('Stage')->getName());

        // A text field: no options on its form.
        $crawler = $this->client->request('GET', self::BASE . $this->board->getId() . '/fields/' . $imported->getId());
        self::assertSelectorExists('[data-testid="nowo-task-field-type"]');
        self::assertCount(0, $crawler->filter('textarea'));
        $this->client->submit($crawler->filter('form[name="task_board_custom_field"]')->form(), ['task_board_custom_field[name]' => 'Renamed here']);
        self::assertSame('clickup:f1', $this->named('Renamed here')->getSourceRef());

        // Delete: a stale version is refused, the current one deletes.
        $this->client->request('POST', self::BASE . $this->board->getId() . '/fields/' . $stage->getId() . '/delete', ['version' => '1', '_token' => PredictableCsrfTokenManager::value('task_board_custom_field_delete')]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'changed this field meanwhile');
        $this->client->request('POST', self::BASE . $this->board->getId() . '/fields/' . $stage->getId() . '/delete', ['version' => '2', '_token' => PredictableCsrfTokenManager::value('task_board_custom_field_delete')]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Field deleted.');
        self::assertNotContains('Stage', array_column(array_map(static fn (TaskCustomField $f): array => ['name' => $f->getName()], $this->fields()->fieldsFor($this->fresh())), 'name'));
        $this->client->request('POST', self::BASE . $this->board->getId() . '/fields/' . $imported->getId() . '/delete', ['version' => '2']);
        self::assertResponseStatusCodeSame(403, 'no CSRF token');
    }

    public function testOnlyThoseWhoMayRestructureTheBoardSeeThePage(): void
    {
        $this->client->request('GET', self::BASE . $this->board->getId() . '/fields');
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'signed in only');
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $this->board->getId() . '/fields');
        self::assertSelectorExists('[data-testid="nowo-task-fields-empty"]');
        $tenantField = $this->fields()->define($this->owner, null, null, 'Everywhere', CustomFieldType::Text);
        $member      = $this->user('member@example.com');
        $this->login($member);
        $this->client->request('GET', self::BASE . $this->board->getId() . '/fields');
        self::assertResponseStatusCodeSame(404);

        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $this->board->getId() . '/fields/' . $tenantField->getId());
        self::assertResponseStatusCodeSame(404, 'a tenant field is not edited from a board');
        $this->client->request('GET', self::BASE . '00000000-0000-4000-8000-000000000000/fields');
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', self::BASE . $this->board->getId() . '/fields');
        self::assertSelectorExists('[data-testid="nowo-task-field-add"]');
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-field-delete"]'));

        // Seeing the board is not enough: the manage right (restructure policy) is.
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE) {
                $event->deny();
            }
        });
        $this->client->disableReboot();
        $this->client->request('GET', self::BASE . $this->board->getId() . '/fields');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId());
        self::assertCount(0, $crawler->filter('a[href$="/fields"]'));
    }

    private function named(string $name): TaskCustomField
    {
        $this->em->clear();
        foreach ($this->fields()->fieldsFor($this->fresh()) as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }
        self::fail('no field ' . $name);
    }

    private function fresh(): TaskBoard
    {
        $board = $this->em->find(TaskBoard::class, $this->board->getId());
        self::assertInstanceOf(TaskBoard::class, $board);

        return $board;
    }

    private function fields(): TaskCustomFieldManager
    {
        $service = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }
}
