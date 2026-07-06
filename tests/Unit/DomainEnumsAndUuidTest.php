<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use PHPUnit\Framework\TestCase;

final class DomainEnumsAndUuidTest extends TestCase
{
    public function testUuidGeneratesValidValue(): void
    {
        $uuid = Uuid::generate();
        self::assertSame($uuid->toString(), (string) $uuid);
        self::assertInstanceOf(Uuid::class, Uuid::fromString($uuid->toString()));
    }

    public function testUuidRejectsInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Uuid::fromString('not-a-uuid');
    }

    public function testEnumValuesAreStable(): void
    {
        self::assertSame('merge_request', TaskLinkType::MergeRequest->value);
        self::assertSame('blocks', TaskDependencyType::Blocks->value);
        self::assertSame('assignee', TaskMemberRole::Assignee->value);
        self::assertSame('urgent', TaskPriority::Urgent->value);
    }
}
