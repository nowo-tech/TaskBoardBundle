<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function in_array;
use function json_encode;
use function sprintf;
use function str_contains;

/**
 * Spec 213 SC-001, read half: every read endpoint × the four levels × own task, another's task, another project, a board
 * outside where, a removed member, a revoked or expired token, the plan without the module. Every answer is asserted, and a
 * list never shows what the token may not read.
 */
final class ApiReadMatrixTest extends ApiTestCase
{
    /** Endpoints that ask more than the level's own reads. */
    private const READ_EVERYTHING = ['tags', 'fields', 'members own'];

    /** Endpoints of a team: they answer with what remains, never 404 for a case of where. */
    private const TEAM_LEVEL = ['team spaces', 'team tasks', 'time'];

    private TaskBoard $roadmap;

    private TaskBoard $sideBoard;

    private Task $own;

    private Task $others;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $colleague            = $this->user('colleague@example.com');
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->sideBoard      = $this->board('Side', tenantKey: 'tenant-a');
        $this->board('Other project', tenantKey: 'tenant-b');
        $this->own    = $this->task($this->roadmap, 'Own');
        $this->others = new Task(board: $this->roadmap, title: 'Others', creator: $colleague);
        $this->em->persist($this->others);
        $this->em->flush();
    }

    public function testEachLevelWithinReach(): void
    {
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            foreach ($this->endpoints() as $name => $path) {
                $this->get($path);
                $mine = $level === TaskApiLevel::MyTasks;
                match (true) {
                    $mine && str_contains($name, 'other')                 => $this->assertRefused(404, 'ACCESS_001'),
                    $mine && in_array($name, self::READ_EVERYTHING, true) => $this->assertRefused(403, 'TOKEN_001'),
                    default                                               => self::assertResponseIsSuccessful($level->value . ' ' . $name),
                };
            }
            // Lists hold what the level reads, and counts count only that.
            $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/task');
            self::assertSame($mine ? ['Own'] : ['Own', 'Others'], $this->sortedNames(), $level->value);
            $this->get('/api/tasks/v2/team/team-1/task');
            self::assertSame($mine ? ['Own'] : ['Own', 'Others'], $this->sortedNames(), $level->value);
            $this->get('/api/tasks/v2/space/space-tenant-a/list');
            self::assertSame($mine ? 1 : 2, $this->taskCountOf($this->roadmap), $level->value);
        }
    }

    public function testAnotherProjectIsOutOfReachWhateverTheLevel(): void
    {
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level, TaskApiWhere::limitedTo(['tenant-b'], []));
            foreach ($this->endpoints() as $name => $path) {
                $this->get($path);
                if (in_array($name, self::TEAM_LEVEL, true)) {
                    self::assertResponseIsSuccessful($level->value . ' ' . $name);
                    $this->assertShowsNothingOf('tenant-a', $name);
                    continue;
                }
                $this->assertRefused(404, 'ACCESS_001');
            }
        }
    }

    public function testABoardOutsideWhereIsOutOfReachAndItsSpaceShowsOnlyTheNamedBoard(): void
    {
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level, TaskApiWhere::limitedTo([], [$this->sideBoard->getId()]));
            foreach ($this->endpoints() as $name => $path) {
                $this->get($path);
                $onSpace = str_contains($path, '/space/');
                match (true) {
                    in_array($name, self::TEAM_LEVEL, true)                          => self::assertResponseIsSuccessful($name),
                    $onSpace && $level === TaskApiLevel::MyTasks && $name === 'tags' => $this->assertRefused(403, 'TOKEN_001'),
                    $onSpace                                                         => self::assertResponseIsSuccessful($name),
                    default                                                          => $this->assertRefused(404, 'ACCESS_001'),
                };
            }
            $this->get('/api/tasks/v2/space/space-tenant-a/list');
            self::assertSame([$this->sideBoard->getId()], $this->idsOf('lists'));
            $this->get('/api/tasks/v2/team/team-1/task');
            self::assertSame([], $this->listOf('tasks'));
            $this->get('/api/tasks/v2/team/team-1/space');
            self::assertSame(['space-tenant-a'], $this->idsOf('spaces'));
        }
    }

    public function testARemovedMemberReachesNothingOnTheNextRequest(): void
    {
        $this->issueToken();
        $this->get('/api/tasks/v2/task/' . $this->own->getId());
        self::assertResponseIsSuccessful();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->deny();
        });

        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            foreach ($this->endpoints() as $name => $path) {
                $this->get($path);
                if (in_array($name, self::TEAM_LEVEL, true) || in_array($name, ['space', 'folders', 'lists'], true) || ($name === 'tags' && $level !== TaskApiLevel::MyTasks)) {
                    self::assertResponseIsSuccessful($name);
                    $this->assertShowsNothingOf('Roadmap', $name);
                    continue;
                }
                $name === 'tags' ? $this->assertRefused(403, 'TOKEN_001') : $this->assertRefused(404, 'ACCESS_001');
            }
        }
    }

    public function testARevokedOrExpiredTokenIsRefusedEverywhere(): void
    {
        // The host's authenticator answers Invalid for both: the bundle cannot tell them apart, nor can the client.
        foreach ($this->endpoints() as $path) {
            $this->get($path, 'bt_revoked_placeholder');
            $this->assertRefused(401, 'OAUTH_019');
        }
    }

    public function testAPlanWithoutTheModuleForTheProject(): void
    {
        ApiTestHost::$planRefusesTenants = ['tenant-a'];
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            foreach ($this->endpoints() as $name => $path) {
                $this->get($path);
                match (true) {
                    in_array($name, self::TEAM_LEVEL, true)                          => self::assertResponseIsSuccessful($name),
                    $level === TaskApiLevel::MyTasks && str_contains($name, 'other') => $this->assertRefused(404, 'ACCESS_001'),
                    default                                                          => $this->assertRefused(403, 'PLAN_001'),
                };
            }
            $this->get('/api/tasks/v2/team/team-1/space');
            self::assertSame(['space-tenant-b'], $this->idsOf('spaces'));
            $this->get('/api/tasks/v2/team/team-1/task');
            self::assertSame([], $this->listOf('tasks'));
        }
    }

    public function testAPlanWithoutTheModuleAnywhere(): void
    {
        ApiTestHost::$planAllows = false;
        $this->issueToken();
        foreach ($this->endpoints() as $name => $path) {
            $this->get($path);
            $this->assertRefused(403, 'PLAN_001');
        }
    }

    /**
     * @return array<string, string>
     */
    private function endpoints(): array
    {
        $board = $this->roadmap->getId();

        return [
            'team spaces'    => '/api/tasks/v2/team/team-1/space',
            'space'          => '/api/tasks/v2/space/space-tenant-a',
            'folders'        => '/api/tasks/v2/space/space-tenant-a/folder',
            'lists'          => '/api/tasks/v2/space/space-tenant-a/list',
            'tags'           => '/api/tasks/v2/space/space-tenant-a/tag',
            'list'           => '/api/tasks/v2/list/' . $board,
            'list tasks'     => '/api/tasks/v2/list/' . $board . '/task',
            'fields'         => '/api/tasks/v2/list/' . $board . '/field',
            'team tasks'     => '/api/tasks/v2/team/team-1/task',
            'task own'       => '/api/tasks/v2/task/' . $this->own->getId(),
            'task other'     => '/api/tasks/v2/task/' . $this->others->getId(),
            'comments own'   => '/api/tasks/v2/task/' . $this->own->getId() . '/comment',
            'comments other' => '/api/tasks/v2/task/' . $this->others->getId() . '/comment',
            'members own'    => '/api/tasks/v2/task/' . $this->own->getId() . '/member',
            'members other'  => '/api/tasks/v2/task/' . $this->others->getId() . '/member',
            'time'           => '/api/tasks/v2/team/team-1/time_entries',
        ];
    }

    private function assertShowsNothingOf(string $needle, string $name): void
    {
        $body = (string) json_encode($this->json());
        self::assertFalse(str_contains($body, $needle) || str_contains($body, $this->roadmap->getId()), sprintf('%s shows %s', $name, $needle));
    }

    /**
     * @return list<string>
     */
    private function sortedNames(): array
    {
        $names = array_column($this->listOf('tasks'), 'name');
        sort($names);

        return array_reverse($names);
    }

    private function taskCountOf(TaskBoard $board): int
    {
        foreach ($this->listOf('lists') as $list) {
            if ($list['id'] === $board->getId()) {
                return (int) $list['task_count'];
            }
        }

        return -1;
    }
}
