<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Api\ApiTestCase;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_column;
use function array_map;
use function json_encode;
use function sort;
use function str_replace;
use function trim;
use function urlencode;

/**
 * Since 2.17.0 (Beacon spec 234, FR-028): native custom fields — definitions per tenant or board, values, the Task API
 * (`GET /v2/list/{id}/field`, `POST /v2/task/{id}/field/{field_id}`, `custom_fields` filters) and tenant isolation.
 */
final class CustomFieldsTest extends ApiTestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->board          = $this->board('Story', tenantKey: 'tenant-a');
        $this->issueToken();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testDefinitionsValuesAndHistory(): void
    {
        $fields = $this->fields();
        $repo   = $fields->define($this->owner, null, 'tenant-a', 'Repositorio destino', CustomFieldType::Text);
        $layer  = $fields->define($this->owner, $this->board, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'domain', 'color' => '#ff0000'], ['name' => 'infra', 'color' => null]]);
        $points = $fields->define($this->owner, $this->board, null, 'Versión propuesta', CustomFieldType::Number);
        $fields->define($this->owner, null, 'tenant-b', 'Not here', CustomFieldType::Text);
        $other = $this->board('Other', tenantKey: 'tenant-a');

        self::assertSame(['Repositorio destino', 'Capa', 'Versión propuesta'], array_map(static fn (TaskCustomField $f): string => $f->getName(), $fields->fieldsFor($this->board)));
        self::assertSame(['Repositorio destino'], array_map(static fn (TaskCustomField $f): string => $f->getName(), $fields->fieldsFor($other)), 'a board field stays on its board');

        $task = $this->task($this->board, 'C-1');
        $fields->setField($task, $repo->getId(), 'core-nowo', $this->owner);
        $fields->setField($task, $layer->getId(), 'infra', $this->owner);
        $fields->setField($task, $points->getId(), '2.50', $this->owner);
        $values = $fields->valuesOf([$task->getId()])[$task->getId()];
        self::assertSame(['core-nowo', 'infra', '2.5'], array_map($fields->display(...), $values));
        self::assertSame(3, $this->em->getRepository(TaskChangeHistory::class)->count(['task' => $task, 'changeType' => TaskChangeType::CustomField]));

        try {
            $fields->setField($task, $points->getId(), 'three', $this->owner);
            self::fail('not a number');
        } catch (TaskCustomFieldRefusedException $refused) {
            self::assertSame(TaskCustomFieldRefusedException::INVALID, $refused->reason);
        }
        $otherTask = $this->task($other, 'Elsewhere');
        try {
            $fields->setField($otherTask, $layer->getId(), 'infra', $this->owner);
            self::fail('a field of another board');
        } catch (TaskCustomFieldRefusedException $refused) {
            self::assertSame(TaskCustomFieldRefusedException::NOT_FOUND, $refused->reason);
        }
        $fields->setField($task, $points->getId(), null, $this->owner);
        self::assertCount(2, $fields->valuesOf([$task->getId()])[$task->getId()]);
    }

    public function testTheApiReadsWritesAndFilters(): void
    {
        $fields = $this->fields();
        $layer  = $fields->define($this->owner, $this->board, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'domain', 'color' => '#00ff00'], ['name' => 'infra']]);
        $points = $fields->define($this->owner, null, 'tenant-a', 'Points', CustomFieldType::Number);
        $a      = $this->task($this->board, 'A');
        $b      = $this->task($this->board, 'B');
        $this->task($this->board, 'C');

        $this->get('/api/tasks/v2/list/' . $this->board->getId() . '/field');
        self::assertResponseIsSuccessful();
        $listed = $this->listOf('fields');
        self::assertSame(['Capa', 'Points'], array_column($listed, 'name'));
        self::assertSame(['drop_down', 'number'], array_column($listed, 'type'));
        self::assertSame('#00ff00', $listed[0]['type_config']['options'][0]['color']);

        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/' . $layer->getId(), ['value' => 'domain']);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/' . $points->getId(), ['value' => 3]);
        $this->send('POST', '/api/tasks/v2/task/' . $b->getId() . '/field/' . $points->getId(), ['value' => 8]);
        $this->send('POST', '/api/tasks/v2/task/' . $b->getId() . '/field/' . $points->getId(), ['value' => 'many']);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/not-a-native-field', ['value' => 'x']);
        $this->assertRefused(409, 'FIELD_001');

        $this->get('/api/tasks/v2/task/' . $a->getId());
        $custom = $this->json()['custom_fields'];
        self::assertSame([$layer->getId(), $points->getId()], array_column($custom, 'id'));
        self::assertSame([$layer->optionNamed('domain')['id'] ?? null, 3], [$custom[0]['value'], $custom[1]['value']], 'a dropdown answers the option id');

        $filter = fn (array $conditions): array => $this->titles('/api/tasks/v2/list/' . $this->board->getId() . '/task?custom_fields=' . urlencode((string) json_encode($conditions)));
        self::assertSame(['B'], $filter([['field_id' => $points->getId(), 'operator' => '>', 'value' => 5]]));
        self::assertSame(['A'], $filter([['field_id' => $points->getId(), 'operator' => '<', 'value' => '5']]));
        self::assertSame(['A'], $filter([['field_id' => $layer->getId(), 'operator' => '=', 'value' => 'domain']]));
        self::assertSame(['B', 'C'], $filter([['field_id' => $layer->getId(), 'operator' => '!=', 'value' => 'domain']]));
        self::assertSame(['A', 'B'], $filter([['field_id' => $points->getId(), 'operator' => 'IS NOT NULL']]));
        self::assertSame(['C'], $filter([['field_id' => $points->getId(), 'operator' => 'IS NULL']]));
        self::assertSame(['A', 'B'], $filter([['field_id' => $points->getId(), 'operator' => 'ANY', 'value' => [3, 8]]]));

        $this->get('/api/tasks/v2/list/' . $this->board->getId() . '/task?custom_fields=' . urlencode((string) json_encode([['field_id' => $layer->getId(), 'operator' => '<', 'value' => 1]])));
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/list/' . $this->board->getId() . '/task?custom_fields=nonsense');
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testAnotherTenantsFieldIsNeverReachable(): void
    {
        $theirs = $this->fields()->define($this->owner, null, 'tenant-b', 'Theirs', CustomFieldType::Text);
        $task   = $this->task($this->board, 'Mine');

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/field/' . $theirs->getId(), ['value' => 'x']);
        $this->assertRefused(409, 'FIELD_001');
        $this->get('/api/tasks/v2/list/' . $this->board->getId() . '/task?custom_fields=' . urlencode((string) json_encode([['field_id' => $theirs->getId(), 'operator' => 'IS NULL']])));
        $this->assertRefused(400, 'INPUT_002');
        self::assertSame([], $this->fields()->valuesOf([$task->getId()]));
    }

    public function testTheListViewShowsAColumnPerFieldAndFiltersByOperator(): void
    {
        $board  = $this->board('Plain');
        $points = $this->fields()->define($this->owner, $board, null, 'Points', CustomFieldType::Number);
        $a      = $this->task($board, 'Small');
        $b      = $this->task($board, 'Big');
        $this->task($board, 'Unset');
        $this->fields()->setField($a, $points->getId(), '2', $this->owner);
        $this->fields()->setField($b, $points->getId(), '13', $this->owner);
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Points', $crawler->filter('thead')->text());
        self::assertSame(['13', '2', '—'], $crawler->filter('td[data-custom-field="' . $points->getId() . '"]')->each(static fn ($cell): string => trim($cell->text())));
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-custom-field-filter"]'));

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list?cf_field=' . $points->getId() . '&cf_op=' . urlencode('>') . '&cf_value=5');
        self::assertResponseIsSuccessful();
        self::assertSame(['Big'], $crawler->filter('.nowo-task-list__title a')->each(static fn ($link): string => trim($link->text())));
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list?cf_field=' . $points->getId() . '&cf_op=' . urlencode('IS NULL'));
        self::assertSame(['Unset'], $crawler->filter('.nowo-task-list__title a')->each(static fn ($link): string => trim($link->text())));
    }

    public function testTheTaskFormHasARowPerField(): void
    {
        $board = $this->board('Plain');
        $layer = $this->fields()->define($this->owner, $board, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'domain'], ['name' => 'infra']]);
        $task  = $this->task($board, 'Task');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-custom-field"]'));
        $form = $crawler->filter('#nowo-task-edit-form')->form();
        $name = $form->getName() . '[cf_' . str_replace('-', '_', $layer->getId()) . ']';
        $form[$name]->select((string) ($layer->optionNamed('infra')['id'] ?? ''));
        $this->client->submit($form);

        $this->em->clear();
        $values = $this->fields()->valuesOf([$task->getId()])[$task->getId()] ?? [];
        self::assertSame(['infra'], array_map($this->fields()->display(...), $values));
    }

    /**
     * @return list<string>
     */
    private function titles(string $path): array
    {
        $this->get($path);
        self::assertResponseIsSuccessful();
        $titles = array_column($this->listOf('tasks'), 'name');
        sort($titles);

        return $titles;
    }

    private function fields(): TaskCustomFieldManager
    {
        $service = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }
}
