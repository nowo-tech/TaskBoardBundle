<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use PHPUnit\Framework\TestCase;
use stdClass;

final class EntityTimestampsTest extends TestCase
{
    public function testCreatedAtIsSetOnConstruction(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $board  = new TaskBoard('Demo', 'demo', new stdClass());
        $a      = new Task($board, 'A', new stdClass());
        $b      = new Task($board, 'B', new stdClass());
        $team   = new Team('Core');

        foreach ([
            new TaskDependency($a, $b, TaskDependencyType::Blocks),
            new TaskMember($a, new stdClass(), TaskMemberRole::Assignee),
            $team,
            new TeamMember($team, new stdClass(), TeamRole::Member),
        ] as $entity) {
            self::assertGreaterThanOrEqual($before, $entity->getCreatedAt(), $entity::class);
        }
    }

    public function testImportFormDataDefaults(): void
    {
        $data = new TaskImportFormData();

        self::assertSame(TaskImportSource::ClickUpCsv, $data->source);
        self::assertNull($data->file);
        self::assertTrue($data->createMissingColumns);
        self::assertTrue($data->skipExisting);
    }
}
