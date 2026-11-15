<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiManagementController;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiAudit;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiBoardOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskBoardEditor;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingQuotaListener;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_column;
use function array_reverse;
use function array_slice;
use function str_repeat;

/**
 * Spec 213 S6 (Full management): boards and columns through the UI's services; columns on this API's own status paths
 * (owner, Q-O2); deleting a board erases its tasks and keeps their time.
 */
final class ApiManagementTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
        foreach ([TaskBoardEvents::BOARD_CREATED, TaskBoardEvents::BOARD_UPDATED, TaskBoardEvents::BOARD_DELETED] as $name) {
            $this->dispatcher()->addListener($name, function () use ($name): void {
                $this->events[] = $name;
            });
        }
    }

    public function testCreateABoardInASpace(): void
    {
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => ' Launch ', 'markdown_content' => 'The **plan**', 'priority' => 1]);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame(['Launch', 'The **plan**', 0], [$json['name'], $json['content'], $json['task_count']]);
        self::assertSame(['To do', 'In progress', 'Done'], array_column($json['statuses'], 'status'), 'the default columns, as the UI makes them');
        self::assertSame([['field' => 'priority', 'reason' => 'not_supported']], $json['warnings']);
        self::assertSame([TaskBoardEvents::BOARD_CREATED], $this->events);
        self::assertSame('board_create', $this->quota()->asked[0]->action);
        self::assertSame('tenant-a', $this->em->find(TaskBoard::class, $json['id'])?->getTenantKey());
        self::assertSame('board.create', $this->audit()->events[0]->operation);
    }

    public function testCreateRefusals(): void
    {
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => '']);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => str_repeat('x', 256)]);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', '/api/tasks/v2/space/space-nothing/list', ['name' => 'x']);
        $this->assertRefused(404, 'ACCESS_001');

        $this->quota()->deny = ['board_create'];
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => 'Over']);
        $this->assertRefused(403, 'PLAN_002');
        self::assertSame('Your plan is full.', $this->json()['err']);
        $this->quota()->deny = [];

        // Where names one board of the space: it may list the space, not create in it.
        $this->issueToken(where: TaskApiWhere::limitedTo([], [$this->roadmap->getId()]));
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => 'Outside']);
        $this->assertRefused(404, 'ACCESS_001');
        self::assertCount(1, $this->em->getRepository(TaskBoard::class)->findAll());
    }

    public function testUpdateABoard(): void
    {
        $slug = $this->roadmap->getSlug();
        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId(), ['name' => 'Renamed', 'content' => 'Notes', 'unset_status' => true]);

        self::assertSame(['Renamed', 'Notes'], [$this->json()['name'], $this->json()['content']]);
        self::assertSame([['field' => 'unset_status', 'reason' => 'not_supported']], $this->json()['warnings']);
        self::assertSame([TaskBoardEvents::BOARD_UPDATED], $this->events);
        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId(), []);
        self::assertSame('Renamed', $this->json()['name'], 'nothing given, nothing changed');
        $this->em->clear();
        self::assertSame($slug, $this->em->find(TaskBoard::class, $this->roadmap->getId())?->getSlug());
    }

    public function testDeleteABoardErasesItsTasksAndKeepsTheirTime(): void
    {
        $task  = $this->task($this->roadmap, 'Timed');
        $entry = new TaskTimeEntry($task, $this->owner, 25, new DateTimeImmutable('2026-11-01'));
        $task->addTimeEntry($entry);
        $this->em->persist($entry);
        $this->em->flush();

        $this->send('DELETE', '/api/tasks/v2/list/' . $this->roadmap->getId());

        self::assertSame([], $this->json());
        $this->em->clear();
        self::assertNull($this->em->find(TaskBoard::class, $this->roadmap->getId()));
        self::assertSame(25, $this->em->find(TaskTimeEntry::class, $entry->getId())?->getMinutes());
        self::assertContains(TaskBoardEvents::BOARD_DELETED, $this->events);
        self::assertSame('board.delete', $this->audit()->events[0]->operation);
    }

    public function testABigBoardIsDeletedInSlices(): void
    {
        foreach (['a', 'b', 'c'] as $title) {
            $this->task($this->roadmap, $title);
        }
        $clock = new class implements ClockInterface {
            private int $now = 1_790_000_000;

            public function now(): DateTimeImmutable
            {
                $this->now += 600;

                return new DateTimeImmutable('@' . $this->now);
            }
        };
        $eraser     = new TaskBoardEraser($this->service(EntityManagerInterface::class), $this->service(TaskBoardResourceAccessChecker::class), $this->dispatcher(), null, $clock, timeBudgetSeconds: 1, cursorSecret: 'test-secret');
        $operations = new TaskApiBoardOperations(
            $this->service(TaskApiGuard::class),
            $this->service(TaskApiAudit::class),
            $this->service(TaskBoardAccessCheckerInterface::class),
            $this->service(TaskBoardCreator::class),
            new TaskBoardEditor($this->service('Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface')),
            $eraser,
            $this->service(BoardColumnManager::class),
            $this->service(TaskApiQueryRepository::class),
            $this->service(ValidatorInterface::class),
            eraseBatchSize: 1,
        );
        $controller = new TaskApiManagementController($operations, $this->service(TaskApiReadOperations::class), new TaskApiReadSerializer(), 1024);
        $request    = Request::create('/', 'DELETE');
        $request->attributes->set('_nowo_task_board_api_principal', new TaskApiPrincipal($this->owner, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name'));

        $first = $controller->deleteBoard($request, $this->roadmap->getId());
        self::assertSame(202, $first->getStatusCode());
        self::assertSame('1', $first->headers->get('Retry-After'));
        self::assertSame('{"status":"in_progress"}', $first->getContent());
        $calls = 1;
        while ($controller->deleteBoard($request, $this->roadmap->getId())->getStatusCode() === 202) {
            self::assertLessThan(10, ++$calls);
        }
        $this->em->clear();
        self::assertNull($this->em->find(TaskBoard::class, $this->roadmap->getId()));
    }

    public function testColumnsAsStatuses(): void
    {
        $task = $this->task($this->roadmap, 'Moves');
        $base = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status';
        $this->send('POST', $base, ['status' => 'Review', 'color' => '#3b82f6', 'type' => 'custom']);
        $statuses = $this->json()['statuses'];
        self::assertSame(['To do', 'Doing', 'Done', 'Review'], array_column($statuses, 'status'));
        self::assertSame('#3b82f6', $statuses[3]['color']);
        // Since 2.19.0 (FR-033): `type` is read; the first type set writes the position types of the other columns.
        self::assertArrayNotHasKey('warnings', $this->json());
        self::assertSame(['open', 'custom', 'closed', 'custom'], array_column($statuses, 'type'));
        $review = $statuses[3]['id'];

        $this->send('PUT', $base . '/' . $review, ['status' => 'Code review']);
        self::assertSame('Code review', $this->json()['statuses'][3]['status']);
        self::assertSame('#3b82f6', $this->json()['statuses'][3]['color'], 'what is not given stays');
        $this->send('PUT', $base . '/' . $review, ['color' => null]);
        self::assertNull($this->json()['statuses'][3]['color']);

        $ids = array_column($statuses, 'id');
        $this->send('PUT', $base . '/order', ['order' => array_reverse($ids)]);
        self::assertSame(['Code review', 'Done', 'Doing', 'To do'], array_column($this->json()['statuses'], 'status'));
        foreach ([['order' => [$ids[0]]], ['order' => [...$ids, $ids[0]]], ['order' => [$ids[0], $ids[1], $ids[2], 'nothing']], ['order' => 'x']] as $body) {
            $this->send('PUT', $base . '/order', $body);
            $this->assertRefused(400, 'INPUT_002');
        }

        foreach ([[[], 400, 'INPUT_002'], [['status' => ''], 400, 'INPUT_002'], [['status' => 'x', 'color' => 'red; x'], 400, 'INPUT_002']] as [$body, $status, $ecode]) {
            $this->send('POST', $base, $body);
            $this->assertRefused($status, $ecode);
        }
        $this->send('PUT', $base . '/nothing', ['status' => 'x']);
        $this->assertRefused(404, 'ACCESS_001');

        $this->send('DELETE', $base . '/' . $task->getColumn()?->getId());
        self::assertResponseIsSuccessful();
        foreach (array_slice(array_column($this->json()['statuses'], 'id'), 1) as $id) {
            $this->send('DELETE', $base . '/' . $id);
        }
        self::assertCount(1, $this->json()['statuses']);
        $this->send('DELETE', $base . '/' . $this->json()['statuses'][0]['id']);
        $this->assertRefused(409, 'LIST_001');
        $this->em->clear();
        self::assertInstanceOf(BoardColumn::class, $this->em->find(Task::class, $task->getId())?->getColumn(), 'its tasks move to a remaining column');
    }

    public function testAColumnIconIsOneEmojiOrAnOfferedIconId(): void
    {
        $base = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status';
        $this->send('POST', $base, ['status' => 'Review', 'icon' => '👀']);
        $statuses = $this->json()['statuses'];
        self::assertSame('👀', $statuses[3]['icon']);
        self::assertNull($statuses[0]['icon']);
        $review = $statuses[3]['id'];

        $this->send('PUT', $base . '/' . $review, ['status' => 'Code review']);
        self::assertSame('👀', $this->json()['statuses'][3]['icon'], 'what is not given stays');
        $this->send('PUT', $base . '/' . $review, ['icon' => ' tabler:bug ']);
        self::assertSame('tabler:bug', $this->json()['statuses'][3]['icon'], 'an id of board.column_icons');
        $this->send('PUT', $base . '/' . $review, ['icon' => '']);
        self::assertNull($this->json()['statuses'][3]['icon']);
        $this->send('PUT', $base . '/' . $review, ['icon' => '✅']);
        $this->send('PUT', $base . '/' . $review, ['icon' => null]);
        self::assertNull($this->json()['statuses'][3]['icon']);

        foreach (['<b>x</b>', 'Done', '👀👀', 'tabler:not-offered', str_repeat('a', 70), 'x:y" onload="1'] as $icon) {
            $this->send('PUT', $base . '/' . $review, ['icon' => $icon]);
            $this->assertRefused(400, 'INPUT_002');
        }
        $this->send('POST', $base, ['status' => 'X', 'icon' => ['👀']]);
        $this->assertRefused(400, 'INPUT_002');
        $this->em->clear();
        self::assertNull($this->em->find(BoardColumn::class, $review)?->getIcon(), 'a refused icon is not stored');
    }

    public function testManagementNeedsFullAndTheBoardsManageDecision(): void
    {
        $endpoints = $this->endpoints();
        foreach ([TaskApiLevel::MyTasks, TaskApiLevel::Read, TaskApiLevel::Tasks] as $level) {
            $this->issueToken($level);
            foreach ($endpoints as $name => $call) {
                $call();
                $this->assertRefused(403, 'TOKEN_001');
            }
        }

        $this->issueToken(where: TaskApiWhere::limitedTo(['tenant-b'], []));
        foreach ($endpoints as $name => $call) {
            $call();
            $this->assertRefused(404, 'ACCESS_001');
        }

        ApiTestHost::$planRefusesTenants = ['tenant-a'];
        $this->issueToken();
        foreach ($endpoints as $name => $call) {
            $call();
            $this->assertRefused(403, 'PLAN_001');
        }
        ApiTestHost::$planRefusesTenants = [];

        foreach ($endpoints as $name => $call) {
            $call('bt_revoked_placeholder');
            $this->assertRefused(401, 'OAUTH_019');
        }

        // The person may view and edit tasks, not manage the board, nor create in the tenant.
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE) {
                $event->deny();
            }
        });
        foreach ($endpoints as $name => $call) {
            if ($name === 'create board') {
                continue;
            }
            $call();
            $this->assertRefused(403, 'ACCESS_002');
        }
        self::assertSame([], $this->audit()->events);
    }

    /** Since 2.19.0 (FR-033): column types on the status paths. */
    public function testColumnTypes(): void
    {
        $base = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status';
        $this->send('POST', $base, ['status' => 'Cancelled', 'type' => 'closed']);
        self::assertSame(['open', 'custom', 'closed', 'closed'], array_column($this->json()['statuses'], 'type'));
        $this->send('POST', $base, ['status' => 'Third', 'type' => 'closed']);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', $base, ['status' => 'Odd', 'type' => 'weird']);
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId());
        $doing = $this->json()['statuses'][1]['id'];
        $this->send('PUT', $base . '/' . $doing, ['type' => 'done']);
        self::assertSame('done', $this->json()['statuses'][1]['type']);
    }

    /** Since 2.5.0 (TB-13): a host that denies `manage_columns` refuses the four status paths, not the board ones. */
    public function testTheColumnDecisionGuardsTheStatusPaths(): void
    {
        $endpoints = $this->endpoints();
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS) {
                $event->deny();
            }
        });
        foreach (['create column', 'update column', 'reorder', 'delete column'] as $name) {
            $endpoints[$name]();
            $this->assertRefused(403, 'ACCESS_002');
        }
        self::assertSame([], $this->audit()->events);
        self::assertCount(3, $this->roadmap->getColumns());

        $endpoints['update board']();
        self::assertResponseIsSuccessful('the board itself is not a column');
    }

    /**
     * @return array<string, callable(string=): void>
     */
    private function endpoints(): array
    {
        $board  = $this->roadmap->getId();
        $column = $this->roadmap->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $column);
        $columnId = $column->getId();
        $ids      = array_map(static fn (BoardColumn $c): string => $c->getId(), $this->roadmap->getColumns()->toArray());
        $send     = fn (string $method, string $path, ?array $body = null): callable => fn (string $token = self::TOKEN) => $this->send($method, '/api/tasks/v2/' . $path, $body, [], $token);

        return [
            'create board'  => $send('POST', 'space/space-tenant-a/list', ['name' => 'New']),
            'update board'  => $send('PUT', 'list/' . $board, ['name' => 'Renamed']),
            'delete board'  => $send('DELETE', 'list/' . $board),
            'create column' => $send('POST', 'list/' . $board . '/status', ['status' => 'Review']),
            'update column' => $send('PUT', 'list/' . $board . '/status/' . $columnId, ['status' => 'Renamed']),
            'reorder'       => $send('PUT', 'list/' . $board . '/status/order', ['order' => $ids]),
            'delete column' => $send('DELETE', 'list/' . $board . '/status/' . $columnId),
        ];
    }

    private function audit(): ApiAuditRecorder
    {
        return $this->service('app.api_audit');
    }

    private function quota(): RecordingQuotaListener
    {
        return $this->service('app.quota_listener');
    }

    private function dispatcher(): EventDispatcherInterface
    {
        return $this->service('event_dispatcher');
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    private function service(string $id): mixed
    {
        return self::getContainer()->get($id);
    }
}
