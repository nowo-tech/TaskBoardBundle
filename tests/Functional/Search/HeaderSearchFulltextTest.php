<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\TaskBoardBundle\Search\Header\TaskHeaderSearch;
use Nowo\TaskBoardBundle\Search\Header\TaskSearchExtras;
use Nowo\TaskBoardBundle\Search\Header\TaskTextMatch;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;

/**
 * Since 2.26.0: with `search.fulltext` on MySQL / MariaDB, description words go through the FULLTEXT index
 * (`MATCH … AGAINST` in boolean mode, `+word*`), titles keep their LIKE, and short words still match titles only. Skipped
 * on the other platforms (they keep the LIKE fallback, covered by HeaderSearchReferencesTest).
 */
final class HeaderSearchFulltextTest extends HeaderSearchTestCase
{
    protected function setUp(): void
    {
        static::$taskBoardConfig = ['search' => ['fulltext' => true]];
        parent::setUp();
        if (!$this->em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('FULLTEXT runs on MySQL / MariaDB only.');
        }
    }

    public function testDescriptionWordsUseTheFulltextIndex(): void
    {
        $board = $this->board();
        $this->described($board, 'Invoice run', 'Nightly reconciliation of the ledger');
        $this->described($board, 'Ledger', null);
        $this->described($board, 'Unrelated', 'Something else entirely');

        self::assertSame(['Invoice run', 'Ledger'], self::titles($this->search()->search($this->viewer($this->owner), 'ledger', 10)->hits));
        self::assertSame(['Invoice run'], self::titles($this->search()->search($this->viewer($this->owner), 'reconcil', 10)->hits), 'a word prefix (+word*)');
        self::assertSame(['Invoice run'], self::titles($this->search()->search($this->viewer($this->owner), 'invoice nightly', 10)->hits));
        self::assertSame([], $this->search()->search($this->viewer($this->owner), 'of', 10)->hits, 'two letters: titles only');
    }

    private function search(): TaskHeaderSearch
    {
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);

        return new TaskHeaderSearch($this->scope(), $this->em, $checker, $this->mapper(), 100, new TaskTextMatch(3, true, 3), new TaskSearchExtras($this->em));
    }
}
