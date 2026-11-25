<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use DateTimeImmutable;
use LogicException;
use Nowo\TaskBoardBundle\Sync\Provider\CredentialScheme;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use SensitiveParameter;

use function mb_substr;

/**
 * Since 3.1.0 (CU-1): what a code exchange returns, for the host to encrypt and store. Like {@see ProviderCredential}
 * it is never stored, logged or dispatched by the bundle: debug output is redacted and serialization throws.
 *
 * ClickUp tokens do not expire today and come without a refresh token. {@see self::$expiresAt} and
 * {@see self::refreshToken()} exist so that a future ClickUp change lands as a minor (Q-CU-16); the bundle fills
 * neither and has no refresh code.
 */
final readonly class OAuthGrant
{
    public function __construct(
        #[SensitiveParameter]
        private string $accessToken,
        public ?DateTimeImmutable $expiresAt = null,
        #[SensitiveParameter]
        private ?string $refreshToken = null,
    ) {
    }

    /** The access token, for the host to encrypt and store. */
    public function accessToken(): string
    {
        return $this->accessToken;
    }

    /** Always null with ClickUp today (no refresh tokens). */
    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /** The last four characters, for the UI ("…a1b2"). */
    public function lastFour(): string
    {
        return mb_substr($this->accessToken, -4);
    }

    /**
     * The credential of this token (scheme `oauth`, whatever `$options` says), e.g. to call
     * {@see ClickUpOAuthClient::describe()} right after the exchange.
     *
     * @param array<string, scalar> $options other non-secret options
     */
    public function toCredential(string $connectionRef, array $options = []): ProviderCredential
    {
        return new ProviderCredential($this->accessToken, $connectionRef, [CredentialScheme::OPTION => CredentialScheme::OAuth->value] + $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'expiresAt' => $this->expiresAt, 'refreshToken' => $this->refreshToken === null ? null : '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An OAuth grant cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An OAuth grant cannot be unserialized.');
    }
}
