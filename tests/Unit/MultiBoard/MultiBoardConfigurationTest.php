<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\MultiBoard;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Since 2.13.0: `multi_board.*` (off by default; the path and the page size are validated).
 */
#[CoversClass(Configuration::class)]
final class MultiBoardConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);

        self::assertSame(['enabled' => false, 'scope_provider' => null, 'path' => '/tools/task-board/tasks', 'per_page' => 25, 'max_boards' => 500, 'scan_max_rows' => 10000, 'export' => ['max_rows' => 5000]], $config['multi_board']);
        self::assertSame('@NowoTaskBoardBundle/manage/tasks.html.twig', $config['templates']['tasks']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'relative path' => [['path' => 'tasks']];
        yield 'format variable' => [['path' => '/tasks/{format}']];
        yield 'page size' => [['per_page' => 30]];
        yield 'no board' => [['max_boards' => 0]];
        yield 'huge export' => [['export' => ['max_rows' => 1000000]]];
    }

    /**
     * @param array<string, mixed> $multiBoard
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $multiBoard): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'multi_board' => $multiBoard]]);
    }
}
