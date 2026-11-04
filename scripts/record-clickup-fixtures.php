#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Records the ClickUp API v2 fixtures of tests/Fixtures/ClickUp from a throwaway workspace, redacted.
 *
 *   CLICKUP_RECORD_TOKEN=pk_… [CLICKUP_RECORD_LIST=<list id>] [CLICKUP_RECORD_TASK=<task id>] php scripts/record-clickup-fixtures.php
 *   CLICKUP_RECORD_TOKEN=pk_… make record-clickup-fixtures
 *
 * The token is read from the environment only (never from argv, never from a file of the repository) and is never
 * written or printed. Before anything is written, every token, workspace / space / folder / list / user name,
 * e-mail, avatar, id-bearing URL and signed query string is replaced by a stable placeholder. At most 60 requests.
 * Only the file names are printed.
 */

use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

const BASE   = 'https://api.clickup.com/api/v2/';
const OUT    = __DIR__ . '/../tests/Fixtures/ClickUp/';
const BUDGET = 60;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/pk_|token|bearer/i', $argument) === 1) {
        fwrite(\STDERR, "Refused: pass the token in CLICKUP_RECORD_TOKEN, never as an argument.\n");
        exit(2);
    }
}
$token = getenv('CLICKUP_RECORD_TOKEN');
if (!is_string($token) || $token === '') {
    fwrite(\STDERR, "Set CLICKUP_RECORD_TOKEN (a personal token of a throwaway workspace).\n");
    exit(2);
}

$client   = HttpClient::create(['timeout' => 10, 'max_duration' => 30, 'max_redirects' => 0]);
$requests = 0;
$get      = static function (string $path, array $query = []) use ($client, $token, &$requests): array {
    if (++$requests > BUDGET) {
        throw new RuntimeException('Request budget exhausted.');
    }
    $response = $client->request('GET', BASE . $path, ['query' => $query, 'headers' => ['Authorization' => $token, 'Accept' => 'application/json']]);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(sprintf('GET %s answered %d.', preg_replace('/\d{5,}/', '<id>', $path), $response->getStatusCode()));
    }

    return $response->toArray();
};

/** Stable placeholders: the n-th distinct value of a kind gets "<Kind> n". */
$names = [];
$ids   = [];
$alias = static function (string $kind, string $value) use (&$names): string {
    $names[$kind] ??= [];
    $names[$kind][$value] ??= count($names[$kind]) + 1;

    return $names[$kind][$value];
};
$fakeId = static function (string $id) use (&$ids): string {
    if (preg_match('/^\d{7,}$/', $id) !== 1) {
        return $id;
    }
    $ids[$id] ??= (string) (9_000_000 + count($ids) + 1);

    return $ids[$id];
};

$redact = static function (mixed $value, string $key = '', string $kind = '') use (&$redact, $alias, $fakeId, $token): mixed {
    if (is_array($value)) {
        $out  = [];
        $kind = match (true) {
            isset($value['email']) || isset($value['username']) => 'user',
            default                                             => $kind,
        };
        foreach ($value as $k => $v) {
            $out[$k] = $redact($v, (string) $k, $kind);
        }

        return $out;
    }
    if (!is_string($value) && !is_int($value)) {
        return $value;
    }
    $value = (string) $value;
    $value = str_replace($token, 'pk_REDACTED', $value);
    $value = (string) preg_replace('/\bpk_[A-Za-z0-9_]{8,}/', 'pk_REDACTED', $value);
    $value = (string) preg_replace('/\bBearer\s+\S+/i', 'Bearer REDACTED', $value);
    if (in_array($key, ['profilePicture', 'avatar', 'profile_picture'], true)) {
        return null;
    }
    if ($key === 'email') {
        return 'user' . $alias('user', $value) . '@example.test';
    }
    if (in_array($key, ['username', 'initials'], true) || ($kind === 'user' && $key === 'name')) {
        return $key === 'initials' ? 'U' . $alias('user', $value) : 'User ' . $alias('user', $value);
    }
    if ($key === 'id' || str_ends_with($key, '_id') || $key === 'parent' || $key === 'depends_on') {
        return $fakeId($value);
    }
    // Every URL: no query (signatures), ids replaced, attachment hosts collapsed to the fixture host.
    $value = (string) preg_replace_callback('#https?://[^\s)"\']+#', static function (array $m) use ($fakeId): string {
        $url  = strtok($m[0], '?#');
        $host = (string) parse_url($url, \PHP_URL_HOST);
        $path = (string) preg_replace_callback('/\d{5,}/', static fn (array $d): string => $fakeId($d[0]), (string) parse_url($url, \PHP_URL_PATH));
        if (str_contains($host, 'clickup-attachments.com') || str_starts_with($host, 'attachments')) {
            return 'https://t000.p.clickup-attachments.com/fixture' . preg_replace('#^/[^/]+#', '', $path);
        }
        if (str_ends_with($host, 'clickup.com')) {
            return 'https://' . $host . $path;
        }

        return 'https://example.test' . $path;
    }, $value);

    // Free text that may carry an e-mail.
    return (string) preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', static fn (array $m): string => str_ends_with($m[0], '@example.test') ? $m[0] : 'user' . $alias('user', $m[0]) . '@example.test', $value);
};

