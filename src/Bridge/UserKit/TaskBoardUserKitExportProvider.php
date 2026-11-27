<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\UserKit;

use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporter;
use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporterInterface;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * Since 3.4.0 (Beacon WORKLIST): UserKit's personal-data export section `task_board` — the sections of
 * {@see TaskBoardPersonalDataExporterInterface::export()} (one per account-erasure step,
 * {@see TaskBoardPersonalDataExporter::ERASURE_SECTIONS}, plus the assignments and the custom field values). Never a
 * secret or a token. Registered only when nowo-tech/user-kit-bundle is installed and `personal_data.user_kit` is true.
 */
final readonly class TaskBoardUserKitExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'task_board';

    /** After host sections, before UserKit's built-ins (-100 and below). */
    public const PRIORITY = -50;

    public function __construct(
        private TaskBoardPersonalDataExporterInterface $exporter,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return SyncUser::id($user) !== null;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function export(object $user): array
    {
        return $this->exporter->export($user);
    }
}
