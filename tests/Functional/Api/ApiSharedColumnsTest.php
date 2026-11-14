<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function array_reverse;

/**
 * Since 2.19.0 (audit PW-H1): through the Task API, a column edit on the caller's own list is refused (403 `ACCESS_002`)
 * when the list's folder shares its statuses with a list the caller may not manage; nothing changes on either list.
 */
final class ApiSharedColumnsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        // Folders are the admins' (the default lets anyone who may create a board manage folders).
        static::$hostConfig = ['security' => ['create_roles' => ['ROLE_ADMIN']]];
        parent::setUp();
    }

    public function testAColumnEditOfASharingFolderNeedsTheRightOnEveryList(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $admin                = $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $member               = $this->user('member@example.com');
        $mine                 = $this->board('Mine', ['Todo', 'Doing', 'Done'], creator: $member, tenantKey: 'tenant-a');
        $theirs               = $this->board('Finance', ['Todo', 'Doing', 'Done'], tenantKey: 'tenant-a');
        $tokens               = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
        $restructure = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $restructure);
        $folder = $restructure->createFolder($admin, 'tenant-a', 'Shared');
        $restructure->moveBoardToFolder($admin, $mine->getId(), $folder->id);
        $restructure->moveBoardToFolder($admin, $theirs->getId(), $folder->id);
        $sets = self::getContainer()->get(FolderStatusSets::class);
        self::assertInstanceOf(FolderStatusSets::class, $sets);
        $sets->shareFrom($admin, $folder->id, $mine->getId());
        $tokens->setToken(null);
        $this->issueToken(email: 'member@example.com', token: 'bt_member');
        $column = ColumnTypes::ordered($mine)[1];

        $this->send('PUT', '/api/tasks/v2/list/' . $mine->getId() . '/status/' . $column->getId(), ['status' => 'Pwned'], [], 'bt_member');
        $this->assertRefused(403, 'ACCESS_002');
        $this->send('DELETE', '/api/tasks/v2/list/' . $mine->getId() . '/status/' . $column->getId(), null, [], 'bt_member');
        $this->assertRefused(403, 'ACCESS_002');
        $this->send('POST', '/api/tasks/v2/list/' . $mine->getId() . '/status', ['status' => 'Extra'], [], 'bt_member');
        $this->assertRefused(403, 'ACCESS_002');
        $order = array_map(static fn (BoardColumn $c): string => $c->getId(), array_reverse(ColumnTypes::ordered($mine)));
        $this->send('PUT', '/api/tasks/v2/list/' . $mine->getId() . '/status/order', ['order' => $order], [], 'bt_member');
        $this->assertRefused(403, 'ACCESS_002');

        $this->em->clear();
        foreach ([$mine, $theirs] as $board) {
            $fresh = $this->em->find(TaskBoard::class, $board->getId());
            self::assertInstanceOf(TaskBoard::class, $fresh);
            self::assertSame(['Todo', 'Doing', 'Done'], array_map(static fn (BoardColumn $c): string => $c->getName(), ColumnTypes::ordered($fresh)));
        }
    }
}
