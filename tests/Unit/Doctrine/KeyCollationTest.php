<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Nowo\TaskBoardBundle\Doctrine\KeyCollation;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function dirname;

/**
 * Since 2.15.1 (REQ-SEC-006 items 3 and 8, audit F5): the key columns get a binary NO PAD collation on MySQL / MariaDB,
 * and a `server_version` without its patch number that leaves them on the PAD SPACE `utf8mb4_bin` is logged.
 */
final class KeyCollationTest extends TestCase
{
    public function testTheCollationPerPlatformAndVersion(): void
    {
        self::assertSame('utf8mb4_nopad_bin', KeyCollation::for(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', KeyCollation::for(new MySQL80Platform()));
        self::assertSame('utf8mb4_0900_bin', KeyCollation::for(new MySQL80Platform(), '8.4.3'));
        self::assertSame('utf8mb4_0900_bin', KeyCollation::for(new MySQL80Platform(), '8.0.17'));
        self::assertSame('utf8mb4_bin', KeyCollation::for(new MySQL80Platform(), '8.0.16'));
        self::assertSame('utf8mb4_bin', KeyCollation::for(new MySQL80Platform(), '8.0'), 'no patch number: read as 8.0.0');
        self::assertSame('utf8mb4_bin', KeyCollation::for(new MySQLPlatform()), 'MySQL 5.7');
        self::assertNull(KeyCollation::for(new SQLitePlatform()));
        self::assertNull(KeyCollation::for(new PostgreSQLPlatform()));
    }

    public function testAVersionWithoutItsPatchNumberIsLogged(): void
    {
        foreach (['8.0' => new MySQL80Platform(), '8' => new MySQLPlatform()] as $version => $platform) {
            $logger = $this->logger();
            KeyCollation::warnAboutPartialVersion($this->connection($platform, (string) $version), $logger);
            self::assertCount(1, $logger->lines, (string) $version);
            self::assertStringContainsString('has no patch number', $logger->lines[0]);
        }
        foreach ([['8.4.3', new MySQL80Platform()], ['8.4', new MySQL80Platform()], ['11.4', new MariaDBPlatform()], [null, new MySQL80Platform()], ['16', new PostgreSQLPlatform()]] as [$version, $platform]) {
            $logger = $this->logger();
            KeyCollation::warnAboutPartialVersion($this->connection($platform, $version), $logger);
            self::assertSame([], $logger->lines, (string) $version);
        }
        KeyCollation::warnAboutPartialVersion($this->connection(new MySQL80Platform(), '8.0'), null);
        $logger = $this->logger();
        KeyCollation::warnAboutPartialVersion($this->connection(new MySQL80Platform(), null, ['serverVersion' => '8.0']), $logger);
        self::assertCount(1, $logger->lines, 'a primary / replica connection');
    }

    public function testTheListenerCollatesTheKeyColumns(): void
    {
        $board = $this->metadata(TaskBoard::class, new MySQL80Platform(), '8.4.3');
        self::assertSame('utf8mb4_0900_bin', $board->getFieldMapping('tenantKey')->options['collation'] ?? null);
        self::assertSame('utf8mb4_0900_bin', $board->getFieldMapping('slug')->options['collation'] ?? null);
        self::assertSame('utf8mb4_nopad_bin', $this->metadata(TaskLink::class, new MariaDBPlatform())->getFieldMapping('externalId')->options['collation'] ?? null);
        self::assertSame('utf8mb4_nopad_bin', $this->metadata(TaskLink::class, new MariaDBPlatform())->getFieldMapping('importSource')->options['collation'] ?? null);
        self::assertSame('utf8mb4_0900_bin', $this->metadata(TaskNumberCounter::class, new MySQL80Platform())->getFieldMapping('scopeKey')->options['collation'] ?? null);
        // Since 2.16.0 (spec 234, FR-061): a task template's tenant is a key column too.
        self::assertSame('utf8mb4_0900_bin', $this->metadata(TaskTemplate::class, new MySQL80Platform(), '8.4.3')->getFieldMapping('tenantKey')->options['collation'] ?? null);
        self::assertNull($this->metadata(TaskTemplate::class, new SQLitePlatform())->getFieldMapping('tenantKey')->options);
        self::assertNull($this->metadata(TaskBoard::class, new SQLitePlatform())->getFieldMapping('tenantKey')->options);
        self::assertNull($this->metadata(TaskBoard::class, new MySQL80Platform())->getFieldMapping('name')->options, 'not a key');

        $logger = $this->logger();
        self::assertSame('utf8mb4_bin', $this->metadata(TaskBoard::class, new MySQL80Platform(), '8.0', $logger)->getFieldMapping('tenantKey')->options['collation'] ?? null);
        self::assertCount(1, $logger->lines);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, AbstractPlatform $platform, ?string $version = null, ?AbstractLogger $logger = null): ClassMetadata
    {
        $metadata = new ClassMetadata($class, new DefaultNamingStrategy());
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity', dirname(__DIR__, 3) . '/src/Number/Entity']))->loadMetadataForClass($class, $metadata);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection($platform, $version));

        (new TaskBoardMetadataListener('t', 'b', 'teams', 'members', 'App\\Entity\\User', logger: $logger))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));

        return $metadata;
    }

    /**
     * @param array<string, mixed>|null $primary
     */
    private function connection(AbstractPlatform $platform, ?string $version, ?array $primary = null): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($primary !== null ? ['primary' => $primary] : ($version !== null ? ['serverVersion' => $version] : []));

        return $connection;
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }
}
