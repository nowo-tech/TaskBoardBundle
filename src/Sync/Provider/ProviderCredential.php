<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use LogicException;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use SensitiveParameter;

use function mb_substr;

/**
 * Since 1.23: a provider token for one run, built by the host ({@see ProviderCredentialResolverInterface}).
 * The bundle never stores, serializes, logs or dispatches it: {@see self::reveal()} is the only way to read the
 * secret, debug output is redacted and serialization throws (so it cannot reach a Messenger message, a cache or a
 * session).
 */
final readonly class ProviderCredential
{
    /**
     * @param string $connectionRef the host's opaque id of the stored connection (at most 191 characters)
     * @param array<string, scalar> $options non-secret options, e.g. ['scheme' => 'personal'|'oauth']
     */
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
        public string $connectionRef,
        public array $options = [],
    ) {
    }

    /** The secret, for the provider adapter's Authorization header only. */
    public function reveal(): string
    {
        return $this->secret;
    }

    /** The last four characters, for the UI ("…a1b2"). */
    public function lastFour(): string
    {
        return mb_substr($this->secret, -4);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['connectionRef' => $this->connectionRef, 'secret' => '[redacted]', 'options' => $this->options];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A provider credential cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A provider credential cannot be unserialized.');
    }
}
