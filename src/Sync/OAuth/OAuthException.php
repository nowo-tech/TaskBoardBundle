<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use RuntimeException;

/**
 * Since 3.1.0 (CU-1): an OAuth step failed. The message is the code's fixed sentence; the provider's HTTP status is
 * kept, its body never (it could echo the code or the client secret), and no previous exception is chained (an HTTP
 * client exception can carry the request options).
 */
final class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly OAuthErrorCode $errorCode,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($errorCode->message());
    }
}
