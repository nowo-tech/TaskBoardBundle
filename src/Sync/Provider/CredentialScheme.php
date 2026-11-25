<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 3.1.0 (ClickUp OAuth, CU-1): how a provider adapter sends a {@see ProviderCredential}, read from its
 * `options['scheme']`. A credential without the option is a personal token (the 1.23 behaviour). An adapter refuses
 * any other value with `ProviderErrorCode::UnsupportedCredential`.
 */
enum CredentialScheme: string
{
    /** The option key in {@see ProviderCredential::$options}. */
    public const OPTION = 'scheme';

    /** A personal API token pasted by a user (ClickUp: `pk_…`, sent as is in `Authorization`). */
    case Personal = 'personal';

    /** An OAuth 2 access token (sent as `Authorization: Bearer <token>`). */
    case OAuth = 'oauth';
}
