<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

/**
 * Since 1.25: sources beyond one list (a folder or a space with several lists), their statuses and list lookups
 * (optional; the connection dialog uses it when the provider implements it).
 */
interface SourceProviderInterface
{
    /** A source by the browser's container id ("s:<id>", "f:<id>" or a list id); null when unknown. */
    public function findSource(ProviderCredential $credential, string $containerId): ?RemoteSource;

    /**
     * Tasks of every list of the source (subtasks and closed ones included), oldest update first, one page per call.
     */
    public function fetchSourceTasks(ProviderCredential $credential, RemoteSource $source, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage;

    /**
     * Ids of every task still in the source; throws on any failure (a partial answer must never look complete).
     *
     * @return iterable<string>
     */
    public function existingSourceTaskIds(ProviderCredential $credential, RemoteSource $source): iterable;

    /**
     * The statuses of the source, in the provider's order.
     *
     * @return list<RemoteStatus>
     */
    public function listStatuses(ProviderCredential $credential, RemoteSource $source): array;

    /** The list id a pasted address points at (no network call); null for any other address. */
    public function resolveListFromUrl(string $url): ?string;

    /**
     * Lists whose name contains the query (case and accents ignored), within a call budget.
     *
     * @return list<RemoteContainer>
     */
    public function searchLists(ProviderCredential $credential, string $query, int $limit = 20): array;
}
