<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\PersonalData;

/**
 * Since 3.4.0 (Beacon WORKLIST, RGPD Art. 15 and 20): one person's TaskBoard data for an access or portability request,
 * as plain arrays (ids, dates and the metadata the person created). Framework-free port: a DDD host calls it from its
 * own export; with nowo-tech/user-kit-bundle it is UserKit's `task_board` section (`personal_data.user_kit`).
 *
 * Never a secret or a token: no provider credential, connection reference, OAuth state or idempotency answer. **The
 * host authorises the caller.** Stateless (FrankenPHP worker mode).
 */
interface TaskBoardPersonalDataExporterInterface
{
    /**
     * The sections of {@see TaskBoardPersonalDataExporter::SECTIONS} for the user (every key always present; an empty
     * list when there is nothing or the feature is off).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function export(object $user): array;
}
