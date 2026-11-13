<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_column;

/**
 * Since 2.17.0 (Beacon spec 234, FR-031): folders on the Task API — `GET/POST /v2/space/{id}/folder`,
 * `GET/PUT/DELETE /v2/folder/{id}`, `GET/POST /v2/folder/{id}/list`.
 */
final class ApiFoldersTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
        // The service calls of the setup run as the owner (the default policy asks the signed-in user's roles).
        $tokens = $this->service('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testFolderLifecycle(): void
    {
        $this->get('/api/tasks/v2/space/space-tenant-a/folder');
        self::assertSame(['folders' => []], $this->json());

        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/folder', ['name' => ' Epic ', 'markdown_content' => '## Goal']);
        self::assertResponseIsSuccessful();
        $folder = $this->json();
        self::assertSame(['Epic', '## Goal', 0, []], [$folder['name'], $folder['content'], $folder['orderindex'], $folder['lists']]);
        self::assertSame('space-tenant-a', $folder['space']['id']);
        $id = (string) $folder['id'];
        self::assertSame('tenant-a', $this->em->find(TaskBoardFolder::class, $id)?->getTenantKey());

        $this->send('POST', '/api/tasks/v2/folder/' . $id . '/list', ['name' => 'Story 1', 'content' => 'Story body']);
        self::assertResponseIsSuccessful();
        $list = $this->json();
        self::assertSame(['id' => $id, 'name' => 'Epic', 'hidden' => false, 'access' => true], $list['folder']);

        $this->get('/api/tasks/v2/folder/' . $id . '/list');
        self::assertSame(['Story 1'], array_column($this->listOf('lists'), 'name'));
        $this->get('/api/tasks/v2/space/space-tenant-a/list');
        self::assertSame(['Roadmap'], array_column($this->listOf('lists'), 'name'), 'a list in a folder is not folderless');
        $this->get('/api/tasks/v2/space/space-tenant-a/folder');
        self::assertSame(['Story 1'], array_column($this->listOf('folders')[0]['lists'], 'name'));

        $this->send('PUT', '/api/tasks/v2/folder/' . $id, ['name' => 'Renamed', 'orderindex' => 3]);
        self::assertSame(['Renamed', '## Goal', 3], [$this->json()['name'], $this->json()['content'], $this->json()['orderindex']]);

        $this->send('DELETE', '/api/tasks/v2/folder/' . $id);
        $this->assertRefused(409, 'FOLDER_001');

        $this->service(TaskBoardRestructureInterface::class)->moveBoardToFolder($this->owner, (string) $list['id'], null);
        $this->send('DELETE', '/api/tasks/v2/folder/' . $id);
        self::assertResponseIsSuccessful();
        $this->get('/api/tasks/v2/folder/' . $id);
        $this->assertRefused(404, 'ACCESS_001');

        $operations = array_map(static fn ($event): string => $event->operation, $this->audit()->events);
        self::assertSame(['folder.create', 'board.create', 'folder.list_create', 'folder.update', 'folder.delete'], $operations);
        self::assertSame('tenant-a', $this->audit()->events[0]->tenantKey);
    }

    public function testAnotherTenantsFolderIsNotFound(): void
    {
        $theirs = $this->service(TaskBoardRestructureInterface::class)->createFolder($this->owner, 'tenant-b', 'Theirs');
        $this->issueToken(where: TaskApiWhere::limitedTo(['tenant-a'], []));

        $this->get('/api/tasks/v2/folder/' . $theirs->id);
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/folder/' . $theirs->id . '/list');
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('PUT', '/api/tasks/v2/folder/' . $theirs->id, ['name' => 'Mine now']);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('DELETE', '/api/tasks/v2/folder/' . $theirs->id);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('POST', '/api/tasks/v2/folder/' . $theirs->id . '/list', ['name' => 'Sneaky']);
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/space/space-tenant-a/folder');
        self::assertSame([], $this->listOf('folders'));
        self::assertSame('Theirs', $this->em->find(TaskBoardFolder::class, $theirs->id)?->getName());
    }

    public function testATokenLimitedToABoardSeesOnlyItsFolderAndCannotWrite(): void
    {
        $service = $this->service(TaskBoardRestructureInterface::class);
        $folder  = $service->createFolder($this->owner, 'tenant-a', 'Epic');
        $empty   = $service->createFolder($this->owner, 'tenant-a', 'Empty');
        $service->moveBoardToFolder($this->owner, $this->roadmap->getId(), $folder->id);
        $this->issueToken(where: TaskApiWhere::limitedTo([], [$this->roadmap->getId()]));

        $this->get('/api/tasks/v2/space/space-tenant-a/folder');
        self::assertSame([$folder->id], $this->idsOf('folders'));
        $this->get('/api/tasks/v2/folder/' . $empty->id);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('PUT', '/api/tasks/v2/folder/' . $folder->id, ['name' => 'Renamed']);
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testWritesNeedFull(): void
    {
        $folder = $this->service(TaskBoardRestructureInterface::class)->createFolder($this->owner, 'tenant-a', 'Epic');
        $this->issueToken(TaskApiLevel::Tasks);
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/folder', ['name' => 'New']);
        $this->assertRefused(403, 'TOKEN_001');
        $this->send('PUT', '/api/tasks/v2/folder/' . $folder->id, ['name' => 'Renamed']);
        $this->assertRefused(403, 'TOKEN_001');
        $this->get('/api/tasks/v2/folder/' . $folder->id);
        self::assertResponseIsSuccessful();
    }

    public function testAnInvalidNameIsRefused(): void
    {
        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/folder', ['name' => '   ']);
        $this->assertRefused(400, 'INPUT_002');
    }

    private function audit(): ApiAuditRecorder
    {
        return $this->service('app.api_audit');
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    private function service(string $id): mixed
    {
        return self::getContainer()->get($id);
    }
}
