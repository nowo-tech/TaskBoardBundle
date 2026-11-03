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
        /**
         * @deprecated since 1.22, ignored and no longer a form field: every import is an idempotent upsert
         */
        public bool $skipExisting = true,
    ) {
    }
}
