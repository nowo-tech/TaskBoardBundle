<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use RuntimeException;

/**
 * Since 2.29.0: the host's plan refused an action of an id-based host port ({@see TaskBoardEvents::QUOTA_CHECK}, as on
 * the pages). The message is the host's reason. Nothing was written.
 */
final class TaskBoardQuotaRefusedException extends RuntimeException
{
}
