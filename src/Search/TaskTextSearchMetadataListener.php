<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;

/**
 * Since 2.16.0 (spec 234, FR-026; registered only with `search.fulltext: true`): maps the FULLTEXT indexes of the text
 * search on MySQL / MariaDB, so `doctrine:migrations:diff` creates them and never drops them: `task_board_tasks_text_idx`
 * on the tasks' title and description, and with `search.comments` `task_board_comments_text_idx` on the comments' body.
 * Other platforms get no index (the search reads them with `LIKE`). Stateless.
 *
 * @internal
 */
final readonly class TaskTextSearchMetadataListener
{
    public const TASK_INDEX = 'task_board_tasks_text_idx';

    public const COMMENT_INDEX = 'task_board_comments_text_idx';

    public function __construct(private bool $comments = false)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $class    = $metadata->getName();
        if ($class !== Task::class && ($class !== TaskComment::class || !$this->comments)) {
            return;
        }
        if (!$args->getObjectManager()->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $table = $metadata->table;
        $table['indexes'] ??= [];
        if ($class === Task::class) {
            $table['indexes'][self::TASK_INDEX] = ['columns' => [$metadata->getColumnName('title'), $metadata->getColumnName('description')], 'flags' => ['fulltext']];
        } else {
            $table['indexes'][self::COMMENT_INDEX] = ['columns' => [$metadata->getColumnName('body')], 'flags' => ['fulltext']];
        }
        $metadata->setPrimaryTable($table);
    }
}
