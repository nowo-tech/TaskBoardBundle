#!/usr/bin/env sh
# Generates local-only AWS S3 example services (gitignored). Safe to run; never commits secrets.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEMO="${ROOT}/demo/symfony8"
TARGET="${DEMO}/src/YopassExamples/S3"
CONFIG_LOCAL="${DEMO}/config/local"
CONFIG_PKG="${DEMO}/config/packages"
CONFIG_SVC="${DEMO}/config"

mkdir -p "${TARGET}" "${CONFIG_LOCAL}" "${CONFIG_PKG}"

if [ ! -f "${DEMO}/.env.s3.example" ]; then
  echo "ERROR: missing ${DEMO}/.env.s3.example" >&2
  exit 1
fi

cat > "${TARGET}/S3EncryptedCiphertextStore.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\YopassExamples\S3;

use Aws\S3\S3Client;
use RuntimeException;

/**
 * Stores E2E ciphertext in a private S3 bucket with server-side encryption (SSE-KMS or SSE-S3).
 *
 * Local-only example — not part of the distributable bundle.
 */
final class S3EncryptedCiphertextStore
{
    private const REF_PREFIX = 'yopass+s3:';

    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly ?string $kmsKeyId,
        private readonly string $keyPrefix,
    ) {
    }

    public function isReference(string $value): bool
    {
        return str_starts_with($value, self::REF_PREFIX);
    }

    public function upload(string $shareId, string $ciphertext): string
    {
        $objectKey = rtrim($this->keyPrefix, '/') . '/' . $shareId . '.enc';

        $params = [
            'Bucket'               => $this->bucket,
            'Key'                  => $objectKey,
            'Body'                 => $ciphertext,
            'ACL'                  => 'private',
            'ContentType'          => 'application/octet-stream',
            'Metadata'             => ['yopass-ciphertext' => '1'],
            'ServerSideEncryption' => $this->kmsKeyId !== null && $this->kmsKeyId !== '' ? 'aws:kms' : 'AES256',
        ];

        if ($params['ServerSideEncryption'] === 'aws:kms') {
            $params['SSEKMSKeyId'] = $this->kmsKeyId;
        }

        $this->client->putObject($params);

        return self::REF_PREFIX . $objectKey;
    }

    public function download(string $reference): string
    {
        if (!$this->isReference($reference)) {
            throw new RuntimeException('Not an S3 ciphertext reference.');
        }

        $objectKey = substr($reference, strlen(self::REF_PREFIX));
        $result    = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key'    => $objectKey,
        ]);

        $body = $result['Body'] ?? null;

        if ($body === null) {
            throw new RuntimeException('Empty S3 object body.');
        }

        return (string) $body;
    }
}
PHP

cat > "${TARGET}/S3ShareFileHandler.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\YopassExamples\S3;

use Aws\S3\S3Client;
use Nowo\YopassBundle\Service\ShareFileHandlerInterface;
use RuntimeException;

/**
 * Enables the file tab when a private encrypted S3 bucket is configured.
 */
final class S3ShareFileHandler implements ShareFileHandlerInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly int $maxFileBytes,
    ) {
        if ($this->bucket === '') {
            throw new RuntimeException('YOPASS_S3_BUCKET is required for S3 file shares.');
        }
    }

    public function getMaxFileBytes(): int
    {
        return $this->maxFileBytes;
    }

    /** Verifies bucket access (private; no public ACL). Call from container if desired. */
    public function assertBucketReachable(): void
    {
        $this->client->headBucket(['Bucket' => $this->bucket]);
    }
}
PHP

cat > "${TARGET}/S3OffloadingShareRepository.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\YopassExamples\S3;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

/**
 * Persists file-share ciphertext in S3; keeps a pointer in the relational store.
 */
final class S3OffloadingShareRepository implements ShareRepositoryInterface
{
    public function __construct(
        private readonly ShareRepositoryInterface $inner,
        private readonly S3EncryptedCiphertextStore $store,
    ) {
    }

    public function find(string $id): ?SecureShare
    {
        $share = $this->inner->find($id);

        if (!$share instanceof SecureShare) {
            return null;
        }

        if ($this->store->isReference($share->getCiphertext())) {
            $share->setCiphertext($this->store->download($share->getCiphertext()));
        }

        return $share;
    }

    /** @return list<SecureShare> */
    public function findByCreator(object $creator): array
    {
        return $this->inner->findByCreator($creator);
    }

    public function countByCreator(object $creator): int
    {
        return $this->inner->countByCreator($creator);
    }

    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        return $this->inner->findByCreatorPaginated($creator, $limit, $offset);
    }

    public function removeByCreatorOlderThan(object $creator, \DateTimeImmutable $before): int
    {
        return $this->inner->removeByCreatorOlderThan($creator, $before);
    }

    public function removeAllByCreator(object $creator): int
    {
        return $this->inner->removeAllByCreator($creator);
    }

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->inner->removeOlderThan($before);
    }

    public function persist(SecureShare $share): void
    {
        if ($share->getPayloadKind() === 'file' && !$this->store->isReference($share->getCiphertext())) {
            $reference = $this->store->upload($share->getId(), $share->getCiphertext());
            $share->setCiphertext($reference);
        }

        $this->inner->persist($share);
    }

    public function remove(SecureShare $share): void
    {
        $this->inner->remove($share);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }
}
PHP

