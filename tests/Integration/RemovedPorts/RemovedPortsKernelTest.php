<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\RemovedPorts;

use LogicException;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Since 3.0.0 (REQ-ARCH-001 rule 3, audit lesson W1-1 / XR-WF-M1): through the real TaskBoardBundle::build(), an
 * application service still on a host interface removed in 3.0 fails the container build with a message naming the
 * service, the removed interface and its replacement. Without the check Symfony would drop it silently (an
 * autodiscovered class that cannot be loaded, a decorator or an alias of a missing id) and TaskBoard would fall back to
 * its default: a host restriction of the sync quotas or of who may be assigned would disappear (wider access), a host
 * API authenticator or people inviter would never be asked. Fixtures: tests/Fixtures/RemovedPorts (one directory per
 * case).
 */
final class RemovedPortsKernelTest extends TestCase
{
    private const FIXTURES = 'Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\\';

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}> kernel configuration, the service the message names, the removed interface
     */
    public static function removedPorts(): iterable
    {
        yield '#[AsDecorator] of the sync limit checker id' => [['__autodiscover' => 'DecoratorOfLimitChecker'], self::FIXTURES . 'DecoratorOfLimitChecker\StricterQuotas', 'Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface'];
        yield 'a loadable #[AsDecorator] of the assignee provider id, onInvalid: IGNORE' => [['__autodiscover' => 'IgnoredDecoratorOfAssignee'], self::FIXTURES . 'IgnoredDecoratorOfAssignee\FewerAssignees', 'Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface'];
        yield '#[AsAlias] of the API authenticator id' => [['__autodiscover' => 'AliasOfAuthenticator'], self::FIXTURES . 'AliasOfAuthenticator\HostTokens', 'Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface'];
        yield 'a class implementing only the people inviter' => [['__autodiscover' => 'ImplementsPeopleInviter'], self::FIXTURES . 'ImplementsPeopleInviter\OldInviter', 'Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface'];
        yield 'a class implementing only the board-aware import user resolver' => [['__autodiscover' => 'ImplementsBoardAwareImportUsers'], self::FIXTURES . 'ImplementsBoardAwareImportUsers\OldImportUsers', 'Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface'];
        yield 'sync.limit_checker naming a class implementing only the removed interface' => [
            ['__register' => [self::FIXTURES . 'ConfiguredLimitChecker\OldLimits'], 'sync' => ['enabled' => true, 'credential_resolver' => 'app.sync_credentials', 'limit_checker' => self::FIXTURES . 'ConfiguredLimitChecker\OldLimits']],
            self::FIXTURES . 'ConfiguredLimitChecker\OldLimits',
            'Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('removedPorts')]
    public function testAServiceOnARemovedPortFailsTheBuild(array $config, string $service, string $removed): void
    {
        $kernel = new FunctionalKernel($config);
        try {
            $kernel->boot();
            self::fail('the build must fail');
        } catch (LogicException $failure) {
            self::assertSame(RemovedPortsPass::message($service, $removed), $failure->getMessage());
            self::assertStringContainsString('deprecated in 2.15.0 and removed in 3.0', $failure->getMessage());
            self::assertStringContainsString(RemovedPortsPass::REMOVED[$removed], $failure->getMessage(), 'the replacement is named');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAnUnrelatedMissingInterfaceKeepsItsLazyErrorAndTheBuildSucceeds(): void
    {
        $kernel = new FunctionalKernel(['__autodiscover' => 'UnrelatedMissing']);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertFalse($container->has(self::FIXTURES . 'UnrelatedMissing\QuotaAdapter'), 'Symfony dropped the unused class as before');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testNamedMatchesOnlyTheExactRemovedName(): void
    {
        self::assertSame('Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface', RemovedPortsPass::named('Class "Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface" not found while loading "App\X".'));
        self::assertNull(RemovedPortsPass::named('Class "Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerPolicyInterface" not found while loading "App\X".'));
    }
}
