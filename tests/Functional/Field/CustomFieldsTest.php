<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldListView;
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
use function str_repeat;
use function str_replace;
use function trim;
use function urlencode;

use const INF;

/**
 * Since 2.19.0 (Beacon spec 234, FR-028): native custom fields — definitions per tenant or board, values, the Task API
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
        self::assertSame(['Points', 'Capa'], array_column($listed, 'name'), 'the tenant\'s fields, then the board\'s');
        self::assertSame(['number', 'drop_down'], array_column($listed, 'type'));
        self::assertSame('#00ff00', $listed[1]['type_config']['options'][0]['color']);
        // The 2.2 read of the synced fields alone stays as it was.
        $reads = self::getContainer()->get(TaskApiReadOperations::class);
        self::assertInstanceOf(TaskApiReadOperations::class, $reads);
        self::assertSame([], $reads->boardFields(new TaskApiPrincipal($this->owner, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name'), $this->board->getId()));

        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/' . $layer->getId(), ['value' => 'domain']);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/' . $points->getId(), ['value' => 3]);
        $this->send('POST', '/api/tasks/v2/task/' . $b->getId() . '/field/' . $points->getId(), ['value' => 8]);
        $this->send('POST', '/api/tasks/v2/task/' . $b->getId() . '/field/' . $points->getId(), ['value' => 'many']);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/' . $points->getId(), ['other' => 1]);
        $this->assertRefused(400, 'INPUT_001');
        $this->send('POST', '/api/tasks/v2/task/' . $a->getId() . '/field/not-a-native-field', ['value' => 'x']);
        $this->assertRefused(409, 'FIELD_001');

        $this->get('/api/tasks/v2/task/' . $a->getId());
        $custom = $this->json()['custom_fields'];
        self::assertSame([$points->getId(), $layer->getId()], array_column($custom, 'id'));
        self::assertSame([3, $layer->optionNamed('domain')['id'] ?? null], [$custom[0]['value'], $custom[1]['value']], 'a dropdown answers the option id');

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
        $cells = $crawler->filter('td[data-custom-field="' . $points->getId() . '"]')->each(static fn ($cell): string => trim($cell->text()));
        sort($cells);
        self::assertSame(['2', '13', '—'], $cells);
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-custom-field-filter"]'));

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list?cf_field=' . $points->getId() . '&cf_op=' . urlencode('>') . '&cf_value=5');
        self::assertResponseIsSuccessful();
        self::assertSame(['Big'], $crawler->filter('.nowo-task-list__title a')->each(static fn ($link): string => trim($link->text())));
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list?cf_field=' . $points->getId() . '&cf_op=' . urlencode('IS NULL'));
        self::assertSame(['Unset'], $crawler->filter('.nowo-task-list__title a')->each(static fn ($link): string => trim($link->text())));
        // An incomplete filter (no field, or no value where one is needed) filters nothing.
        foreach (['?cf_op=' . urlencode('IS NULL'), '?cf_field=' . $points->getId() . '&cf_op=' . urlencode('>')] as $query) {
            $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list' . $query);
            self::assertCount(3, $crawler->filter('.nowo-task-list__title a'));
        }
    }

    public function testTheTaskFormHasARowPerField(): void
    {
        $board = $this->board('Plain');
        $layer = $this->fields()->define($this->owner, $board, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'domain'], ['name' => 'infra']]);
        $num   = $this->fields()->define($this->owner, $board, null, 'Points', CustomFieldType::Number);
        $text  = $this->fields()->define($this->owner, $board, null, 'Repo', CustomFieldType::Text);
        $task  = $this->task($board, 'Task');
        $this->fields()->setField($task, $num->getId(), '3', $this->owner);
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-testid="nowo-task-custom-field"]'));
        $form   = $crawler->filter('#nowo-task-edit-form')->form();
        $prefix = $form->getName() . '[cf_';
        $form[$prefix . str_replace('-', '_', $layer->getId()) . ']']->select($layer->optionNamed('infra')['id'] ?? '');
        $form[$prefix . str_replace('-', '_', $text->getId()) . ']'] = str_repeat('x', TaskCustomFieldManager::TEXT_MAX + 1);
        $this->client->submit($form);

        $this->em->clear();
        $values = $this->fields()->valuesOf([$task->getId()])[$task->getId()] ?? [];
        $shown  = array_map($this->fields()->display(...), $values);
        sort($shown);
        self::assertSame(['3', 'infra'], $shown, 'the unchanged number stays, the too long text is refused');
        $this->client->followRedirect();
        self::assertStringContainsString('Repo', (string) $this->client->getResponse()->getContent());
    }

    public function testTheListViewOperators(): void
    {
        $view = self::getContainer()->get(TaskCustomFieldListView::class);
        self::assertInstanceOf(TaskCustomFieldListView::class, $view);
        $layer = $this->fields()->define($this->owner, $this->board, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'a'], ['name' => 'b']]);
        $num   = $this->fields()->define($this->owner, $this->board, null, 'N', CustomFieldType::Number);
        $text  = $this->fields()->define($this->owner, $this->board, null, 'T', CustomFieldType::Text);
        $task  = $this->task($this->board, 'Task');
        $this->fields()->setField($task, $layer->getId(), 'a', $this->owner);
        $this->fields()->setField($task, $num->getId(), '4', $this->owner);
        $this->fields()->setField($task, $text->getId(), 'x', $this->owner);
        $values = [];
        foreach ($this->fields()->valuesOf([$task->getId()])[$task->getId()] as $value) {
            $values[$value->getField()->getId()] = $value;
        }
        self::assertTrue($view->holds($layer, $values[$layer->getId()], CustomFieldOperator::Equals, ['a']));
        self::assertTrue($view->holds($layer, $values[$layer->getId()], CustomFieldOperator::In, ['b', 'a']));
        self::assertTrue($view->holds($layer, $values[$layer->getId()], CustomFieldOperator::NotEquals, ['b']));
        self::assertTrue($view->holds($num, $values[$num->getId()], CustomFieldOperator::LessThan, ['5']));
        self::assertFalse($view->holds($num, $values[$num->getId()], CustomFieldOperator::GreaterThan, ['5']));
        self::assertTrue($view->holds($num, $values[$num->getId()], CustomFieldOperator::Equals, ['4.0']));
        self::assertTrue($view->holds($text, $values[$text->getId()], CustomFieldOperator::IsSet, []));
        self::assertFalse($view->holds($text, null, CustomFieldOperator::IsSet, []));
        self::assertFalse($view->holds($text, null, CustomFieldOperator::Equals, ['x']));
    }

    public function testManagerEdges(): void
    {
        $fields = $this->fields();
        self::assertNull($fields->find(''));
        self::assertNull($fields->find('unknown'));
        $tenant = $fields->define($this->owner, null, 'tenant-a', 'Tenant field', CustomFieldType::Text);
        self::assertSame([$tenant->getId()], array_map(static fn (TaskCustomField $f): string => $f->getId(), $fields->tenantFields('tenant-a')));
        self::assertSame([], $fields->tenantFields(null));
        self::assertSame($tenant, $fields->find($tenant->getId()));
        $refused = static function (callable $call, string $reason): void {
            try {
                $call();
                self::fail('expected ' . $reason);
            } catch (TaskCustomFieldRefusedException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        };
        $refused(fn (): TaskCustomField => $fields->define($this->owner, $this->board, null, ' ', CustomFieldType::Text), TaskCustomFieldRefusedException::INVALID);
        $layer = $fields->define($this->owner, $this->board, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'a'], ['name' => 'b']]);
        $num   = $fields->define($this->owner, $this->board, null, 'N', CustomFieldType::Number);
        $task  = $this->task($this->board, 'T');
        $fields->setField($task, $layer->getId(), 'b', $this->owner);
        $fields->setField($task, $num->getId(), 1.25, $this->owner);
        $fields->setField($task, $num->getId(), 7, $this->owner);
        self::assertTrue($fields->hasValues($layer));
        $refused(fn () => $fields->setField($task, $num->getId(), 'NaN', $this->owner), TaskCustomFieldRefusedException::INVALID);
        $refused(fn () => $fields->setField($task, $num->getId(), '1e5', $this->owner), TaskCustomFieldRefusedException::INVALID);
        $refused(fn () => $fields->setField($task, $num->getId(), INF, $this->owner), TaskCustomFieldRefusedException::INVALID);
        $refused(fn () => $fields->setField($task, $layer->getId(), ['b'], $this->owner), TaskCustomFieldRefusedException::INVALID);
        $refused(fn () => $fields->setField($task, $tenant->getId(), str_repeat('x', TaskCustomFieldManager::TEXT_MAX + 1), $this->owner), TaskCustomFieldRefusedException::INVALID);
        $refused(fn (): TaskCustomField => $fields->update($this->owner, $layer, ' ', null, 0), TaskCustomFieldRefusedException::INVALID);

        // Dropping option b clears the value that held it.
        $fields->update($this->owner, $layer, 'Layer', [['id' => $layer->optionNamed('a')['id'] ?? null, 'name' => 'a']], 3);
        self::assertFalse($fields->hasValues($layer));
        $fields->update($this->owner, $num, 'Number', null, 4);
        $fields->delete($this->owner, $num);
        self::assertNull($fields->find($num->getId()));

        $fields->setField($task, $tenant->getId(), 'kept', $this->owner);
        $value = $fields->valuesOf([$task->getId()])[$task->getId()][0];
        self::assertNotSame('', $value->getId());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $value->getUpdatedAt());
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
