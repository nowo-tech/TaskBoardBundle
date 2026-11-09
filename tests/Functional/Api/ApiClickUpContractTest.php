<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_diff;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function fnmatch;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;

use const FNM_NOESCAPE;

/**
 * Spec 213 SC-002: the same requests, compared with what ClickUp answers. ClickUp's side is the shape (field names and JSON
 * types, never values) of the response examples published in its API v2 reference pages, derived on 2026-11-08 into
 * tests/Fixtures/clickup/shapes.json. **These are published examples, not live recordings**: no ClickUp account was called.
 *
 * Every difference must be listed in {@see self::DIFFERENCES} with its reason, and named in docs/API.md (*Differences from
 * ClickUp*). An undocumented difference fails, and so does a listed one that no longer exists. A null on either side is not
 * compared: a field that is present but empty is not a difference of shape.
 */
final class ApiClickUpContractTest extends ApiTestCase
{
    /** The documented differences: `page: path: kind`. */
    public const DIFFERENCES = [
        // Ids and people.
        '*: *id: string, not number'          => 'Ids are strings: the bundle\'s UUIDs, the host\'s ids for people.',
        '*: *.creator.initials: extra'        => 'A creator has initials, as other people do.',
        '*: *.creator.email: extra'           => 'A creator has an email field (null unless the host shows it).',
        '*: *.profileInfo: missing'           => 'No profile details beyond name, email, colour and picture.',
        'getauthorizeduser: .user.*: missing' => 'No timezone, week start or font settings.',
        // Fields Beacon has no equivalent for.
        '*: *custom_item_id: missing'                          => 'No task types.',
        '*: *.locations: missing'                              => 'A task is on one board.',
        '*: *permission_level: missing'                        => 'Permissions are the host\'s; not reported per object.',
        '*: *.sharing: missing'                                => 'No public sharing.',
        '*: *.attachments: missing'                            => 'Files are the host\'s: not listed on the task.',
        '*: *.markdown_description: missing'                   => 'Given only with include_markdown_description.',
        '*: *.subtasks: missing'                               => 'Subtasks are given with include_subtasks=true only.',
        '*: *.tags[].creator: missing'                         => 'Tags have no creator.',
        '*: *.dependencies[].*: missing'                       => 'Dependencies have no chain, user or workspace id.',
        '*: *.linked_tasks[].userid: missing'                  => 'Links have no user id.',
        '*: *.custom_fields[].*: missing'                      => 'A task\'s custom field values carry id, name, type and value only.',
        'getaccessiblecustomfields: .fields[].*: missing'      => 'Imported fields carry the options the provider gave; no per-type config.',
        'getaccessiblecustomfields: .fields[].required: extra' => 'Imported fields say they are not required.',
        '*: *inbound_address: missing'                         => 'No email-in address for boards.',
        '*: .due_date_time: missing'                           => 'Boards have no dates.',
        '*: .start_date_time: missing'                         => 'Boards have no dates.',
        '*: *.date_created: missing'                           => 'Checklists and their items carry no creation date of their own.',
        // What this API adds.
        '*: *statuses[].id: extra'                     => 'Statuses are columns and have ids (used by the status paths).',
        '*: *statuses[].icon: extra'                   => 'Since 2.9.0: a status carries its column\'s icon (one emoji or an icon id), or null.',
        '*: *.status.icon: extra'                      => 'Since 2.9.0: a task\'s status carries its column\'s icon too.',
        '*: *.number: extra'                           => 'Since 2.10.0 (spec 214): a task carries its readable number (#123), or null without task numbers.',
        'getfolderlesslists: .lists[].statuses: extra' => 'Each list carries its statuses (columns).',
        '*: .archived: extra'                          => 'Boards say whether they are archived.',
        '*: .override_statuses: extra'                 => 'Every board has its own statuses.',
        '*tasklink: .task.*: extra'                    => 'A link answers the whole task, not ClickUp\'s short form.',
        // ClickUp's published examples disagree with each other or with its own schemas.
        'getfolderlesslists: .lists[].task_count: number, not string'      => 'ClickUp\'s schema says string; its list example and this API use a number.',
        'getfilteredteamtasks: .last_page: extra'                          => 'ClickUp\'s example of this page omits last_page, which its list page has.',
        'getfilteredteamtasks: .tasks[].*: extra'                          => 'ClickUp\'s example of this page is shorter than its list page\'s.',
        'getfilteredteamtasks: .tasks[].priority: object, not number'      => 'ClickUp\'s example of this page shows a number; its list page an object, as here.',
        'gettaskcomments: .comments[].reply_count: number, not string'     => 'A count is a number here.',
        'createatimeentry: *'                                              => 'ClickUp\'s published example for this page is the request; this API answers {"data": entry} as the list does.',
        'gettimeentrieswithinadaterange: .data[].*: missing'               => 'Entries carry the host\'s fields: no tags, source, location or task URL; the person is {id}.',
        'gettimeentrieswithinadaterange: .data[].*: string, not number'    => 'Start and end are millisecond strings, as every other date here.',
        'getspace: .*: extra'                                              => 'ClickUp\'s example of one space is shorter than its list of spaces; both are answered alike here.',
        '*: *.checklists[].creator: missing'                               => 'A checklist has no row of its own, so no creator.',
        '*: *.checklists[].orderindex: number, not string'                 => 'ClickUp\'s task examples show a string, its checklist examples a number, as here.',
        'getfilteredteamtasks: .tasks[].time_estimate: string, not number' => 'ClickUp\'s example of this page shows a number; its task examples a string, as here.',
        'setcustomfieldvalue: .: object, not list'                         => 'Custom fields are read-only: 409 FIELD_001 (owner).',
    ];

