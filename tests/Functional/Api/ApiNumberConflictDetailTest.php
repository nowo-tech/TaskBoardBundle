<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\FoldsKeyColumns;

use function array_keys;

/**
 * Beacon spec 234 (2.16.0): a task that cannot be numbered because its scope's counter is a look-alike scope's answers
 * 409 `TASK_003` **at request level** — the write is all or none, so the item is never listed in a `failures` array that
 * would imply the others landed — and the refusal names the task and the scope the caller's own board asks for, so a
 * client writing many tasks does not have to bisect its batch.
 */
final class ApiNumberConflictDetailTest extends ApiTestCase
{
    use FoldsKeyColumns;

    protected function setUp(): void
    {
        static::$hostConfig = ['task_numbers' => ['enabled' => true]];
        parent::setUp();
        $this->foldKeyColumns($this->em);
        $this->owner          = $this->user('owner@example.com');
        ApiTestHost::$tenants = ['Bob', 'bob'];
        $this->issueToken();
    }

    public function testACreateNamesTheTaskAndTheScopeItAskedFor(): void
    {
        $upper = $this->board('Upper', tenantKey: 'Bob');
        self::assertSame(1, $this->task($upper, 'Numbered')->getNumber());
        $lower = $this->board('Lower', tenantKey: 'bob');

        $this->send('POST', '/api/tasks/v2/list/' . $lower->getId() . '/task', ['name' => 'Would be #1 of Bob']);

        self::assertResponseStatusCodeSame(409);
        $json = $this->json();
        self::assertSame(['err', 'ECODE', 'task_id', 'scope'], array_keys($json));
        self::assertSame('TASK_003', $json['ECODE']);
        // The scope is the one this caller's own board asks for, never the look-alike key stored for the other tenant.
        self::assertSame('bob', $json['scope']);
        // No `failures`: nothing was written, so nothing may read as "the other items went through".
        self::assertArrayNotHasKey('failures', $json);
        // The id the task would have had: it was not created.
        self::assertNotSame('', $json['task_id']);
        $this->em->clear();
        self::assertNull($this->em->find(Task::class, $json['task_id']));
        self::assertSame([], $this->em->getRepository(Task::class)->findBy(['title' => 'Would be #1 of Bob']));
        self::assertTrue($this->em->isOpen());
    }

    public function testAMovedTaskNamesItsStoredId(): void
    {
        // The other numbering path (`onFlush`): a task that changes board draws from its new scope's counter. Its id is
        // the stored one — this is the case the ClickUp cutover hits when it moves boards in bulk.
        $clean  = $this->board('Clean', tenantKey: 'alpha');
        $moving = $this->task($clean, 'Moves');
        self::assertSame(1, $moving->getNumber());
        $this->task($this->board('Upper', tenantKey: 'Bob'), 'Numbered');
        $lower = $this->board('Lower', tenantKey: 'bob');

        $moving->changeBoard($lower, $lower->getColumns()->first() ?: null, 0);
        try {
            $this->em->flush();
            self::fail('bob never draws from Bob\'s counter');
        } catch (TaskNumberScopeConflictException $conflict) {
            self::assertSame($moving->getId(), $conflict->taskId);
            self::assertSame('bob', $conflict->scopeKey);
            self::assertSame(409, $conflict->getStatusCode());
        }
    }
}
