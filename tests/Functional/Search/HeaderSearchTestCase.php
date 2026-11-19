<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Search\Header\BoardHeaderSearch;
use Nowo\TaskBoardBundle\Search\Header\SearchBoardScope;
use Nowo\TaskBoardBundle\Search\Header\SearchHit;
use Nowo\TaskBoardBundle\Search\Header\SearchHitMapper;
use Nowo\TaskBoardBundle\Search\Header\SearchTenants;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;
use Nowo\TaskBoardBundle\Search\Header\TaskHeaderSearch;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function count;
use function is_array;

/**
 * Since 2.25.0 (header search): fixtures of the header search tests — the services built on the kernel's own
 * collaborators, a viewer, team boards, tenants and a query counter. Runs on SQLite and, in CI, on MySQL 8.4,
 * MariaDB 11.4 and PostgreSQL 16 (TASK_BOARD_TEST_DATABASE_URL).
 */
abstract class HeaderSearchTestCase extends FunctionalTestCase
{
    /** The kernel configuration with tenancy: tenants open to their members only, under /t/{tenantSlug}. */
    protected const TENANCY = ['tenancy' => ['enabled' => true, 'resolver' => 'app.member_tenant_resolver', 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]]];

    /** @var list<array{string, callable}> */
    private array $listeners = [];

    protected function tearDown(): void
    {
        $dispatcher = $this->dispatcher();
        foreach ($this->listeners as [$event, $listener]) {
            $dispatcher->removeListener($event, $listener);
        }
        $this->listeners               = [];
        MemberTenantResolver::$members = [];
        parent::tearDown();
    }

    /**
     * Boots the kernel again with `$config` (tenancy, ...) and a fresh schema.
     *
     * @param array<string, mixed> $config
     */
    protected function boot(array $config): void
    {
        self::ensureKernelShutdown();
        static::$taskBoardConfig = $config;
        $this->setUp();
    }

    protected function scope(int $maxBoards = 200, int $maxScannedBoards = 1000): SearchBoardScope
    {
        $container = self::getContainer();
        $checker   = $container->get(TaskBoardResourceAccessChecker::class);
        $tenants   = $container->get(TaskBoardTenantContext::class);
        $users     = $container->get(TaskBoardUsers::class);
        $requests  = $container->get('request_stack');
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        self::assertInstanceOf(TaskBoardTenantContext::class, $tenants);
        self::assertInstanceOf(TaskBoardUsers::class, $users);
        self::assertInstanceOf(RequestStack::class, $requests);

        return new SearchBoardScope($this->em, $checker, $this->dispatcher(), $tenants, $this->logger(), $maxBoards, new SearchTenants($tenants, $requests), $users, $maxScannedBoards);
    }

    protected function mapper(): SearchHitMapper
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $routes = self::getContainer()->getParameter('nowo_task_board.routes');
        self::assertIsArray($routes);

        /* @phpstan-ignore argument.type */
        return new SearchHitMapper($router, $routes);
    }

    protected function tasks(int $scanRows = 100, int $maxBoards = 200, int $maxScannedBoards = 1000): TaskHeaderSearch
    {
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);

        return new TaskHeaderSearch($this->scope($maxBoards, $maxScannedBoards), $this->em, $checker, $this->mapper(), $scanRows);
    }

    protected function boards(int $maxBoards = 200): BoardHeaderSearch
    {
        return new BoardHeaderSearch($this->scope($maxBoards), $this->mapper());
    }

    protected function viewer(AppUser $user, ?string $scope = null): SearchViewer
    {
        return new SearchViewer($user, false, $scope);
    }

    protected function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /** Adds a listener for this test (removed in tearDown()). */
    protected function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->dispatcher()->addListener($event, $listener, $priority);
        $this->listeners[] = [$event, $listener];
    }

    /**
     * A board of `$creator` whose team has `$members`.
     *
     * @param list<AppUser> $members
     */
    protected function teamBoard(string $name, AppUser $creator, array $members, ?string $tenantKey = null): TaskBoard
    {
        $team = new Team($name . ' team');
        $this->em->persist($team);
        foreach ($members as $member) {
            $this->em->persist(new TeamMember($team, $member, TeamRole::Member));
        }
        $board = $this->board($name, creator: $creator, tenantKey: $tenantKey);
        $board->setTeam($team);
        $this->em->flush();

        return $board;
    }

    protected function described(TaskBoard $board, string $title, ?string $description): Task
    {
        $task = $this->task($board, $title);
        $task->setDescription($description);
        $this->em->flush();

        return $task;
    }

    /**
     * @param list<SearchHit> $hits
     *
     * @return list<string>
     */
    protected static function titles(array $hits): array
    {
        $titles = array_map(static fn (SearchHit $hit): string => $hit->title, $hits);
        sort($titles);

        return $titles;
    }

    /** Whether the bundle logged `$message` as a warning. */
    protected function warned(string $message): bool
    {
        foreach ($this->logger()->records as $record) {
            if ($record['level'] === 'warning' && $record['message'] === $message) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<SearchHit> $hits
     *
     * @return list<string>
     */
    protected static function subtitles(array $hits): array
    {
        $subtitles = array_map(static fn (SearchHit $hit): string => $hit->subtitle, $hits);
        sort($subtitles);

        return $subtitles;
    }

    /** The queries `$run` makes, every connection included. */
    protected function queries(callable $run): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $this->em->clear();
        $holder->reset();
        $run();
        $count = 0;
        foreach ($holder->getData() as $queries) {
            $count += is_array($queries) ? count($queries) : 0;
        }

        return $count;
    }
}
