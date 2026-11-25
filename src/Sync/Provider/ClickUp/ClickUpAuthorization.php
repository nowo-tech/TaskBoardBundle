<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider\ClickUp;

use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\OAuth\ClickUpOAuthClient;
use Nowo\TaskBoardBundle\Sync\Provider\CredentialScheme;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

/**
 * Since 3.1.0 (CU-1): the `Authorization` header value of a ClickUp credential: a personal token (`pk_…`) as is, an
 * OAuth access token as `Bearer <token>`. Any other `scheme` is refused before a request is sent. Pure function.
 *
 * @internal used by {@see ClickUpTaskProvider} and {@see ClickUpOAuthClient}
 */
final class ClickUpAuthorization
{
    /**
     * @throws ProviderException `unsupported_credential` for an unknown scheme
     */
    public static function header(ProviderCredential $credential): string
    {
        return match ($credential->scheme()) {
            CredentialScheme::Personal => $credential->reveal(),
            CredentialScheme::OAuth    => 'Bearer ' . $credential->reveal(),
            null                       => throw new ProviderException(ProviderErrorCode::UnsupportedCredential),
        };
    }
}
