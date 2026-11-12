<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Doctrine\Type\TaskBoardUserRefType;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Since 2.15.0 (`database.user_reference: id`): a user relation becomes a string column of the same name and
 * nullability, indexed; on MySQL / MariaDB it compares in binary.
 */
final class TaskBoardMetadataListenerUserReferenceTest extends TestCase
{
    public function testTheRelationBecomesAnExactIdColumn(): void
    {
        $metadata = $this->metadata(new MySQL80Platform());

        self::assertArrayNotHasKey('user', $metadata->associationMappings);
        $field = $metadata->getFieldMapping('user');
        self::assertSame(TaskBoardUserRefType::NAME, $field->type);
        self::assertSame('user_id', $field->columnName);
        self::assertFalse((bool) $field->nullable, 'a member always has a user');
        self::assertSame(128, $field->length);
        self::assertSame(['collation' => 'utf8mb4_0900_bin'], $field->options, 'OC-M1: binary NO PAD on MySQL 8');
        self::assertContains(['columns' => ['user_id']], $metadata->table['indexes']);
    }

    public function testMariaDbAndMySql57GetTheirBinaryCollation(): void
    {
        self::assertSame(['collation' => 'utf8mb4_nopad_bin'], $this->metadata(new MariaDBPlatform())->getFieldMapping('user')->options);
        self::assertSame(['collation' => 'utf8mb4_bin'], $this->metadata(new MySQLPlatform())->getFieldMapping('user')->options, 'MySQL 5.7 has no binary NO PAD collation: edge spaces are refused instead');
    }

    public function testOtherPlatformsCompareExactlyWithoutACollation(): void
    {
        self::assertNull($this->metadata(new SQLitePlatform())->getFieldMapping('user')->options);
    }

    /**
     * @return ClassMetadata<TaskMember>
     */
    private function metadata(object $platform): ClassMetadata
    {
        $metadata = new ClassMetadata(TaskMember::class, new DefaultNamingStrategy());
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']))->loadMetadataForClass(TaskMember::class, $metadata);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        (new TaskBoardMetadataListener('t', 'b', 'teams', 'members', 'App\\Entity\\User', TaskBoardUsers::ID))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));

        return $metadata;
    }
}
