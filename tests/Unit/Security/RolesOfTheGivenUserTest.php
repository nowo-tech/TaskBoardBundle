<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Security;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\RoleHolderUser;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\UserRoleCheck;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.29.0 (audit B8): the role rules (`security.access_roles` / `create_roles` / `list_roles` and the
 * `security.manage_roles` bypass) judge the user they are asked about. Up to 2.28 the signed-in user's token decided for
 * every user: an administrator's session granted access to anyone checked, and a check without a token refused everyone.
 */
final class RolesOfTheGivenUserTest extends TestCase
{
    private TokenStorage $tokens;

    private AuthorizationChecker $authorization;

    protected function setUp(): void
    {
        $this->tokens        = new TokenStorage();
        $this->authorization = new AuthorizationChecker($this->tokens, new AccessDecisionManager([new RoleHierarchyVoter(new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]))]));
    }

    public function testASignedInAdministratorDoesNotGrantAccessToAnotherUser(): void
    {
        $admin  = new InMemoryUser('admin', null, ['ROLE_ADMIN']);
        $member = new InMemoryUser('member', null, ['ROLE_MEMBER']);
        $this->signIn($admin);
        $checker = $this->checker(['ROLE_USER'], ['ROLE_ADMIN'], ['ROLE_USER']);

        self::assertTrue($checker->canAccess($admin));
        self::assertTrue($checker->canCreateBoard($admin, 'acme'));
        self::assertFalse($checker->canAccess($member), 'The administrator\'s session must not grant the member access.');
        self::assertFalse($checker->canCreateBoard($member, 'acme'));
        self::assertFalse($checker->canListBoards($member));
    }

    public function testASignedInMemberDoesNotHideTheRolesOfAnAdministrator(): void
    {
        $admin  = new InMemoryUser('admin', null, ['ROLE_ADMIN']);
        $member = new InMemoryUser('member', null, ['ROLE_MEMBER']);
        $this->signIn($member);
        $checker = $this->checker(['ROLE_USER'], ['ROLE_ADMIN'], ['ROLE_USER']);

        self::assertFalse($checker->canAccess($member));
        // The role hierarchy applies to the other user too (ROLE_ADMIN includes ROLE_USER).
        self::assertTrue($checker->canAccess($admin));
        self::assertTrue($checker->canCreateBoard($admin));
        self::assertTrue($checker->canListBoards($admin, 'acme'));
    }

    public function testWithoutATokenTheUsersOwnRolesDecide(): void
    {
        // A Messenger handler or a console command: nobody is signed in.
        $checker = $this->checker(['ROLE_USER'], ['ROLE_ADMIN'], ['ROLE_USER']);

        self::assertTrue($checker->canAccess(new InMemoryUser('worker-user', null, ['ROLE_USER'])));
        self::assertFalse($checker->canAccess(new InMemoryUser('nobody', null, [])));
    }

    public function testTheSignedInUserIsStillJudgedByTheToken(): void
    {
        // The token's roles (here: added by the firewall, not reported by the user object) decide for the signed-in user.
        $user = new IdUser('u-1', ['ROLE_MEMBER']);
        $this->tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $checker = $this->checker(['ROLE_USER'], ['ROLE_USER'], ['ROLE_USER']);

        self::assertTrue($checker->canAccess($user));
        // Another object of the same user (exactly the same id): still the signed-in user.
        self::assertTrue($checker->canAccess(new IdUser('u-1', [])));
        // The same id on another kind of object (a team, a service account) is somebody else.
        self::assertFalse($checker->canAccess(new RolesOnlyWithId('u-1', ['ROLE_MEMBER'])));
        // A look-alike id is somebody else.
        self::assertFalse($checker->canAccess(new IdUser('U-1', ['ROLE_MEMBER'])));
    }

    public function testAHostUserThatIsNotASymfonyUserIsJudgedOnItsReportedRoles(): void
    {
        $this->signIn(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
        $checker = $this->checker(['ROLE_USER'], ['ROLE_ADMIN'], ['ROLE_USER']);

        self::assertTrue($checker->canAccess(new RolesOnly(['ROLE_ADMIN', 7, ''])));
        self::assertFalse($checker->canAccess(new RolesOnly(['ROLE_MEMBER'])));
        self::assertFalse($checker->canAccess(new RolesOnly('ROLE_ADMIN')));
        // No roles at all: holds none (before 2.29.0 the administrator's session decided).
        self::assertFalse($checker->canAccess(new stdClass()));
    }

    public function testAHostAuthorizationCheckerWithoutIsGrantedForUserComparesTheRoleNames(): void
    {
        $plain = new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                return true;
            }
        };
        $this->signIn(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
        $check = new UserRoleCheck($plain, $this->tokens);

        self::assertTrue($check->hasAnyRole(new InMemoryUser('m', null, ['ROLE_USER']), ['ROLE_USER']));
        self::assertFalse($check->hasAnyRole(new InMemoryUser('m', null, ['ROLE_USER']), ['ROLE_ADMIN']));
        self::assertFalse($check->hasAnyRole(new InMemoryUser('m', null, ['ROLE_USER']), []));
        self::assertFalse((new UserRoleCheck(null, $this->tokens))->hasAnyRole(new InMemoryUser('m', null, ['ROLE_USER']), ['ROLE_USER']));
    }

    public function testAHandBuiltCheckerWithoutTokenStorageKeepsTheTokenRule(): void
    {
        $this->signIn(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
        $checker = new ConfigurableTaskBoardAccessChecker($this->authorization, ['ROLE_ADMIN'], [], []);

        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testManageRolesAreTheRolesOfTheGivenUser(): void
    {
        $admin  = new IdUser('admin', ['ROLE_ADMIN']);
        $member = new IdUser('member', ['ROLE_USER']);
        $board  = new TaskBoard('Roadmap', 'roadmap', new IdUser('creator', []));
        $this->tokens->setToken(new UsernamePasswordToken($admin, 'main', ['ROLE_ADMIN']));
        $teams = $this->createStub(TeamMemberRepositoryInterface::class);
        $teams->method('findByUserId')->willReturn([]);
        $checker = new TaskBoardResourceAccessChecker($teams, new NullTaskBoardTeamMembershipResolver(), $this->authorization, tokenStorage: $this->tokens);

        self::assertTrue($checker->canViewBoard($admin, $board));
        self::assertFalse($checker->canViewBoard($member, $board), 'The administrator\'s session must not open the board to the member.');
        self::assertTrue($checker->canManageTenantTemplates($admin, 'acme'));
        self::assertFalse($checker->canManageTenantTemplates($member, 'acme'));

        // Signed in as the member: the administrator still holds the bypass.
        $this->tokens->setToken(new UsernamePasswordToken($member, 'main', ['ROLE_USER']));
        self::assertTrue($checker->canViewBoard($admin, $board));
        self::assertFalse($checker->canViewBoard($member, $board));
    }

    public function testTheStandInUserCarriesOnlyIdAndRoles(): void
    {
        $user = new RoleHolderUser('u-7', ['ROLE_X']);
        $user->eraseCredentials();

        self::assertSame(['u-7', 'u-7', ['ROLE_X']], [$user->getId(), $user->getUserIdentifier(), $user->getRoles()]);
    }

    /**
     * @param list<string> $access
     * @param list<string> $create
     * @param list<string> $list
     */
    private function checker(array $access, array $create, array $list): ConfigurableTaskBoardAccessChecker
    {
        return new ConfigurableTaskBoardAccessChecker($this->authorization, $access, $create, $list, $this->tokens);
    }

    private function signIn(InMemoryUser $user): void
    {
        $this->tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}

/** A host user with an id (the bundle tells users apart by `getId()`). */
final readonly class IdUser implements UserInterface
{
    /** @param list<string> $roles */
    public function __construct(private string $id, private array $roles)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return 'user-' . $this->id;
    }

    public function eraseCredentials(): void
    {
    }
}

/** A host user object that is not a Symfony user but reports roles (a DDD host's own user). */
final readonly class RolesOnly
{
    public function __construct(private mixed $roles)
    {
    }

    public function getRoles(): mixed
    {
        return $this->roles;
    }
}

/** Another kind of host object whose id overlaps a user's. */
final readonly class RolesOnlyWithId
{
    /** @param list<string> $roles */
    public function __construct(private string $id, private array $roles)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }
}
