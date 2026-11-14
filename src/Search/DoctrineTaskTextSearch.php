<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\ValueObject\TaskTextQuery;

use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function implode;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function preg_split;
use function strtr;
use function trim;

/**
 * Since 2.16.0 (spec 234, FR-026): the default text search. Every word of the text (split on white space) must be in the
 * task's title or description (or, with `search.comments`, in one of its comments that is not deleted).
 *
 * With `search.fulltext` on MySQL / MariaDB, a word of letters and digits at least `search.min_token_length` long (the
 * server's `innodb_ft_min_token_size`) is matched through the FULLTEXT indexes (`MATCH … AGAINST` in boolean mode, as
 * `+word*`: the word or a longer one). Every other word (shorter, or with punctuation such as `C-12`), and every word on
 * other platforms, is a case-insensitive `LIKE %word%` with `%`, `_` and `!` escaped. So no boolean-mode operator
 * (`+ - < > ( ) ~ * " @`) of the user's text ever reaches the server.
 *
 * Only the boards of the query are read, in SQL, before the limit. Stateless (worker mode).
 */
final readonly class DoctrineTaskTextSearch implements TaskTextSearchInterface
{
    /** At most this many words of a text are used. */
    public const MAX_WORDS = 8;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private bool $fulltext = false,
        private bool $comments = false,
        private int $minTokenLength = 3,
    ) {
    }

    public function matchingTaskIds(TaskTextQuery $query): array
    {
        $words = self::words($query->text);
        if ($words === [] || $query->boardIds === [] || $query->limit < 1) {
            return [];
        }
        $connection = $this->entityManager->getConnection();
        $fulltext   = $this->fulltext && $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $tasks      = $this->entityManager->getClassMetadata(Task::class);
        $comments   = $this->entityManager->getClassMetadata(TaskComment::class);
        $id         = $tasks->getColumnName('id');
        $title      = 't.' . $tasks->getColumnName('title');
        $text       = 't.' . $tasks->getColumnName('description');
        $board      = 't.' . $tasks->getSingleAssociationJoinColumnName('board');

        $qb = $connection->createQueryBuilder()
            ->select('t.' . $id)
            ->from($tasks->getTableName(), 't')
            ->where($board . ' IN (:boards)')
            ->setParameter('boards', $query->boardIds, ArrayParameterType::STRING)
            ->orderBy('t.' . $id)
            ->setMaxResults($query->limit + 1);

        $long  = [];
        $short = [];
        foreach ($words as $word) {
            if ($fulltext && mb_strlen($word) >= $this->minTokenLength && preg_match('/^[\p{L}\p{N}]+$/uD', $word) === 1) {
                $long[] = '+' . $word . '*'; // @codeCoverageIgnore
            } else {
                $short[] = $word;
            }
        }
        $commentExists = (static fn (string $condition): string => 'EXISTS (SELECT 1 FROM ' . $comments->getTableName() . ' c WHERE c.' . $comments->getSingleAssociationJoinColumnName('task')
            . ' = t.' . $comments->getSingleIdentifierColumnName() . ' AND c.' . $comments->getColumnName('deletedAt') . ' IS NULL AND ' . $condition . ')');
        // MySQL / MariaDB only: covered by the MySQL job (ApiSearchFulltextTest).
        // @codeCoverageIgnoreStart
        if ($long !== []) {
            $against = implode(' ', $long);
            $match   = 'MATCH (' . $title . ', ' . $text . ') AGAINST (:against IN BOOLEAN MODE)';
            if ($this->comments) {
                $match = '(' . $match . ' OR ' . $commentExists('MATCH (c.' . $comments->getColumnName('body') . ') AGAINST (:against IN BOOLEAN MODE)') . ')';
            }
            $qb->andWhere($match)->setParameter('against', $against);
        }
        // @codeCoverageIgnoreEnd
        foreach ($short as $index => $word) {
            $this->like($qb, $index, $word, [$title, $text], $this->comments ? $commentExists('LOWER(c.' . $comments->getColumnName('body') . ') LIKE :word' . $index . " ESCAPE '!'") : null);
        }

        /** @var list<string> $ids */
        $ids = array_map(strval(...), $qb->executeQuery()->fetchFirstColumn());

        return $ids;
    }

    /**
     * The words of a text: split on white space, lower case, de-duplicated, at most {@see self::MAX_WORDS}.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $word = mb_strtolower(trim($word));
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /**
     * @param list<string> $columns
     */
    private function like(QueryBuilder $qb, int $index, string $word, array $columns, ?string $orComment): void
    {
        $any = array_map(static fn (string $column): string => 'LOWER(' . $column . ') LIKE :word' . $index . " ESCAPE '!'", $columns);
        if ($orComment !== null) {
            $any[] = $orComment;
        }
        $qb->andWhere('(' . implode(' OR ', $any) . ')')
            ->setParameter('word' . $index, '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
    }
}
