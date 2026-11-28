<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance\Attribute;

use Attribute;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteChannelControllerListener;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): the channel of the writes a controller makes, declared on the controller class
 * or on one action (the action's wins). {@see WriteChannelControllerListener} runs the action inside
 * `WriteChannelScope::run()` with it. The bundle's controllers carry it (web pages `web`, Task API `api`, client API
 * `app`); a host may put it on its own controllers that write tasks.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class AsWriteChannel
{
    public function __construct(public WriteChannel $channel)
    {
    }
}
