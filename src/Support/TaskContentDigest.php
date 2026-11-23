<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Entity\Task;

use function hash;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.31.0 (REQ-RUNTIME-003 rule 4): a digest of the task fields the edit form writes (title, description, priority,
 * estimate, dates, tags, colour). The form posts the digest of the task it showed (`baseDigest`); a save whose task no
 * longer has that content (someone else saved one of these fields meanwhile) is refused instead of overwriting it.
 * Column, position, members and custom fields are not part of it: a card moved meanwhile keeps the 2.2.1 behaviour (the
 * edit is saved, the task stays where it is now). Stateless.
 */
final class TaskContentDigest
{
    public static function of(Task $task): string
    {
        return hash('sha256', json_encode([
            $task->getTitle(),
            $task->getDescription(),
            $task->getPriority()->value,
            $task->getEstimatedMinutes(),
            $task->getDueAt()?->format(DATE_ATOM),
            $task->getTags(),
            $task->getColor(),
            $task->getStartAt()?->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
