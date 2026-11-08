<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_reverse;
use function json_encode;

/**
 * Audit IA-L8: what prepend() maps for Doctrine follows the merged configuration, where the last file that sets an option
 * wins: `enabled: true` in a base file and `false` in a `when@prod` override is off, and the other way round is on.
 */
final class TaskBoardPrependOverrideTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function options(): iterable
    {
        yield 'sync tables' => ['sync', ['credential_resolver' => 'app.credentials'], 'TaskBoardSync'];
        yield 'task numbers' => ['task_numbers', [], 'TaskBoardNumber'];
        yield 'API table' => ['api', ['hooks' => ['authenticator' => 'app.auth']], 'TaskBoardApi'];
        yield 'API query function' => ['api', ['hooks' => ['authenticator' => 'app.auth']], 'NOWO_TB_JSON_TEXT'];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('options')]
    public function testTheLastFileThatSetsAnOptionDecides(string $option, array $extra, string $mapped): void
    {
        $on  = [$option => ['enabled' => true] + $extra];
        $off = [$option => ['enabled' => false]];

        self::assertStringNotContainsString($mapped, $this->doctrine([$on, $off]), 'on, then off in an override');
        self::assertStringContainsString($mapped, $this->doctrine([$off, $on]), 'off, then on in an override');
        self::assertStringContainsString($mapped, $this->doctrine([$on, [$option => $extra]]), 'an override that does not say keeps it on');
        self::assertStringNotContainsString($mapped, $this->doctrine([[]]), 'off by default');
    }

    public function testTheLastStoreSetDecidesWhetherTheTableIsMapped(): void
    {
        $api = ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth']];

        self::assertStringNotContainsString('TaskBoardApi', $this->doctrine([['api' => $api], ['api' => ['hooks' => ['idempotency_store' => 'app.store']]]]));
        self::assertStringContainsString('TaskBoardApi', $this->doctrine([['api' => ['hooks' => ['idempotency_store' => 'app.store']] + $api], ['api' => ['hooks' => ['idempotency_store' => null]]]]));
    }

    /**
     * @param list<array<string, mixed>> $files the host's config files, in the order they load
     */
    private function doctrine(array $files): string
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $container->setParameter('kernel.debug', false);
        // Each prepend goes in front, so prepending in reverse leaves the files in load order.
        foreach (array_reverse($files) as $file) {
            $container->prependExtensionConfig('nowo_task_board', ['user_class' => stdClass::class] + $file);
        }
        (new TaskBoardExtension())->prepend($container);

        return (string) json_encode($container->getExtensionConfig('doctrine'));
    }
}
