<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use SortDirection;

use function count;
use function hash;
use function json_encode;
use function max;
use function preg_match;
use function strlen;
use function substr;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.25: one-off migration of comments a 1.23 sync wrote for an unmatched author, with the baked-in
 * "**<name>** (<provider>):" first line (the same text in every shipped locale). When the name matches exactly one
 * person of the directory (case-sensitive after trim), the line is removed and the comment points at the person;
 * the sync hashes are updated so the next sync sees no conflict. Zero or several matches leave the comment alone.
 * Idempotent and batched (call again with the returned last id until `done`).
 */
final readonly class TaskSyncCommentLegacyAuthorMigrator
{
    private const PREFIX = '/\A\*\*(?<name>[^*\n]{1,120})\*\* \([^)\n]{1,80}\):\n\n/u';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPersonRepository $people,
    ) {
    }

    /**
     * @return array{migrated: int, skipped: int, lastId: string, done: bool}
     */
    public function migrate(TaskBoardConnection $connection, int $batchSize = 500, string $afterId = ''): array
    {
        $directory = $connection->getDirectory();
        $batchSize = max(1, $batchSize);
        /** @var list<TaskSyncComment> $comments */
        $comments = $this->entityManager->createQueryBuilder()->select('c')->from(TaskSyncComment::class, 'c')
            ->join('c.item', 'i')->where('i.connection = :connection')->andWhere('c.authorPerson IS NULL')->andWhere('c.id > :after')
            ->setParameter('connection', $connection)->setParameter('after', $afterId)
            ->orderBy('c.id', SortDirection::Ascending)->setMaxResults($batchSize)->getQuery()->getResult();
        $migrated = 0;
        $skipped  = 0;
        $last     = $afterId;
        $byName   = $directory instanceof TaskSyncDirectory ? $this->byName($directory) : [];
        foreach ($comments as $syncComment) {
            $last    = $syncComment->getId();
            $comment = $syncComment->getComment();
            $body    = $comment->getBody();
            if (preg_match(self::PREFIX, $body, $match) !== 1) {
                continue;
            }
            $person = $byName[trim($match['name'])] ?? null;
            if (!$person instanceof TaskSyncPerson) {
                ++$skipped;
                continue;
            }
            $newBody = substr($body, strlen($match[0]));
            $comment->edit($newBody, $comment->getMentions());
            $syncComment->setAuthorPerson($person);
            $syncComment->written($syncComment->getBodyHash(), hash('sha256', (string) json_encode($newBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            ++$migrated;
        }
        $this->entityManager->flush();

        return ['migrated' => $migrated, 'skipped' => $skipped, 'lastId' => $last, 'done' => count($comments) < $batchSize];
    }

    /**
     * Names that belong to exactly one person.
     *
     * @return array<string, false|TaskSyncPerson>
     */
    private function byName(TaskSyncDirectory $directory): array
    {
        $names = [];
        foreach ($this->people->forDirectory($directory, [], 100_000) as $person) {
            $name         = trim($person->getName());
            $names[$name] = isset($names[$name]) ? false : $person;
        }

        return $names;
    }
}
