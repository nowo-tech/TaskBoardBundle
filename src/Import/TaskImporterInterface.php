<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;

/**
 * Parses a vendor export into normalized task rows.
 */
interface TaskImporterInterface
{
    public function supports(TaskImportSource $source): bool;

    /**
     * @return list<ImportedTaskDto>
     */
    public function parse(string $content, string $filename): array;
}
