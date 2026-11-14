<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.19.0 (audit PW-H1): with folders restricted to admins (`security.create_roles: [ROLE_ADMIN]`), a user who may
 * manage his own board cannot put it in a folder: joining one needs the folder right of its tenant too.
 */
final class FolderJoinRightTest extends CopyTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['security' => ['create_roles' => ['ROLE_ADMIN']]];
        parent::setUp();
    }

    public function testJoiningAFolderNeedsTheFolderRight(): void
    {
        $admin    = $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $intruder = $this->user('intruder@example.com');
        $own      = $this->board('Mine', ['A', 'B'], creator: $intruder, tenantKey: 'acme');

        $this->signIn($admin);
        $folder = $this->restructurer()->createFolder($admin, 'acme', 'Finance folder');

        $this->signIn($intruder);
        $policy = self::getContainer()->get(TaskBoardRestructurePolicyInterface::class);
        self::assertInstanceOf(TaskBoardRestructurePolicyInterface::class, $policy);
        self::assertFalse($policy->mayManageFolders($intruder, 'acme'));
        try {
            $this->restructurer()->moveBoardToFolder($intruder, $own->getId(), $folder->id);
            self::fail('no folder right');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::NOT_ALLOWED, $refused->reason);
        }
        $this->clear();
        self::assertNull($this->em->find(TaskBoard::class, $own->getId())?->getFolderId());

        // The admin may, and may take it out again.
        $this->signIn($admin);
        $this->restructurer()->moveBoardToFolder($admin, $own->getId(), $folder->id);
        $this->restructurer()->moveBoardToFolder($admin, $own->getId(), null);
        $this->clear();
        self::assertNull($this->em->find(TaskBoard::class, $own->getId())?->getFolderId());
    }

    private function signIn(object $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }
}
