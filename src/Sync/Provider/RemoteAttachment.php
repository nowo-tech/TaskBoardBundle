<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

use function parse_url;

use const PHP_URL_HOST;

/**
 * Since 1.23: a file of a provider task. Its URL may be signed: debug output shows the host only, and the bundle
 * never logs it.
 */
final readonly class RemoteAttachment
{
    public function __construct(
        public string $id,
        public string $name,
        public ?int $size,
        public ?string $mime,
        public string $url,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'size' => $this->size, 'mime' => $this->mime, 'host' => parse_url($this->url, PHP_URL_HOST)];
    }
}
