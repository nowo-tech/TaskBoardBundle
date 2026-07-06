<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload payload for board task imports.
 */
final class TaskImportFormData
{
    public function __construct(
        public TaskImportSource $source = TaskImportSource::ClickUpCsv,
        public ?UploadedFile $file = null,
        public bool $createMissingColumns = true,
        public bool $skipExisting = true,
    ) {
    }
}
