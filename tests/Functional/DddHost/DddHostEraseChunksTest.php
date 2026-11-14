<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use DateTimeImmutable;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\Service\TaskTimeLoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_fill;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Audit OC (Low), since 2.15.1: an id-mode erasure updates the user's rows by primary key, 500 per statement. One IN list
 * of every row went over the 65,535 bound parameters PostgreSQL and MySQL accept (SQLite: 32,766) for a user with that
 * many time entries, and the erasure failed.
 */
final class DddHostEraseChunksTest extends KernelTestCase
{
    private const ROWS = 66_000;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DddHostKernel([]);
    }

    public function testAnErasureOfManyRowsStaysUnderTheParameterLimit(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em        = $container->get('app.task_board_em');
        self::assertInstanceOf(TaskBoardEntityManager::class, $em);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $bruno = DddUsers::all()['u-bruno'];
        $board = $container->get(TaskBoardCreatorInterface::class)->create(new TaskBoardFormData('Many', 'many'), $bruno);
        $task  = $container->get(TaskManagerInterface::class)->create($board, new TaskFormData('Many entries'), $bruno);
        $entry = $container->get(TaskTimeLoggerInterface::class)->log($task, $bruno, 5, new DateTimeImmutable('2026-11-12'));
        $em->flush();
        $db    = $em->getConnection();
        $table = $em->getClassMetadata(TaskTimeEntry::class)->getTableName();
        $row   = $db->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $table), [$entry->getId()]);
        self::assertIsArray($row);
        $em->clear();

        $columns = array_keys($row);
        $db->beginTransaction();
        for ($done = 1; $done < self::ROWS; $done += 400) {
            $values = [];
            $params = [];
            for ($i = $done; $i < $done + 400 && $i < self::ROWS; ++$i) {
                $values[]   = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $copy       = $row;
                $copy['id'] = sprintf('00000000-0000-4000-8000-%012d', $i);
                $params     = array_merge($params, array_values($copy));
            }
            $db->executeStatement(sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', $values)), $params);
        }
        $db->commit();
        self::assertSame(self::ROWS, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE user_id = ?', $table), ['u-bruno']));

        $purger = $container->get(TaskTimeEntrySnapshotPurger::class);
        self::assertInstanceOf(TaskTimeEntrySnapshotPurger::class, $purger);
        self::assertSame(self::ROWS, $purger->detachUser($bruno));
        self::assertSame(0, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE user_id IS NOT NULL', $table)));
    }
}
