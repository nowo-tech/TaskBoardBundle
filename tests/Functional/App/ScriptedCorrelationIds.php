<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;

/**
 * Since 3.7.0 (Beacon spec 235): the host's correlation id provider in the tests, registered as
 * `Nowo\CorrelationContract\CorrelationIdProviderInterface` so the bundle autowires it. {@see self::$current} is the id
 * of the unit of work a test scripts (null by default: no id).
 */
final class ScriptedCorrelationIds implements CorrelationIdProviderInterface
{
    public static ?CorrelationId $current = null;

    public function current(): ?CorrelationId
    {
        return self::$current;
    }

    public function currentOrGenerate(): CorrelationId
    {
        return self::$current ?? CorrelationId::generate();
    }
}
