<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Exception;

use RuntimeException;

/**
 * Since 1.23: a provider call failed. The message is the fixed sentence of the code and no previous exception is
 * chained (an HTTP client exception can carry the request options, so it never travels).
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly ProviderErrorCode $errorCode,
    ) {
        parent::__construct($errorCode->message());
    }

    public function code(): ProviderErrorCode
    {
        return $this->errorCode;
    }
}
