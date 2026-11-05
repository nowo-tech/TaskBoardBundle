<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a person as a task, comment, checklist item or time entry names them (provider user id, name, e-mail).
 * Already sanitised by the adapter: one line, no controls; the e-mail is syntax-checked or null.
 */
final readonly class RemotePersonRef
{
    public function __construct(
        public string $providerUserId,
        public string $name,
        public ?string $email = null,
    ) {
    }
}
