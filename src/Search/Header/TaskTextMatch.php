<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use SortDirection;

use function implode;
use function mb_strlen;
use function preg_match;

/**
 * Since 2.26.0 (header search): the text condition of a task search, added to a query on `Task` (alias `t` by default).
 *
 * - **Words**: every word of the query (at most eight, lower case) must be in the title (escaped `LIKE`), or in the
 *   description when the word has at least `search_kit.description_min_length` characters. On MySQL / MariaDB with
 *   `search.fulltext`, a description word of letters and digits at least `search.min_token_length` long uses the
 *   FULLTEXT index (`+word*`); every other one is an escaped `LIKE`. Shorter words never scan descriptions.
 * - **References** ({@see TaskReference}): a query of one token also finds the task whose number it names (`#123`,
 *   `123`, or `DEV-123` with the board's numbering prefix) or whose stored external id it is, by exact match on bound
 *   parameters. Number matches are ordered first.
 *
 * Only conditions are added (the caller's board scope, archive filter and order stay); nothing reaches the database
 * but bound parameters. Stateless (FrankenPHP worker mode).
 */
final readonly class TaskTextMatch
{
    public function __construct(
        private int $descriptionMinLength = 3,
        private bool $fulltext = false,
        private int $minTokenLength = 3,
    ) {
    }

    /**
     * Adds the condition; returns the reference the query names (null: none), so the caller can rank its matches.
     * With no word, the query finds nothing.
     */
    public function apply(QueryBuilder $qb, string $text, string $alias = 't'): ?TaskReference
    {
        $words = SearchText::words($text);
        if ($words === []) {
            $qb->andWhere('1 = 0');

            return null;
        }
        $fulltext = $this->fulltext && $qb->getEntityManager()->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $all      = [];
        foreach ($words as $index => $word) {
            $any = ["LOWER({$alias}.title) LIKE :tbWord{$index} ESCAPE '!'"];
            $qb->setParameter('tbWord' . $index, SearchText::contains($word));
            if (mb_strlen($word) >= $this->descriptionMinLength) {
                // MySQL / MariaDB only: covered by the MySQL and MariaDB jobs (HeaderSearchFulltextTest).
                // @codeCoverageIgnoreStart
                if ($fulltext && mb_strlen($word) >= $this->minTokenLength && preg_match('/^[\p{L}\p{N}]+$/uD', $word) === 1) {
                    $any[] = TaskTextMatchFunction::NAME . "({$alias}.title, {$alias}.description, :tbAgainst{$index}) > 0";
                    $qb->setParameter('tbAgainst' . $index, '+' . $word . '*');
                    $all[] = '(' . implode(' OR ', $any) . ')';
                    continue;
                }
                // @codeCoverageIgnoreEnd
                $any[] = "LOWER({$alias}.description) LIKE :tbWord{$index} ESCAPE '!'";
            }
            $all[] = '(' . implode(' OR ', $any) . ')';
        }
        $condition = implode(' AND ', $all);

        $reference = TaskReference::parse($text);
        $named     = [];
        if ($reference?->number !== null) {
            $qb->setParameter('tbNumber', $reference->number);
            if ($reference->prefix !== null) {
                $qb->setParameter('tbPrefix', $reference->prefix);
            }
            // DQL needs its own alias for each subquery.
            $byNumber = static fn (string $board): string => "{$alias}.number = :tbNumber" . ($reference->prefix === null ? ''
                : ' AND IDENTITY(' . $alias . '.board) IN (SELECT ' . $board . '.id FROM ' . TaskBoard::class . ' ' . $board . ' WHERE ' . $board . '.numberPrefix = :tbPrefix)');
            $named[] = '(' . $byNumber('tbPrefixBoard') . ')';
            // Number matches first, then the caller's order.
            $orders = $qb->getDQLPart('orderBy');
            $qb->addSelect('CASE WHEN ' . $byNumber('tbPrefixRankBoard') . ' THEN 0 ELSE 1 END AS HIDDEN tbReferenceRank')->orderBy('tbReferenceRank', SortDirection::Ascending);
            foreach ($orders as $order) {
                $qb->addOrderBy($order);
            }
        }
        if ($reference?->externalId !== null) {
            $named[] = 'EXISTS (SELECT tbLink.id FROM ' . TaskLink::class . " tbLink WHERE tbLink.task = {$alias} AND tbLink.importSource IS NOT NULL AND tbLink.externalId = :tbExternalId)";
            $qb->setParameter('tbExternalId', $reference->externalId);
        }
        $qb->andWhere($named === [] ? $condition : '(' . $condition . ' OR ' . implode(' OR ', $named) . ')');

        return $reference;
    }
}
