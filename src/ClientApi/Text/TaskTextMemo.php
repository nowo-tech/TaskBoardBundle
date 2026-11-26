<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Text;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientReference;

/**
 * Since 3.4.0: what one conversion asked the host already (each reference and file once per text). Created per call
 * and dropped with it: nothing outlives the request (worker mode).
 *
 * @internal
 */
final class TaskTextMemo
{
    /** @var array<string, false|TaskClientReference> */
    public array $references = [];

    /** @var array<string, bool> */
    public array $files = [];
}
