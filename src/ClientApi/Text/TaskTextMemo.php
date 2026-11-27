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

    /** Since 3.4.0 (audit ZCS-M1): bytes read for emphasis so far. */
    public int $emphasisBytes = 0;

    /** Since 3.4.0 (audit ZCS-M1): emphasis spans found so far. */
    public int $marks = 0;

    /**
     * Since 3.4.1 (audit ZCS-v-I1, wording ZCY-I1): search start positions the emphasis patterns tried (from each search's
     * offset to its match or the end of the text). Each start may read a span body of up to 2000 characters.
     */
    public int $emphasisScanned = 0;
}
