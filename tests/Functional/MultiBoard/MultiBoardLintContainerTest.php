<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Since 2.13.0: `lint:container` on the functional host kernel, with the multi-board list off and on (with and without a
 * provider): every service argument of the bundle matches its type.
 */
#[CoversNothing]
final class MultiBoardLintContainerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function configs(): iterable
    {
        yield 'off' => [[]];
        yield 'on, no provider' => [['multi_board' => ['enabled' => true]]];
        yield 'on, provider' => [['multi_board' => ['enabled' => true, 'scope_provider' => 'app.multi_scope'], 'task_numbers' => ['enabled' => true], 'calendar' => ['enabled' => true]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('configs')]
    public function testTheContainerLints(array $config): void
    {
        $kernel = new FunctionalKernel($config);
        $kernel->boot();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('lint:container'));
        $status = $tester->execute([]);

        self::assertSame(0, $status, $tester->getDisplay());
        self::assertStringContainsString('OK', $tester->getDisplay());
        $kernel->shutdown();
    }
}
