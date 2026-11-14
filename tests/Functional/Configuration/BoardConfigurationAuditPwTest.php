<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Configuration;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Configuration\BoardColumnsApplier;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Configuration\BoardConfiguration;
use Nowo\TaskBoardBundle\Configuration\BoardConfigurationApplier;
use Nowo\TaskBoardBundle\Configuration\BoardFieldSpec;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function range;

/**
 * Since 2.19.0 (audit PW-M2, the proof of concept turned into a regression): a field definition the field would refuse is
 * refused before the transaction opens, so the entity manager stays open and the caller's board stays managed: the next
 * apply with the same board object works (worker mode, kernel reset off).
 */
final class BoardConfigurationAuditPwTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testInvalidFieldDataLeavesTheManagerAndTheBoardAsTheyWere(): void
    {
        $board   = $this->board('Board', ['Todo', 'Done'], tenantKey: 'acme');
        $columns = BoardColumnsApplier::specsOf($board);
        $tooMany = array_map(static fn (int $i): array => ['name' => 'O' . $i, 'color' => null], range(1, 201));
        foreach ([
            'colour'        => [new BoardFieldSpec('Kind', CustomFieldType::Dropdown, [['name' => 'A', 'color' => 'red']])],
            'duplicate'     => [new BoardFieldSpec('Kind', CustomFieldType::Dropdown, [['name' => 'A', 'color' => null], ['name' => ' A ', 'color' => null]])],
            'empty option'  => [new BoardFieldSpec('Kind', CustomFieldType::Dropdown, [['name' => '  ', 'color' => null]])],
            'too many'      => [new BoardFieldSpec('Kind', CustomFieldType::Dropdown, $tooMany)],
            'two of a name' => [new BoardFieldSpec('Kind', CustomFieldType::Text), new BoardFieldSpec(' kind', CustomFieldType::Number)],
        ] as $what => $fields) {
            try {
                $this->applier()->apply($this->owner, $board, new BoardConfiguration($columns, $fields));
                self::fail($what . ' is refused');
            } catch (TaskBoardRestructureRefusedException $refused) {
                self::assertSame([TaskBoardRestructureRefusedException::INVALID, ['field' => 'fields']], [$refused->reason, $refused->params], $what);
            }
            $em = self::getContainer()->get('doctrine')->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            self::assertTrue($em->isOpen(), $what);
            self::assertTrue($em->contains($board), $what . ': the caller\'s board stays managed');
        }

        $this->applier()->apply($this->owner, $board, new BoardConfiguration([...$columns, new BoardColumnSpec('Extra')], [], [], 'ABC'));

        $this->clear();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertSame('ABC', $fresh?->getNumberPrefix());
        self::assertCount(3, $fresh->getColumns());
    }

    /** PW-L5: the tasks an apply moves or completes get their history rows, as a move by hand does. */
    public function testAnApplyRecordsTheTasksItMovesAndCompletes(): void
    {
        $board = $this->board('Board', ['Todo', 'Doing', 'Done'], tenantKey: 'acme');
        $task  = $this->task($board, 'In progress', 1);

        $this->applier()->apply($this->owner, $board, new BoardConfiguration([new BoardColumnSpec('Todo', ColumnType::Open), new BoardColumnSpec('Done', ColumnType::Closed)]), ['Doing' => 'Done']);

        $this->clear();
        self::assertTrue($this->reload($task)->isCompleted());
        $rows = $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task->getId()]);
        $kinds = array_map(static fn (TaskChangeHistory $row): string => $row->getChangeType()->value, $rows);
        self::assertContains('column', $kinds);
        self::assertContains('completed', $kinds);
        self::assertContains('task_completed', $this->eventNames());
    }

    private function applier(): BoardConfigurationApplier
    {
        $service = self::getContainer()->get(BoardConfigurationApplier::class);
        self::assertInstanceOf(BoardConfigurationApplier::class, $service);

        return $service;
    }
}
