<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Spec 213 S5: files through the host's upload checks (FR-014), time through the host's time store, never deleted.
 */
final class ApiFilesAndTimeTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private Task $own;

    private Task $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $colleague            = $this->user('colleague@example.com');
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->own            = $this->task($this->roadmap, 'Own');
        $this->theirs         = new Task(board: $this->roadmap, title: 'Theirs', creator: $colleague);
        $this->em->persist($this->theirs);
        $this->em->flush();
        $this->issueToken();
    }

    public function testAFileGoesToTheHostsStore(): void
    {
        $this->upload($this->own, 'notes.txt', 'hello');

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame(['file-1', 'notes.txt', 'txt', '/files/file-1'], [$json['id'], $json['title'], $json['extension'], $json['url']]);
        self::assertSame([[$this->own->getId(), 'notes.txt', 'hello']], ApiTestHost::$stored);
    }

    public function testExtraFormFieldsAreWarnedAbout(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-api');
        file_put_contents($path, 'x');
        $this->client->request('POST', '/api/tasks/v2/task/' . $this->own->getId() . '/attachment', ['filename' => 'renamed.txt', 'other' => '1'], ['attachment' => new UploadedFile($path, 'x.txt', null, null, true)], ['HTTP_AUTHORIZATION' => self::TOKEN]);

        self::assertSame([['field' => 'filename', 'reason' => 'not_supported'], ['field' => 'other', 'reason' => 'unknown']], $this->json()['warnings']);
    }

    public function testAFileRefusedByTheHostOrMissingIsRefused(): void
    {
        ApiTestHost::$refuseUploads = true;
        $this->upload($this->own, 'big.bin', 'x');
        $this->assertRefused(403, 'FILE_002');

        $this->client->request('POST', '/api/tasks/v2/task/' . $this->own->getId() . '/attachment', [], [], ['HTTP_AUTHORIZATION' => self::TOKEN]);
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testFilesNeedTheTasksLevel(): void
    {
        $this->issueToken(TaskApiLevel::MyTasks);
        $this->upload($this->own, 'a.txt', 'a');
        $this->assertRefused(403, 'TOKEN_001');
        $this->upload($this->theirs, 'a.txt', 'a');
        $this->assertRefused(404, 'ACCESS_001');
        self::assertSame([], ApiTestHost::$stored);
    }

    public function testAnUploadIsOneRequestForIdempotency(): void
    {
        $this->upload($this->own, 'a.txt', 'first', 'up-1');
        $this->upload($this->own, 'a.txt', 'first', 'up-1');
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        $this->upload($this->own, 'a.txt', 'other bytes', 'up-1');
        $this->assertRefused(409, 'IDEMP_002');
        self::assertCount(1, ApiTestHost::$stored);

        // The same bytes under another name or type are another request (audit IA-L7, the auditor's proof turned).
        $this->upload($this->own, 'b.txt', 'first', 'up-1');
        $this->assertRefused(409, 'IDEMP_002');
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-api');
        file_put_contents($path, 'first');
        $this->client->request('POST', '/api/tasks/v2/task/' . $this->own->getId() . '/attachment', [], ['attachment' => new UploadedFile($path, 'a.txt', 'text/plain', null, true)], ['HTTP_AUTHORIZATION' => self::TOKEN, 'HTTP_IDEMPOTENCY_KEY' => 'up-1']);
        $this->assertRefused(409, 'IDEMP_002');
        self::assertCount(1, ApiTestHost::$stored);

        // A nested file counts too.
        foreach (['one', 'two'] as $index => $bytes) {
            $path = (string) tempnam(sys_get_temp_dir(), 'tb-api');
            file_put_contents($path, $bytes);
            $main = (string) tempnam(sys_get_temp_dir(), 'tb-api');
            file_put_contents($main, 'same');
            $this->client->request('POST', '/api/tasks/v2/task/' . $this->own->getId() . '/attachment', [], [
                'attachment' => new UploadedFile($main, 'm.txt', null, null, true),
                'extra'      => ['nested' => new UploadedFile($path, 'n.txt', null, null, true)],
            ], ['HTTP_AUTHORIZATION' => self::TOKEN, 'HTTP_IDEMPOTENCY_KEY' => 'up-2']);
            $index === 0 ? self::assertResponseIsSuccessful() : $this->assertRefused(409, 'IDEMP_002');
        }
    }

    public function testTimeIsLoggedForTheTokensOwnPerson(): void
    {
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $this->own->getId(), 'start' => 1790000000000, 'duration' => 1800000, 'description' => 'Review', 'billable' => true, 'assignee' => 'uuid-' . $this->owner->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['entry-1', $this->own->getId(), '1800000', 'Review'], [$this->json()['data']['id'], $this->json()['data']['task']['id'], $this->json()['data']['duration'], $this->json()['data']['description']]);
        // Since 2.12.0: `billable` is taken (no warning) and reaches the host's time store.
        self::assertArrayNotHasKey('warnings', $this->json());
        self::assertTrue(ApiTestHost::$logged[0][1]->billable);
        self::assertSame(1790000000, ApiTestHost::$logged[0][1]->start->getTimestamp());
        self::assertSame(1800, ApiTestHost::$logged[0][1]->durationSeconds);

        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['start' => 1790000000000, 'duration' => 60000]);
        self::assertResponseIsSuccessful('without a task, the host decides');
        self::assertNull(ApiTestHost::$logged[1][0]);
    }

    /** Since 2.12.0: a host time store without the billable capability refuses the change. */
    public function testAStoreWithoutTheBillableCapabilityRefusesTheChange(): void
    {
        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/entry-1', ['billable' => true]);
        $this->assertRefused(403, 'TIME_003');

        ApiTestHost::$planAllows = false;
        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/entry-1', ['billable' => true]);
        $this->assertRefused(403, 'PLAN_001');
    }

    public function testTimeWithoutATaskIsReplayedForAnyWhere(): void
    {
        $this->issueToken(where: TaskApiWhere::limitedTo([], [$this->roadmap->getId()]));
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['start' => 1790000000000, 'duration' => 60000], ['HTTP_IDEMPOTENCY_KEY' => 'time-1']);
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['start' => 1790000000000, 'duration' => 60000], ['HTTP_IDEMPOTENCY_KEY' => 'time-1']);

        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotency-Replayed'));
        self::assertCount(1, ApiTestHost::$logged);
    }

    public function testTimeRefusals(): void
    {
        foreach ([
            [['start' => 1790000000000], 400, 'INPUT_002'],
            [['duration' => 60000], 400, 'INPUT_002'],
            [['start' => 1790000000000, 'duration' => 59999], 400, 'INPUT_002'],
            [['start' => 1790000000000, 'duration' => 604800001], 400, 'INPUT_002'],
            [['start' => 1790000000000, 'duration' => 60000, 'description' => str_repeat('x', 513)], 400, 'INPUT_002'],
            [['start' => 1790000000000, 'duration' => 60000, 'assignee' => 'uuid-999'], 403, 'TOKEN_001'],
            [['start' => 1790000000000, 'duration' => 60000, 'tid' => 'nothing'], 404, 'ACCESS_001'],
        ] as [$body, $status, $ecode]) {
            $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', $body);
            $this->assertRefused($status, $ecode);
        }
        $this->send('POST', '/api/tasks/v2/team/team-9/time_entries', ['start' => 1790000000000, 'duration' => 60000]);
        $this->assertRefused(404, 'ACCESS_001');

        $this->issueToken(TaskApiLevel::Read);
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['start' => 1790000000000, 'duration' => 60000]);
        $this->assertRefused(403, 'TOKEN_001');
        $this->issueToken(TaskApiLevel::MyTasks);
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $this->theirs->getId(), 'start' => 1790000000000, 'duration' => 60000]);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $this->own->getId(), 'start' => 1790000000000, 'duration' => 60000]);
        self::assertResponseIsSuccessful('My tasks logs its time on its own task');

        ApiTestHost::$planAllows = false;
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['start' => 1790000000000, 'duration' => 60000]);
        $this->assertRefused(403, 'PLAN_001');
        self::assertCount(1, ApiTestHost::$logged);
    }

    public function testDeletingTimeIsAlwaysRefused(): void
    {
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            foreach (['entry-1', 'nothing'] as $entry) {
                $this->send('DELETE', '/api/tasks/v2/team/team-1/time_entries/' . $entry);
                $this->assertRefused(403, 'TIME_002');
            }
        }
    }

    private function upload(Task $task, string $name, string $content, ?string $key = null): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-api');
        file_put_contents($path, $content);
        $server = ['HTTP_AUTHORIZATION' => self::TOKEN] + ($key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]);
        $this->client->request('POST', '/api/tasks/v2/task/' . $task->getId() . '/attachment', [], ['attachment' => new UploadedFile($path, $name, null, null, true)], $server);
    }
}
