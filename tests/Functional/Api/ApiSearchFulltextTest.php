<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

use function rawurlencode;
use function sort;

/**
 * Spec 234 (TaskBoard 2.16.0): `search.fulltext` on MySQL / MariaDB (the CI MySQL job): long words through the FULLTEXT
 * indexes (comments too), short ones and words with punctuation through LIKE, and no boolean-mode operator of the text.
 */
final class ApiSearchFulltextTest extends ApiTestCase
{
    protected function setUp(): void
    {
        self::$hostConfig = ['search' => ['fulltext' => true, 'comments' => true]];
        parent::setUp();
        if (!$this->em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('MySQL / MariaDB only.');
        }
        ApiTestHost::$tenants = ['tenant-a'];
        $this->issueToken();
    }

    public function testWordsMatchThroughTheIndexesAndLike(): void
    {
        $board   = $this->board('Roadmap', tenantKey: 'tenant-a');
        $gateway = $this->task($board, 'C-12 Payment gateway');
        $other   = $this->task($board, 'Invoices');
        $this->em->persist(new TaskComment($other, $this->owner, 'Waiting on the payments provider'));
        $this->task($this->board('Secret', tenantKey: 'tenant-b'), 'Payment elsewhere');
        $this->em->flush();

        $this->get('/api/tasks/v2/team/team-1/task?query=paym');
        $titles = array_column($this->listOf('tasks'), 'name');
        sort($titles);
        self::assertSame(['C-12 Payment gateway', 'Invoices'], $titles);

        $this->get('/api/tasks/v2/team/team-1/task?query=' . rawurlencode('c-12 gateway'));
        self::assertSame([$gateway->getId()], $this->idsOf('tasks'));

        $this->get('/api/tasks/v2/team/team-1/task?query=' . rawurlencode('+payment -gateway (x) ~y "z" @w*'));
        self::assertSame([], $this->listOf('tasks'));
    }
}
