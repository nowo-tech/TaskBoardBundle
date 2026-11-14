<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Psr\Log\LoggerInterface;

use function is_array;
use function is_string;
use function preg_match;
use function version_compare;

/**
 * Since 2.15.1 (REQ-SEC-006 items 3 and 8): the collation of the columns the bundle matches byte for byte (user ids with
 * `user_reference: id`, tenant keys, board slugs, import external ids, task-number scopes). Binary and NO PAD:
 * `utf8mb4_0900_bin` on MySQL 8.0.17+, `utf8mb4_nopad_bin` on MariaDB; `utf8mb4_bin` (PAD SPACE: `"acme "` = `"acme"`)
 * only on older MySQL, where the keys with edge spaces are refused instead. PostgreSQL and SQLite compare exactly
 * already and get none. The repositories re-check every key in PHP anyway.
 *
 * Stateless (REQ-RUNTIME-002): static helpers only.
 *
 * @internal
 */
final class KeyCollation
{
    public const MYSQL_NOPAD   = 'utf8mb4_0900_bin';
    public const MARIADB_NOPAD = 'utf8mb4_nopad_bin';
    public const PAD_FALLBACK  = 'utf8mb4_bin';

    /** First MySQL release with `utf8mb4_0900_bin`. */
    public const MYSQL_NOPAD_SINCE = '8.0.17';

    /**
     * The exact-match collation for that platform, null when the platform already compares exactly. A MySQL 8 platform
     * with an unknown server version gets `utf8mb4_0900_bin`; a version without a patch number (`8.0`) is read as `8.0.0`
     * and falls back to `utf8mb4_bin`.
     */
    public static function for(AbstractPlatform $platform, ?string $serverVersion = null): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB_NOPAD,
            $platform instanceof MySQL80Platform       => self::mysqlNoPad($serverVersion) ? self::MYSQL_NOPAD : self::PAD_FALLBACK,
            $platform instanceof AbstractMySQLPlatform => self::PAD_FALLBACK,
            default                                    => null,
        };
    }

    /** The collation for a connection: its platform and its configured `server_version`. */
    public static function forConnection(Connection $connection): ?string
    {
        return self::for($connection->getDatabasePlatform(), self::configuredVersion($connection));
    }

    /**
     * REQ-SEC-006 item 8: warns when a MySQL `server_version` configured without its patch number (`8`, `8.0`) leaves the
     * key columns on the PAD SPACE `utf8mb4_bin`. `8.4.3` or a MariaDB version is fine.
     */
    public static function warnAboutPartialVersion(Connection $connection, ?LoggerInterface $logger): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($logger === null || !$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform) {
            return;
        }
        $version = self::configuredVersion($connection);
        if ($version === null || preg_match('/^\d+\.\d+\.\d+/', $version) === 1 || self::for($platform, $version) !== self::PAD_FALLBACK) {
            return;
        }
        $logger->warning('doctrine.dbal.server_version "{version}" has no patch number: the TaskBoard key columns get the PAD SPACE collation utf8mb4_bin. Configure the full version (e.g. 8.4.3) to get utf8mb4_0900_bin.', ['bundle' => 'NowoTaskBoardBundle', 'version' => $version]);
    }

    /** The server version configured on the connection (also on a primary / replica connection), if any. */
    private static function configuredVersion(Connection $connection): ?string
    {
        $params  = $connection->getParams();
        $primary = $params['primary'] ?? null;
        $version = $params['serverVersion'] ?? (is_array($primary) ? ($primary['serverVersion'] ?? null) : null);

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function mysqlNoPad(?string $serverVersion): bool
    {
        if ($serverVersion === null || preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $serverVersion, $match) !== 1) {
            return true;
        }
        $version = $match[1] . '.' . (($match[2] ?? '') !== '' ? $match[2] : '0') . '.' . (($match[3] ?? '') !== '' ? $match[3] : '0');

        return version_compare($version, self::MYSQL_NOPAD_SINCE, '>=');
    }
}
