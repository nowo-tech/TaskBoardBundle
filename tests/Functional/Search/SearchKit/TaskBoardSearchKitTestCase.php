<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search\SearchKit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function class_alias;
use function class_exists;

if (!class_exists(EntitySearchProviderTestCase::class)) {
    class_alias(SearchKitAbsentTestCase::class, EntitySearchProviderTestCase::class);
}

/**
 * Since 2.25.0: SearchKit's provider contract (EntitySearchProviderTestCase) against the bundle's SearchKit groups, on
 * the functional kernel's database (SQLite, or TASK_BOARD_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL in CI).
 *
 * A SearchKit tenant is a TaskBoard tenant: the viewer acts on a tenant page (`/t/{tenantSlug}`), signed in, and the
 * member resolver opens both tenants to both viewers, so only the tenant of the page separates their rows. Board scoping is
 * off: the contract is about the tenant, the scope, the escaping, the limit, the budget and the host event; visibility per
 * board and TASK_ACCESS_CHECK are covered by HeaderSearchTest and TaskBoardSearchDefinitionTest.
 */
abstract class TaskBoardSearchKitTestCase extends EntitySearchProviderTestCase
{
    protected const TENANT_A = 'skt-a';

    protected const TENANT_B = 'skt-b';

    protected FunctionalKernel $kernel;

    protected EntityManagerInterface $em;

    /** @var array<string, AppUser> */
    protected array $users = [];

    /** @var array<string, TaskBoard> */
    protected array $tenantBoards = [];

    protected int $seeded = 0;

    /**
     * @param array<string, mixed> $config
     */
    protected function bootKernel(array $config = []): void
    {
        $this->kernel = new FunctionalKernel($config + [
            'tenancy'  => ['enabled' => true, 'resolver' => 'app.member_tenant_resolver', 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
            'security' => ['resource_scoping' => false],
        ]);
        $this->kernel->boot();
        $em = $this->container()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->users        = [];
        $this->tenantBoards = [];
        foreach ([self::VIEWER_A, self::VIEWER_B] as $identifier) {
            $this->users[$identifier] = new AppUser($identifier);
            $this->em->persist($this->users[$identifier]);
        }
        $this->em->flush();
        MemberTenantResolver::$members = [self::TENANT_A => [self::VIEWER_A, self::VIEWER_B], self::TENANT_B => [self::VIEWER_A, self::VIEWER_B]];
    }

    protected function setUp(): void
    {
        if (!class_exists(QueryCounter::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
        }
        $this->bootKernel();
    }

    protected function tearDown(): void
    {
        MemberTenantResolver::$members = [];
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        parent::tearDown();
    }

    protected function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    protected function queryCounter(): QueryCounter
    {
        $counter = $this->container()->get('app.search_kit_query_counter');
        self::assertInstanceOf(QueryCounter::class, $counter);

        return $counter;
    }

    protected function tenants(): array
    {
        return [self::TENANT_A, self::TENANT_B];
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier, bool $feature = true): void
    {
        $user   = $this->users[$userIdentifier] ?? null;
        $tokens = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken($user !== null ? new UsernamePasswordToken($user, 'main', $feature ? $user->getRoles() : []) : null);
        $stack = $this->container()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        while ($stack->pop() !== null) {
        }
        // No tenant: a tenant page whose slug resolves for nobody.
        $stack->push(new Request(attributes: ['tenantSlug' => $tenant !== null ? (string) $tenant : 'no-such-tenant']));
    }

    protected function disableFeature(): void
    {
        $tokens = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $user = $tokens->getToken()?->getUser();
        self::assertInstanceOf(AppUser::class, $user);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', []));
    }

    /** The board of a tenant (one per tenant and test). */
    protected function tenantBoard(int|string $tenant): TaskBoard
    {
        $key = (string) $tenant;
        if (!isset($this->tenantBoards[$key])) {
            $board = new TaskBoard('Board of ' . $key, 'board-' . $key, $this->users[self::VIEWER_A]);
            $board->setTenantKey($key);
            $this->em->persist($board);
            $this->em->flush();
            $this->tenantBoards[$key] = $board;
        }

        return $this->tenantBoards[$key];
    }

    protected function definitionService(string $id): EntitySearchDefinition
    {
        $definition = $this->container()->get($id);
        self::assertInstanceOf(EntitySearchDefinition::class, $definition);

        return $definition;
    }
}
