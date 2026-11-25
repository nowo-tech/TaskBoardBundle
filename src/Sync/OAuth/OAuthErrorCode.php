<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

/**
 * Since 3.1.0 (CU-1): why an OAuth connection failed. Each code has one fixed English message and a translation key
 * `task_board.sync.oauth.error.<value>` (for the host's callback page).
 */
enum OAuthErrorCode: string
{
    /** The user refused on the consent screen, or the callback carried no code (raised by the host's callback). */
    case Denied = 'oauth_denied';

    /** The callback's `state` is missing, malformed, expired or not the stored one (raised by the host's callback). */
    case StateInvalid = 'oauth_state_invalid';

    /** The provider did not exchange the code for a token. */
    case ExchangeFailed = 'oauth_exchange_failed';

    /** The provider refused the access token (HTTP 401). */
    case TokenRejected = 'oauth_token_rejected';

    public function message(): string
    {
        return match ($this) {
            self::Denied         => 'The connection was not authorised.',
            self::StateInvalid   => 'The connection request expired or was not started here.',
            self::ExchangeFailed => 'The provider did not complete the connection.',
            self::TokenRejected  => 'The provider refused the connection token.',
        };
    }
}
