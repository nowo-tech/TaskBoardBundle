<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\DBAL\Exception\RetryableException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Throwable;

use function max;

/**
 * Since 2.22.0 (owner decision, transaction-doctrine 1.3): reads a failure's whole previous chain for a database lock
 * conflict the client may simply retry — an exception marked {@see LockConflictExceptionInterface} (a write unit's
 * {@see ConcurrencyConflict}, a host's or a bundle's own), a {@see TransientStorageFailure}, a DBAL
 * {@see RetryableException} (deadlock, lock wait timeout) or what {@see LockConflict::is()} recognises (PostgreSQL
 * `55P03`, MySQL's lost savepoint). Anywhere in the chain, so a host decorator that wraps the failure keeps the answer.
 *
 * Stateless.
 *
 * @internal
 */
final class LockConflictChain
{
    public static function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof LockConflictExceptionInterface || $current instanceof TransientStorageFailure || $current instanceof RetryableException || LockConflict::is($current)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a write unit already committed part of its work before the conflict (no blind retry). */
    public static function committed(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict && $current->committed) {
                return true;
            }
        }

        return false;
    }

    /** The `Retry-After` hint (seconds, at least 1) a {@see TransientStorageFailure} of the chain carries; null: none. */
    public static function retryAfterHint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint === null ? null : max(1, $hint);
            }
        }

        return null;
    }
}
