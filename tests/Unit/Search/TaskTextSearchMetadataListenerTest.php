<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Search\TaskTextSearchMetadataListener;
use PHPUnit\Framework\TestCase;

final class TaskTextSearchMetadataListenerTest extends TestCase
{
    public function testMapsTheFulltextIndexesOnMysqlOnly(): void
    {
        $task = $this->load(new TaskTextSearchMetadataListener(true), Task::class, ['title', 'description'], new MySQLPlatform());
        self::assertSame(['columns' => ['title', 'description'], 'flags' => ['fulltext']], $task->table['indexes'][TaskTextSearchMetadataListener::TASK_INDEX]);

        $comment = $this->load(new TaskTextSearchMetadataListener(true), TaskComment::class, ['body'], new MySQLPlatform());
        self::assertSame(['columns' => ['body'], 'flags' => ['fulltext']], $comment->table['indexes'][TaskTextSearchMetadataListener::COMMENT_INDEX]);

        self::assertArrayNotHasKey('indexes', $this->load(new TaskTextSearchMetadataListener(false), TaskComment::class, ['body'], new MySQLPlatform())->table);
        self::assertArrayNotHasKey('indexes', $this->load(new TaskTextSearchMetadataListener(true), Task::class, ['title', 'description'], new SQLitePlatform())->table);
        self::assertArrayNotHasKey('indexes', $this->load(new TaskTextSearchMetadataListener(true), TaskBoard::class, ['name'], new MySQLPlatform())->table);
    }

    /**
     * @param class-string $class
     * @param list<string> $fields
     *
     * @return ClassMetadata<object>
     */
    private function load(TaskTextSearchMetadataListener $listener, string $class, array $fields, object $platform): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => 'table']);
        foreach ($fields as $field) {
            $metadata->mapField(['fieldName' => $field, 'type' => 'string']);
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $listener->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));

        return $metadata;
    }
}
