<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use function is_array;
use function is_scalar;
use function json_decode;

/**
 * Since 1.16: form payload of a comment — its text and the people picked in the @mention list (a JSON list of
 * {id, label} written by the comment box; the server keeps only valid candidates still named in the text).
 */
final class TaskCommentFormData
{
    public function __construct(
        public string $body = '',
        public string $mentions = '[]',
    ) {
    }

    /** @return list<string> */
    public function mentionIds(): array
    {
        $decoded = json_decode($this->mentions, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if (is_scalar($id) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