cat > "${CONFIG_LOCAL}/services_s3.yaml" <<'YAML'
# Optional S3 wiring (gitignored). Requires: composer require aws/aws-sdk-php. Active when YOPASS_USE_S3=1.
when@env(YOPASS_USE_S3): '1'
services:
    App\YopassExamples\S3\S3EncryptedCiphertextStore:
        arguments:
            $client: '@App\YopassExamples\S3\YopassS3Client'
            $bucket: '%env(YOPASS_S3_BUCKET)%'
            $kmsKeyId: '%env(default::YOPASS_S3_KMS_KEY_ID)%'
            $keyPrefix: '%env(default:yopass-shares:YOPASS_S3_KEY_PREFIX)%'

    App\YopassExamples\S3\S3ShareFileHandler:
        arguments:
            $client: '@App\YopassExamples\S3\YopassS3Client'
            $bucket: '%env(YOPASS_S3_BUCKET)%'
            $maxFileBytes: '%env(int:default:524288:YOPASS_S3_MAX_FILE_BYTES)%'

    App\YopassExamples\S3\S3OffloadingShareRepository:
        arguments:
            $inner: '@Nowo\YopassBundle\Repository\DoctrineOrmShareRepository'

    App\YopassExamples\S3\YopassS3Client:
        class: Aws\S3\S3Client
        factory: ['App\YopassExamples\S3\YopassS3ClientFactory', 'create']
        arguments:
            $region: '%env(AWS_REGION)%'
YAML

cat > "${TARGET}/YopassS3ClientFactory.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\YopassExamples\S3;

use Aws\S3\S3Client;

final class YopassS3ClientFactory
{
    public static function create(string $region): S3Client
    {
        return new S3Client([
            'version'                 => 'latest',
            'region'                    => $region,
            'use_aws_shared_config_files' => true,
        ]);
    }
}
PHP

cat > "${CONFIG_LOCAL}/nowo_yopass_s3.yaml" <<'YAML'
# Optional S3 override (gitignored). Active only when YOPASS_USE_S3=1 in .env.
when@env(YOPASS_USE_S3): '1'
nowo_yopass:
    file_handler: App\YopassExamples\S3\S3ShareFileHandler
    database:
        driver: custom
        repository: App\YopassExamples\S3\S3OffloadingShareRepository
YAML

cat > "${CONFIG_LOCAL}/services_s3.yaml" <<'YAML'
# Optional S3 wiring (gitignored). Requires: composer require aws/aws-sdk-php. Active when YOPASS_USE_S3=1.
when@env(YOPASS_USE_S3): '1'
services:
    App\YopassExamples\S3\S3EncryptedCiphertextStore:
        arguments:
            $client: '@App\YopassExamples\S3\YopassS3Client'
            $bucket: '%env(YOPASS_S3_BUCKET)%'
            $kmsKeyId: '%env(default::YOPASS_S3_KMS_KEY_ID)%'
            $keyPrefix: '%env(default:yopass-shares:YOPASS_S3_KEY_PREFIX)%'

    App\YopassExamples\S3\S3ShareFileHandler:
        arguments:
            $client: '@App\YopassExamples\S3\YopassS3Client'
            $bucket: '%env(YOPASS_S3_BUCKET)%'
            $maxFileBytes: '%env(int:default:524288:YOPASS_S3_MAX_FILE_BYTES)%'

    App\YopassExamples\S3\S3OffloadingShareRepository:
        arguments:
            $inner: '@Nowo\YopassBundle\Repository\DoctrineOrmShareRepository'

    App\YopassExamples\S3\YopassS3Client:
        class: Aws\S3\S3Client
        factory: ['App\YopassExamples\S3\YopassS3ClientFactory', 'create']
        arguments:
            $region: '%env(AWS_REGION)%'
YAML

if [ ! -f "${DEMO}/.env.s3.local" ]; then
  cp "${DEMO}/.env.s3.example" "${DEMO}/.env.s3.local"
  echo "Created ${DEMO}/.env.s3.local — fill AWS credentials and bucket (never commit)."
fi

echo "S3 examples scaffolded under ${TARGET}"
echo "Next steps:"
echo "  1. Edit demo/symfony8/.env.s3.local (private bucket, SSE-KMS optional)"
echo "  2. Append its contents to demo/symfony8/.env (include YOPASS_USE_S3=1)"
echo "  3. Uncomment S3 imports in config/packages/nowo_yopass.yaml and config/services.yaml"
echo "  4. cd demo/symfony8 && composer require aws/aws-sdk-php"
echo "  5. make -C demo/symfony8 update-bundle"