    private TaskBoard $board;

    private Task $task;

    private string $checklistId;

    private string $itemId;

    private string $relatedId;

    private TaskSyncField $field;

    private string $doomedTaskId;

    private string $doomedBoardId;

    protected function setUp(): void
    {
        static::$hostConfig = SyncFunctionalTestCase::SYNC_CONFIG;
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $colleague            = $this->user('colleague@example.com');
        $this->board          = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->task           = $this->task($this->board, 'Ship', 1);
        $this->task->setDescription('Text')->setPriority(TaskPriority::High)->setTags(['api'])->setDueAt(new DateTimeImmutable('@1790000000'))->setStartAt(new DateTimeImmutable('@1789000000'))->setEstimatedMinutes(60);
        $this->task->addMember(new TaskMember(task: $this->task, user: $colleague, memberRole: TaskMemberRole::Assignee));
        $this->task->addMember(new TaskMember(task: $this->task, user: $this->owner, memberRole: TaskMemberRole::Watcher));
        $item = new Task(board: $this->board, title: 'Item', creator: $this->owner, parent: $this->task, tags: ['checklist:Steps']);
        $item->addMember(new TaskMember(task: $item, user: $colleague, memberRole: TaskMemberRole::Assignee));
        $blocker = $this->task($this->board, 'Blocker');
        $related = $this->task($this->board, 'Related');
        $entry   = new TaskTimeEntry($this->task, $this->owner, 30, new DateTimeImmutable('2026-11-01'));
        $this->task->addTimeEntry($entry);
        $now         = new DateTimeImmutable('@1790000000');
        $this->field = new TaskSyncField($this->board, 'clickup', 'cf-1', $now);
        $this->field->define('Stage', SyncFieldType::Text, 'drop_down', [['id' => 'o1', 'name' => 'Alpha', 'color' => '#000000']], [], 0, $now);
        foreach ([$item, $entry, new TaskDependency($blocker, $this->task, TaskDependencyType::Blocks), new TaskDependency($this->task, $related, TaskDependencyType::Related), new TaskComment($this->task, $colleague, 'Looks good'), $this->field, new TaskSyncFieldValue($this->field, $this->task, 'o1', 'Alpha', $now)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->checklistId   = TaskApiReadSerializer::checklistId($this->task->getId(), 'Steps');
        $this->itemId        = $item->getId();
        $this->relatedId     = $related->getId();
        $this->doomedTaskId  = $this->task($this->board, 'Doomed')->getId();
        $this->doomedBoardId = $this->board('Doomed board', tenantKey: 'tenant-a')->getId();
        $this->issueToken();
    }

    public function testOnlyTheDocumentedDifferencesRemain(): void
    {
        $shapes = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/clickup/shapes.json'), true);
        self::assertIsArray($shapes);
        $found = [];
        foreach ($this->requests() as $page => $request) {
            $request();
            $status = $this->client->getResponse()->getStatusCode();
            self::assertTrue($status === 200 || $page === 'setcustomfieldvalue', sprintf('%s answered %d: %s', $page, $status, (string) $this->client->getResponse()->getContent()));
            $ours = json_decode((string) $this->client->getResponse()->getContent(), true);
            foreach ($this->compare($shapes[$page]['shape'], $this->shape($ours), '') as $difference) {
                $found[] = $page . ': ' . $difference;
            }
        }
        $undocumented = [];
        $used         = [];
        foreach ($found as $difference) {
            $matched = false;
            foreach (array_keys(self::DIFFERENCES) as $pattern) {
                if (fnmatch($pattern, $difference, FNM_NOESCAPE)) {
                    $used[$pattern] = true;
                    $matched        = true;
                }
            }
            if (!$matched) {
                $undocumented[] = $difference;
            }
        }

        self::assertSame([], $undocumented, "Undocumented differences:\n" . implode("\n", $undocumented));
        self::assertSame([], array_values(array_diff(array_keys(self::DIFFERENCES), array_keys($used))), 'Listed differences that no longer exist');
    }

    public function testEveryDifferenceIsNamedInTheDocs(): void
    {
        $docs = (string) file_get_contents(__DIR__ . '/../../../docs/API.md');
        foreach (self::DIFFERENCES as $difference => $reason) {
            self::assertStringContainsString('`' . $difference . '`', $docs, $reason);
        }
    }

    /**
     * Each ClickUp reference page with the request this API answers it with.
     *
     * @return array<string, callable(): void>
     */
    private function requests(): array
    {
        $task  = $this->task->getId();
        $board = $this->board->getId();
        $get   = fn (string $path): callable => fn () => $this->get('/api/tasks' . $path);
        $send  = fn (string $method, string $path, ?array $body = null): callable => fn () => $this->send($method, '/api/tasks' . $path, $body);

        return [
            'getauthorizeduser'              => $get('/v2/user'),
            'getauthorizedteams'             => $get('/v2/team'),
            'getspaces'                      => $get('/v2/team/team-1/space'),
            'getspace'                       => $get('/v2/space/space-tenant-a'),
            'getfolders'                     => $get('/v2/space/space-tenant-a/folder'),
            'getfolderlesslists'             => $get('/v2/space/space-tenant-a/list'),
            'getlist'                        => $get('/v2/list/' . $board),
            'gettasks'                       => $get('/v2/list/' . $board . '/task'),
            'gettask'                        => $get('/v2/task/' . $task),
            'getfilteredteamtasks'           => $get('/v2/team/team-1/task'),
            'gettaskcomments'                => $get('/v2/task/' . $task . '/comment'),
            'gettaskmembers'                 => $get('/v2/task/' . $task . '/member'),
            'getspacetags'                   => $get('/v2/space/space-tenant-a/tag'),
            'getaccessiblecustomfields'      => $get('/v2/list/' . $board . '/field'),
            'gettimeentrieswithinadaterange' => $get('/v2/team/team-1/time_entries'),
            'createatimeentry'               => $send('POST', '/v2/team/team-1/time_entries', ['tid' => $task, 'start' => 1790000000000, 'duration' => 60000]),
            'createtask'                     => $send('POST', '/v2/list/' . $board . '/task', ['name' => 'New']),
            'updatetask'                     => $send('PUT', '/v2/task/' . $task, ['name' => 'Renamed']),
            'createtaskcomment'              => $send('POST', '/v2/task/' . $task . '/comment', ['comment_text' => 'Hello']),
            'createchecklist'                => $send('POST', '/v2/task/' . $task . '/checklist', ['name' => 'Steps']),
            'createchecklistitem'            => $send('POST', '/v2/checklist/' . $this->checklistId . '/checklist_item', ['name' => 'Another']),
            'editchecklistitem'              => $send('PUT', '/v2/checklist/' . $this->checklistId . '/checklist_item/' . $this->itemId, ['resolved' => true]),
            'editchecklist'                  => $send('PUT', '/v2/checklist/' . $this->checklistId, ['name' => 'Steps']),
            'addtagtotask'                   => $send('POST', '/v2/task/' . $task . '/tag/extra'),
            'removetagfromtask'              => $send('DELETE', '/v2/task/' . $task . '/tag/extra'),
            'setcustomfieldvalue'            => $send('POST', '/v2/task/' . $task . '/field/' . $this->field->getId(), ['value' => 'o1']),
            'adddependency'                  => $send('POST', '/v2/task/' . $task . '/dependency', ['dependency_of' => $this->relatedId]),
            'deletedependency'               => fn () => $this->send('DELETE', '/api/tasks/v2/task/' . $task . '/dependency?dependency_of=' . $this->relatedId),
            'deletetasklink'                 => $send('DELETE', '/v2/task/' . $task . '/link/' . $this->relatedId),
            'addtasklink'                    => $send('POST', '/v2/task/' . $task . '/link/' . $this->relatedId),
            'createtaskattachment'           => function () use ($task): void {
                $path = (string) tempnam(sys_get_temp_dir(), 'tb-api');
                file_put_contents($path, 'x');
                $this->client->request('POST', '/api/tasks/v2/task/' . $task . '/attachment', [], ['attachment' => new UploadedFile($path, 'a.txt', null, null, true)], ['HTTP_AUTHORIZATION' => self::TOKEN]);
            },
            'createfolderlesslist' => $send('POST', '/v2/space/space-tenant-a/list', ['name' => 'Board']),
            'updatelist'           => $send('PUT', '/v2/list/' . $board, ['name' => 'Renamed board']),
            'deletechecklistitem'  => $send('DELETE', '/v2/checklist/' . $this->checklistId . '/checklist_item/' . $this->itemId),
            'deletechecklist'      => $send('DELETE', '/v2/checklist/' . $this->checklistId),
            'deletetask'           => $send('DELETE', '/v2/task/' . $this->doomedTaskId),
            'deletelist'           => $send('DELETE', '/v2/list/' . $this->doomedBoardId),
        ];
    }

    /**
     * Field names and JSON types, as the fixtures hold them.
     */
    private function shape(mixed $value): mixed
    {
        return match (true) {
            is_bool($value)                           => 'boolean',
            is_int($value), is_float($value)          => 'number',
            is_string($value)                         => 'string',
            $value === null                           => 'null',
            is_array($value) && array_is_list($value) => $value === [] ? [] : [$this->merged(array_map($this->shape(...), $value))],
            is_array($value)                          => $this->shapeOfObject($value),
            default                                   => 'null',
        };
    }

    /**
     * One shape for every item of a list: their fields together, a type wherever one item has a value. So the order of the
     * items (tasks made in the same second) cannot change the result.
     *
     * @param list<mixed> $shapes
     */
    private function merged(array $shapes): mixed
    {
        $merged = 'null';
        foreach ($shapes as $shape) {
            if ($merged === 'null') {
                $merged = $shape;
            } elseif (is_array($merged) && is_array($shape) && !array_is_list($merged) && !array_is_list($shape)) {
                foreach ($shape as $key => $inner) {
                    $merged[$key] = array_key_exists($key, $merged) ? $this->merged([$merged[$key], $inner]) : $inner;
                }
                ksort($merged);
            } elseif (is_array($merged) && is_array($shape) && $merged === [] && $shape !== []) {
                $merged = $shape;
            } elseif (is_array($merged) && is_array($shape) && array_is_list($merged) && array_is_list($shape) && $shape !== []) {
                $merged = [$this->merged([$merged[0], $shape[0]])];
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function shapeOfObject(array $value): array
    {
        $shape = [];
        foreach ($value as $key => $item) {
            $shape[$key] = $this->shape($item);
        }
        ksort($shape);

        return $shape;
    }

    /**
     * @return list<string> the differences under `$path`
     */
    private function compare(mixed $theirs, mixed $ours, string $path): array
    {
        if ($theirs === 'null' || $ours === 'null') {
            return [];
        }
        if (is_array($theirs) && is_array($ours)) {
            $theirsIsList = $theirs === [] || array_is_list($theirs);
            $oursIsList   = $ours === [] || array_is_list($ours);
            if ($theirsIsList && $oursIsList) {
                return $theirs === [] || $ours === [] ? [] : $this->compare($theirs[0], $ours[0], $path . '[]');
            }
            if (!$theirsIsList && !$oursIsList) {
                $differences = [];
                foreach ($theirs as $key => $shape) {
                    $differences = [...$differences, ...(array_key_exists($key, $ours) ? $this->compare($shape, $ours[$key], $path . '.' . $key) : [$path . '.' . $key . ': missing'])];
                }
                foreach ($ours as $key => $shape) {
                    if (!array_key_exists($key, $theirs)) {
                        $differences[] = $path . '.' . $key . ': extra';
                    }
                }

                return $differences;
            }
        }
        if ($theirs === $ours) {
            return [];
        }

        return [sprintf('%s: %s, not %s', $path === '' ? '.' : $path, $this->describe($ours), $this->describe($theirs))];
    }

    private function describe(mixed $shape): string
    {
        return is_string($shape) ? $shape : (is_array($shape) && ($shape === [] || array_is_list($shape)) ? 'list' : 'object');
    }
}
