<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF token manager of the functional kernel: the token of id X is "csrf-X", so tests post the token of the action
 * they call (and a wrong one to assert the refusal) without scraping it from the page.
 */
final class PredictableCsrfTokenManager implements CsrfTokenManagerInterface
{
    public static function value(string $tokenId): string
    {
        return 'csrf-' . $tokenId;
    }

    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, self::value($tokenId));
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return $this->getToken($tokenId);
    }

    public function removeToken(string $tokenId): ?string
    {
        return null;
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return $token->getValue() === self::value($token->getId());
    }
}
