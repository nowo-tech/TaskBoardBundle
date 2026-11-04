<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider\ClickUp;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Import\ClickUp\ClickUpTaskMapper;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerPage;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCapabilities;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function ctype_digit;
use function implode;
use function intdiv;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function strlen;
use function substr;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.23: ClickUp API v2. The base URI is a constant (never configurable), the token goes only to it, in the
 * `Authorization` header (personal tokens); paging, rate limits, timeouts and size caps are those of
 * {@see ProviderHttpClient}. Tree ids carry their level: "w:<workspace>", "s:<space>", "f:<folder>"; a list is its
 * plain ClickUp id.
 */
final readonly class ClickUpTaskProvider implements TaskProviderInterface
{
    public const BASE_URI = 'https://api.clickup.com/api/v2/';

    public const ID = 'clickup';

    /** Tasks per page of GET list/{id}/task. */
    public const PAGE_SIZE = 100;

    /** Comments per page of GET task/{id}/comment. */
    public const COMMENT_PAGE_SIZE = 25;

    /** Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. */
    public const DEFAULT_ATTACHMENT_HOSTS = ['*.clickup-attachments.com', 'attachments.clickup.com', 'attachments2.clickup.com'];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** The most pages the id scan of the deletions phase reads (100 000 tasks). */
    private const MAX_ID_PAGES = 1000;

    /**
     * @param list<string> $attachmentHosts
     */
    public function __construct(
        private ProviderHttpClient $http,
        private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer(),
        private ClickUpTaskMapper $mapper = new ClickUpTaskMapper(),
        private array $attachmentHosts = self::DEFAULT_ATTACHMENT_HOSTS,
        private int $maxComments = 500,
        private int $maxDescriptionLength = 100_000,
        // Since 1.23: unexpected answer shapes are logged at debug level (keys only).
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'task_board.sync.provider.clickup';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            comments: true,
            attachments: true,
            dependencies: true,
            customFields: true,
            subtasks: true,
            incremental: true,
            attachmentHosts: $this->attachmentHosts,
        );
    }

    public function importScope(ProviderCredential $credential): string
    {
        return self::ID;
    }

    public function listContainers(ProviderCredential $credential, ?string $parentId = null, ?string $cursor = null): ContainerPage
    {
        if ($parentId === null) {
            $items = [];
            foreach ($this->items($this->get($credential, 'team'), 'teams') as $team) {
                $items[] = new RemoteContainer('w:' . $this->itemId($team), $this->name($team), ContainerType::Workspace, null, false, true);
            }

            return new ContainerPage($items);
        }
        if (preg_match('/^([wsf]):(.+)$/D', $parentId, $match) !== 1) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }
        $id = $this->checkId($match[2]);

        return new ContainerPage(match ($match[1]) {
            'w' => $this->spaces($credential, $id, $parentId),
            's' => [...$this->folders($credential, $id, $parentId), ...$this->lists($credential, sprintf('space/%s/list', $id), $parentId)],
            'f' => $this->lists($credential, sprintf('folder/%s/list', $id), $parentId),
        });
    }

    public function findContainer(ProviderCredential $credential, string $containerId): ?RemoteContainer
    {
        if (preg_match(self::ID_PATTERN, $containerId) !== 1) {
            return null;
        }
        try {
            $list = $this->get($credential, sprintf('list/%s', $containerId));
        } catch (ProviderException $exception) {
            if ($exception->errorCode === ProviderErrorCode::NotFound) {
                return null;
            }

            throw $exception;
        }
        $path   = [];
        $space  = is_array($list['space'] ?? null) ? $list['space'] : [];
        $folder = is_array($list['folder'] ?? null) ? $list['folder'] : [];
        if ($this->name($space) !== '') {
            $path[] = $this->name($space);
        }
        if ($this->name($folder) !== '' && ($folder['hidden'] ?? false) !== true) {
            $path[] = $this->name($folder);
        }

        return new RemoteContainer($this->itemId($list), $this->name($list), ContainerType::List, null, true, false, $this->count($list['task_count'] ?? null), $path);
    }

    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage
    {
        $page  = $this->page($cursor);
        $query = [
            'page'                         => $page,
            'subtasks'                     => 'true',
            'include_closed'               => 'true',
            'include_markdown_description' => 'true',
            'order_by'                     => 'updated',
            // Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy.
            // reverse=true is meant to list the oldest update first, so a task updated during the scan moves to a later
            // page instead of shifting the pages not read yet. The run does not depend on it: the watermark is the
            // newest date of all pages, and the overlap window of the next run re-reads the edge.
            'reverse' => 'true',
        ];
        if ($updatedSince instanceof DateTimeImmutable) {
            $query['date_updated_gt'] = (string) ($updatedSince->getTimestamp() * 1000);
        }
        $data  = $this->get($credential, sprintf('list/%s/task', $this->checkId($listId)), $query);
        $tasks = [];
        foreach ($this->items($data, 'tasks') as $row) {
            $task = $this->toRemoteTask($row);
            if ($task instanceof RemoteTask) {
                $tasks[] = $task;
            }
        }
        $rows = count($this->items($data, 'tasks'));
        $last = $this->lastPage($data, $rows);

        return new RemoteTaskPage($tasks, $last ? null : (string) ($page + 1), $last);
    }

    public function fetchComments(ProviderCredential $credential, string $taskId): iterable
    {
        $path     = sprintf('task/%s/comment', $this->checkId($taskId));
        $comments = [];
        $query    = [];
        // Audit EG-L2: raw rows count, at most ceil(max / 25) + 1 pages, and a page that does not move stops.
        $pageCap = intdiv($this->maxComments + self::COMMENT_PAGE_SIZE - 1, self::COMMENT_PAGE_SIZE) + 1;
        for ($pages = 1, $rowsRead = 0; count($comments) < $this->maxComments; ++$pages) {
            $rows = $this->items($this->get($credential, $path, $query), 'comments');
            $rowsRead += count($rows);
            foreach ($rows as $row) {
                $comment = $this->toRemoteComment($row);
                if ($comment instanceof RemoteComment && count($comments) < $this->maxComments) {
                    $comments[] = $comment;
                }
            }
            $oldest = $rows === [] ? [] : $rows[count($rows) - 1];
            // Older comments: start = the oldest date seen, start_id = its id (the documented paging). Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy.
            $next = ['start' => $this->stringOf($oldest['date'] ?? null), 'start_id' => $this->itemId($oldest)];
            if (count($rows) < self::COMMENT_PAGE_SIZE || $next['start_id'] === '' || $next === $query || $pages >= $pageCap || $rowsRead >= $this->maxComments) {
                break;
            }
            $query = $next;
        }

        // The API answers newest first.
        return array_reverse($comments);
    }

    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable
    {
        $path = sprintf('list/%s/task', $this->checkId($listId));
        for ($page = 0;; ++$page) {
            if ($page >= self::MAX_ID_PAGES) {
                // A runaway paging loop: a partial answer must never look complete.
                throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
            }
            $data = $this->get($credential, $path, ['page' => $page, 'subtasks' => 'true', 'include_closed' => 'true']);
            $rows = $this->items($data, 'tasks');
            foreach ($rows as $row) {
                $id = $this->itemId($row);
                if ($id !== '' && ($row['archived'] ?? false) !== true) {
                    yield $id;
                }
            }
            if ($this->lastPage($data, count($rows))) {
                return;
            }
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function toRemoteTask(array $row): ?RemoteTask
    {
        $markdown    = $this->stringOf($row['markdown_description'] ?? null);
        $description = $markdown !== ''
            ? $this->sanitizer->markdown($markdown, $this->maxDescriptionLength)
            : $this->sanitizer->markdown($this->stringOf($row['text_content'] ?? $row['description'] ?? null), $this->maxDescriptionLength);
        // Titles are one clean line (no controls, no bidi overrides).
        $row['name'] = $this->sanitizer->line($this->stringOf($row['name'] ?? null), 255);
        $task        = $this->mapper->map($row, $description === '' ? null : $description);
        if (!$task instanceof ImportedTaskDto || $task->externalId === null || preg_match(self::ID_PATTERN, $task->externalId) !== 1) {
            return null;
        }

        return new RemoteTask(
            task: $task,
            remoteUpdatedAt: $this->date($row['date_updated'] ?? null) ?? $this->date($row['date_created'] ?? null) ?? new DateTimeImmutable('@0'),
            archived: ($row['archived'] ?? false) === true,
            attachments: $this->attachments($row),
            inlineImageUrls: $this->imageUrls($description),
            customFieldsText: $this->customFields($row),
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function toRemoteComment(array $row): ?RemoteComment
    {
        $id = $this->itemId($row);
        if ($id === '') {
            return null;
        }
        $segments = is_array($row['comment'] ?? null) ? $row['comment'] : [];
        $body     = $segments !== [] ? $this->segmentsToMarkdown($segments) : $this->stringOf($row['comment_text'] ?? null);
        $body     = $this->sanitizer->markdown($body, 5000);
        $user     = is_array($row['user'] ?? null) ? $row['user'] : [];

        return new RemoteComment(
            id: $id,
            body: $body,
            authorEmail: ($email = $this->stringOf($user['email'] ?? null)) !== '' ? $email : null,
            authorName: ($name = $this->stringOf($user['username'] ?? null)) !== '' ? $this->sanitizer->line($name, 120) : null,
            createdAt: $this->date($row['date'] ?? null) ?? new DateTimeImmutable('@0'),
            inlineImageUrls: $this->imageUrls($body),
        );
    }

    /**
     * ClickUp rich text (a list of {text, attributes, type}) as Markdown.
     *
     * @param array<mixed> $segments
     */
    private function segmentsToMarkdown(array $segments): string
    {
        $out = '';
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $attributes = is_array($segment['attributes'] ?? null) ? $segment['attributes'] : [];
            if (($segment['type'] ?? null) === 'image' && is_array($segment['image'] ?? null)) {
                $url = $this->stringOf($segment['image']['url'] ?? null);
                $out .= $url !== '' ? sprintf('![%s](%s)', $this->stringOf($segment['image']['name'] ?? 'image'), $url) : '';
                continue;
            }
            if (($segment['type'] ?? null) === 'tag' && is_array($segment['user'] ?? null)) {
                $out .= '@' . $this->stringOf($segment['user']['username'] ?? '');
                continue;
            }
            // Raw text: the spaces between segments are part of the comment.
            $text = is_string($segment['text'] ?? null) ? $segment['text'] : '';
            if (trim($text) === '') {
                $out .= $text;
                continue;
            }
            if (($attributes['code'] ?? false) === true) {
                $text = '`' . $text . '`';
            }
            if (($attributes['bold'] ?? false) === true) {
                $text = '**' . $text . '**';
            }
            if (($attributes['italic'] ?? false) === true) {
                $text = '*' . $text . '*';
            }
            $link = $attributes['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $text = sprintf('[%s](%s)', $text, $link);
            }
            $out .= $text;
        }

        return $out;
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<RemoteAttachment>
     */
    private function attachments(array $row): array
    {
        $files = [];
        foreach ($this->items($row, 'attachments') as $file) {
            $id  = $this->itemId($file);
            $url = $this->stringOf($file['url'] ?? null);
            if ($id === '' || $url === '') {
                continue;
            }
            // Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. Every field but id and url may be missing.
            $files[] = new RemoteAttachment(
                id: $id,
                name: $this->stringOf($file['title'] ?? $file['name'] ?? null) ?: 'file',
                size: $this->count($file['size'] ?? null),
                mime: ($mime = $this->stringOf($file['mimetype'] ?? null)) !== '' ? $mime : null,
                url: $url,
                updatedAt: $this->date($file['date'] ?? null),
            );
        }

        return $files;
    }

    /**
     * @param array<mixed> $row
     */
    private function customFields(array $row): ?string
    {
        $lines = [];
        foreach ($this->items($row, 'custom_fields') as $field) {
            $name  = $this->sanitizer->line($this->stringOf($field['name'] ?? null), 120);
            $value = $field['value'] ?? null;
            if ($name === '' || $value === null || $value === '' || $value === []) {
                continue;
            }
            $text    = is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $lines[] = sprintf('- **%s**: %s', $name, $this->sanitizer->line($text, 500));
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function imageUrls(string $markdown): array
    {
        preg_match_all('/!\[[^\]\n]*\]\(\s*<?([^)\s>]+)/', $markdown, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<RemoteContainer>
     */
    private function spaces(ProviderCredential $credential, string $workspaceId, string $parentId): array
    {
        $items = [];
        foreach ($this->items($this->get($credential, sprintf('team/%s/space', $workspaceId), ['archived' => 'false']), 'spaces') as $space) {
            $items[] = new RemoteContainer('s:' . $this->itemId($space), $this->name($space), ContainerType::Space, $parentId, false, true);
        }

        return $items;
    }

    /**
     * @return list<RemoteContainer>
     */
    private function folders(ProviderCredential $credential, string $spaceId, string $parentId): array
    {
        $items = [];
        foreach ($this->items($this->get($credential, sprintf('space/%s/folder', $spaceId), ['archived' => 'false']), 'folders') as $folder) {
            $items[] = new RemoteContainer('f:' . $this->itemId($folder), $this->name($folder), ContainerType::Folder, $parentId, false, true);
        }

        return $items;
    }

    /**
     * @return list<RemoteContainer>
     */
    private function lists(ProviderCredential $credential, string $path, string $parentId): array
    {
        $items = [];
        foreach ($this->items($this->get($credential, $path, ['archived' => 'false']), 'lists') as $list) {
            $items[] = new RemoteContainer($this->itemId($list), $this->name($list), ContainerType::List, $parentId, true, false, $this->count($list['task_count'] ?? null));
        }

        return $items;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(ProviderCredential $credential, string $path, array $query = []): array
    {
        return $this->http->getJson(self::BASE_URI . $path, $query, ['Authorization' => $credential->reveal()]);
    }

    /**
     * The array items of `$data[$key]` (anything else is skipped).
     *
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function items(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null)) {
            $this->unexpected($key, $data);

            return [];
        }
        $items = [];
        foreach ($data[$key] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            } else {
                $this->unexpected($key . '[]', $data);
            }
        }

        return $items;
    }

    /**
     * Whether a task page is the last one. Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. `last_page` is used when the answer has it; without it the paging
     * goes on until an empty page (a short page is not trusted to be the last: the page size is not confirmed either).
     * The run's page caps (audit EG-L2) bound it in every case.
     *
     * @param array<mixed> $data
     */
    private function lastPage(array $data, int $rows): bool
    {
        if (array_key_exists('last_page', $data)) {
            return $data['last_page'] === true || $rows === 0;
        }
        $this->unexpected('last_page', $data);

        return $rows === 0;
    }

    /**
     * An answer that does not have the expected shape: logged at debug level with its keys only, never a value.
     *
     * @param array<mixed> $data
     */
    private function unexpected(string $what, array $data): void
    {
        $this->logger->debug('TaskBoard sync: unexpected ClickUp answer shape', [
            'bundle'   => 'nowo_task_board',
            'provider' => self::ID,
            'field'    => $what,
            'keys'     => array_slice(array_map(strval(...), array_keys($data)), 0, 30),
        ]);
    }

    /**
     * @param array<mixed> $item
     */
    private function itemId(array $item): string
    {
        $id = $this->stringOf($item['id'] ?? null);

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
    }

    /**
     * @param array<mixed> $item
     */
    private function name(array $item): string
    {
        return $this->sanitizer->line($this->stringOf($item['name'] ?? null), 255);
    }

    private function checkId(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }

        return $id;
    }

    private function page(?string $cursor): int
    {
        return $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
    }

    private function count(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /** A ClickUp date: milliseconds since the epoch. */
    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = $this->stringOf($value);
        if ($value === '' || !ctype_digit($value) || strlen($value) > 15) {
            return null;
        }

        return new DateTimeImmutable('@' . (strlen($value) > 3 ? substr($value, 0, -3) : '0'));
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
