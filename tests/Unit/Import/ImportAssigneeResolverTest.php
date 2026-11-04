<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Import;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpTaskMapper;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Import\ImportAssigneeResolver;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;
use function str_contains;

/**
 * Since 1.23 (audit EG-L6): a row resolves at most MAX_ASSIGNEES_PER_ROW assignees, each e-mail once, and the
 * unresolved ones are warned about in a bounded list plus a count.
 */
final class ImportAssigneeResolverTest extends TestCase
{
    public function testAssigneesAreCappedDedupedAndWarnedInAggregate(): void
    {
        $lookups = 0;
        $users   = new class($lookups) implements TaskImportUserResolverInterface {
            public function __construct(private int &$lookups)
            {
            }

            public function resolveByEmail(string $email): ?object
            {
                ++$this->lookups;

                return $email === 'u1@example.test' ? new TestUser('1', 'u1') : null;
            }
        };
        $resolver = new ImportAssigneeResolver($users, null, new TaskBoard('B', 'b', new TestUser('9', 'owner')));
        $emails   = array_map(static fn (int $i): string => 'u' . $i . '@example.test', range(1, 200));

        $resolved = $resolver->resolve(new ImportedTaskDto(title: 'Wide', assigneeEmail: 'u1@example.test', assigneeEmails: [...$emails, ...$emails]));

        self::assertCount(1, $resolved);
        self::assertSame(ImportAssigneeResolver::MAX_ASSIGNEES_PER_ROW, $lookups, 'one lookup per kept e-mail, each once');
        $warnings = $resolver->warnings();
        self::assertCount(12, $warnings, '10 listed, one count of the rest, one row over the cap');
        self::assertTrue(str_contains($warnings[10], '39 more assignee e-mail(s)'));
        self::assertTrue(str_contains($warnings[11], '1 row(s) name more than 50 assignees'));
    }

    public function testUnresolvedNamesAreListedTenAtMost(): void
    {
        $resolver = new ImportAssigneeResolver(new class implements TaskImportUserResolverInterface {
            public function resolveByEmail(string $email): ?object
            {
                return null;
            }
        }, null, new TaskBoard('B', 'b', new TestUser('9', 'owner')));
        foreach (range(1, 15) as $i) {
            $resolver->resolve(new ImportedTaskDto(title: 'T' . $i, assigneeNames: ['Name ' . $i]));
        }

        $warnings = $resolver->warnings();
        self::assertCount(11, $warnings);
        self::assertTrue(str_contains($warnings[0], '5 more assignee name(s)'));
    }

    public function testTheClickUpMapperKeepsOneMoreThanTheCap(): void
    {
        $assignees = array_map(static fn (int $i): array => ['email' => 'u' . $i . '@example.test'], range(1, 300));
        $row       = (new ClickUpTaskMapper())->map(['id' => 't1', 'name' => 'Wide', 'assignees' => [...$assignees, ...$assignees]]);

        self::assertInstanceOf(ImportedTaskDto::class, $row);
        self::assertSame(ImportAssigneeResolver::MAX_ASSIGNEES_PER_ROW + 1, count($row->assigneeEmails));
    }
}