$names2 = static function (array $data, string $listKey, string $kind) use ($alias): array {
    foreach ($data[$listKey] ?? [] as $i => $item) {
        if (isset($item['name'])) {
            $data[$listKey][$i]['name'] = ucfirst($kind) . ' ' . $alias($kind, (string) $item['name']);
        }
    }

    return $data;
};

$write = static function (string $file, array $data) use ($redact): void {
    file_put_contents(OUT . $file, json_encode($redact($data), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    echo $file, "\n";
};

$teams = $names2($get('team'), 'teams', 'workspace');
$write('team.json', $teams);
$teamId = (string) ($get('team')['teams'][0]['id'] ?? '');
$spaces = $get('team/' . $teamId . '/space', ['archived' => 'false']);
$write('team_' . $fakeId($teamId) . '_space.json', $names2($spaces, 'spaces', 'space'));
$spaceId = (string) ($spaces['spaces'][0]['id'] ?? '');
$folders = $get('space/' . $spaceId . '/folder', ['archived' => 'false']);
$write('space_' . $fakeId($spaceId) . '_folder.json', $names2($folders, 'folders', 'folder'));
$write('space_' . $fakeId($spaceId) . '_list.json', $names2($get('space/' . $spaceId . '/list', ['archived' => 'false']), 'lists', 'list'));
$folderId = (string) ($folders['folders'][0]['id'] ?? '');
$lists    = $folderId === '' ? ['lists' => []] : $get('folder/' . $folderId . '/list', ['archived' => 'false']);
if ($folderId !== '') {
    $write('folder_' . $fakeId($folderId) . '_list.json', $names2($lists, 'lists', 'list'));
}
$listId = (string) (getenv('CLICKUP_RECORD_LIST') ?: ($lists['lists'][0]['id'] ?? ''));
if ($listId === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $listId) !== 1) {
    fwrite(\STDERR, "No list to record (set CLICKUP_RECORD_LIST).\n");
    exit(1);
}
$list         = $get('list/' . $listId);
$list['name'] = 'List ' . $alias('list', (string) $list['name']);
foreach (['folder', 'space'] as $parent) {
    if (isset($list[$parent]['name'])) {
        $list[$parent]['name'] = ucfirst($parent) . ' ' . $alias($parent, (string) $list[$parent]['name']);
    }
}
$write('list_' . $fakeId($listId) . '.json', $list);
$taskQuery = ['subtasks' => 'true', 'include_closed' => 'true', 'include_markdown_description' => 'true', 'order_by' => 'updated'];
$tasks     = $get('list/' . $listId . '/task', ['page' => 0, ...$taskQuery, 'reverse' => 'true']);
$write('list_' . $fakeId($listId) . '_task_page0.json', $tasks);
// Q-O4: the same page with reverse=false (which order is oldest-first), and the second page (last_page, page size).
$write('list_' . $fakeId($listId) . '_task_page0_reverse_false.json', $get('list/' . $listId . '/task', ['page' => 0, ...$taskQuery, 'reverse' => 'false']));
$write('list_' . $fakeId($listId) . '_task_page1.json', $get('list/' . $listId . '/task', ['page' => 1, ...$taskQuery, 'reverse' => 'true']));
$taskId = (string) (getenv('CLICKUP_RECORD_TASK') ?: ($tasks['tasks'][0]['id'] ?? ''));
if ($taskId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $taskId) === 1) {
    // Q-O4: one task in full (are attachments in the list answer, and with which fields?).
    $write('task_' . $fakeId($taskId) . '.json', $get('task/' . $taskId, ['include_markdown_description' => 'true']));
    $comments = $get('task/' . $taskId . '/comment');
    $write('task_' . $fakeId($taskId) . '_comment.json', $comments);
    // Q-O4: the next comment page from the oldest comment of the first one (start = its date, start_id = its id).
    $oldest = $comments['comments'][count($comments['comments'] ?? []) - 1] ?? null;
    if (count($comments['comments'] ?? []) >= 25 && is_array($oldest)) {
        $write('task_' . $fakeId($taskId) . '_comment_page2.json', $get('task/' . $taskId . '/comment', ['start' => (string) ($oldest['date'] ?? ''), 'start_id' => (string) ($oldest['id'] ?? '')]));
    }
}
fwrite(\STDERR, sprintf("%d requests. Review the files, run the ScrubbedFixturesTest, then mark tests/Fixtures/ClickUp/README.md as RECORDED.\n", $requests));
