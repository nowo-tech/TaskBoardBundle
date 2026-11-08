<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Spec 213 SC-001, write half: every write endpoint × the four levels × own task, another's task, another project, a board
 * outside where, a removed member, a person who may only read, a revoked or expired token, the plan without the module.
 * Each request gets fresh tasks, so a write never depends on the one before. Owner decisions: Read writes nothing; My tasks
 * works only on its own tasks and their checklists.
 */
final class ApiWriteMatrixTest extends ApiTestCase
{
    /** What the endpoint asks of the level. */
    private const WORK = 'work';

    private const EDIT = 'edit';

    private const DELETE = 'delete';

    private const COMMENT = 'comment';

    private string $boardId;

    private string $sideBoardId;

    private string $colleagueEmail = 'colleague@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->user($this->colleagueEmail);
        $this->boardId     = $this->board('Roadmap', tenantKey: 'tenant-a')->getId();
        $this->sideBoardId = $this->board('Side', tenantKey: 'tenant-a')->getId();
    }

    public function testEachLevelWithinReach(): void
    {
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            foreach ($this->endpoints() as $name => [$kind, $others, $call]) {
                $call($this->fixtures());
                $expected = match (true) {
                    $level === TaskApiLevel::Read                                                           => [403, 'TOKEN_001'],
                    $level === TaskApiLevel::MyTasks && $others                                             => [404, 'ACCESS_001'],
                    $level === TaskApiLevel::MyTasks && !in_array($kind, [self::WORK, self::COMMENT], true) => [403, 'TOKEN_001'],
                    default                                                                                 => null,
                };
                $this->assertOutcome($expected, $level->value . ' ' . $name);
            }
        }
    }

    public function testAnotherProjectABoardOutsideWhereAndARemovedMemberAreNotFound(): void
    {
        $denied = false;
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$denied): void {
            if ($denied) {
                $event->deny();
            }
        });
        foreach (['another project' => TaskApiWhere::limitedTo(['tenant-b'], []), 'a board outside where' => TaskApiWhere::limitedTo([], [$this->sideBoardId]), 'a removed member' => null] as $case => $where) {
            $denied = $where === null;
            foreach (TaskApiLevel::cases() as $level) {
                $this->issueToken($level, $where ?? TaskApiWhere::everything());
                foreach ($this->endpoints() as $name => [, , $call]) {
                    $call($this->fixtures());
                    $this->assertOutcome([404, 'ACCESS_001'], sprintf('%s, %s, %s', $case, $level->value, $name));
                }
            }
        }
    }

    public function testAPersonWhoMayOnlyReadIsRefusedWhateverTheToken(): void
    {
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->grant();
                $event->markReadOnly();
            } else {
                $event->deny();
            }
        });
        $this->issueToken(TaskApiLevel::Full);
        foreach ($this->endpoints() as $name => [$kind, , $call]) {
            $call($this->fixtures());
            // Readers comment unless the host turns it off (comments.readers_can_comment).
            $this->assertOutcome($kind === self::COMMENT ? null : [403, 'ACCESS_002'], 'read-only ' . $name);
        }
    }

    public function testAPlanWithoutTheModuleAndARevokedToken(): void
    {
        ApiTestHost::$planRefusesTenants = ['tenant-a'];
        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            foreach ($this->endpoints() as $name => [, $others, $call]) {
                $call($this->fixtures());
                $this->assertOutcome($level === TaskApiLevel::MyTasks && $others ? [404, 'ACCESS_001'] : [403, 'PLAN_001'], 'plan ' . $level->value . ' ' . $name);
            }
        }
        ApiTestHost::$planRefusesTenants = [];
        foreach ($this->endpoints() as $name => [, , $call]) {
            $call($this->fixtures(), 'bt_revoked_placeholder');
            $this->assertOutcome([401, 'OAUTH_019'], 'revoked ' . $name);
        }
    }

    /**
     * name => [what it asks, whether it targets another person's task, the call].
     *
     * @return array<string, array{0: string, 1: bool, 2: callable(array<string, string>, string=): void}>
     */
    private function endpoints(): array
    {
        $send = fn (string $method, string $path, ?array $body = null): callable => fn (array $fx, string $token = self::TOKEN) => $this->send(
            $method,
            '/api/tasks/v2/' . strtr($path, $fx),
            $body === null ? null : array_map(static fn (mixed $value): mixed => is_string($value) ? strtr($value, $fx) : $value, $body),
            [],
            $token,
        );

        return [
            'create task'          => [self::EDIT, false, $send('POST', 'list/{board}/task', ['name' => 'New'])],
            'update own status'    => [self::WORK, false, $send('PUT', 'task/{own}', ['status' => 'Doing'])],
            'update own name'      => [self::EDIT, false, $send('PUT', 'task/{own}', ['name' => 'Renamed'])],
            'update others status' => [self::WORK, true, $send('PUT', 'task/{others}', ['status' => 'Doing'])],
            'delete own'           => [self::DELETE, false, $send('DELETE', 'task/{own}')],
            'comment own'          => [self::COMMENT, false, $send('POST', 'task/{own}/comment', ['comment_text' => 'Hi'])],
            'comment others'       => [self::COMMENT, true, $send('POST', 'task/{others}/comment', ['comment_text' => 'Hi'])],
            'tag own'              => [self::EDIT, false, $send('POST', 'task/{own}/tag/api')],
            'field own'            => [self::EDIT, false, $send('POST', 'task/{own}/field/f1', ['value' => 1])],
            'dependency own'       => [self::EDIT, false, $send('POST', 'task/{own}/dependency', ['depends_on' => '{blocker}'])],
            'link own'             => [self::EDIT, false, $send('POST', 'task/{own}/link/{blocker}')],
            'checklist own'        => [self::WORK, false, $send('POST', 'task/{own}/checklist', ['name' => 'Steps'])],
            'checklist others'     => [self::WORK, true, $send('POST', 'task/{others}/checklist', ['name' => 'Steps'])],
            'item create own'      => [self::WORK, false, $send('POST', 'checklist/{checklist}/checklist_item', ['name' => 'Item'])],
            'item update own'      => [self::WORK, false, $send('PUT', 'checklist/{checklist}/checklist_item/{item}', ['resolved' => true])],
            'item delete own'      => [self::WORK, false, $send('DELETE', 'checklist/{checklist}/checklist_item/{item}')],
            'checklist delete own' => [self::WORK, false, $send('DELETE', 'checklist/{checklist}')],
            'item update others'   => [self::WORK, true, $send('PUT', 'checklist/{otherChecklist}/checklist_item/{otherItem}', ['resolved' => true])],
        ];
    }

    /**
     * Fresh tasks for one request: the owner's, a colleague's, a blocker, and a checklist item on each task.
     *
     * @return array<string, string>
     */
    private function fixtures(): array
    {
        $this->em->clear();
        $board     = $this->em->find(TaskBoard::class, $this->boardId);
        $owner     = $this->em->getRepository(AppUser::class)->findOneBy(['email' => 'owner@example.com']);
        $colleague = $this->em->getRepository(AppUser::class)->findOneBy(['email' => $this->colleagueEmail]);
        self::assertInstanceOf(TaskBoard::class, $board);
        $own       = new Task(board: $board, title: 'Own', creator: $owner);
        $others    = new Task(board: $board, title: 'Others', creator: $colleague);
        $blocker   = new Task(board: $board, title: 'Blocker', creator: $owner);
        $item      = new Task(board: $board, title: 'Item', creator: $owner, parent: $own, tags: ['checklist:Steps']);
        $otherItem = new Task(board: $board, title: 'Their item', creator: $colleague, parent: $others, tags: ['checklist:Steps']);
        foreach ([$own, $others, $blocker, $item, $otherItem] as $task) {
            $this->em->persist($task);
        }
        $this->em->flush();

        return [
            '{board}'          => $this->boardId,
            '{own}'            => $own->getId(),
            '{others}'         => $others->getId(),
            '{blocker}'        => $blocker->getId(),
            '{checklist}'      => TaskApiReadSerializer::checklistId($own->getId(), 'Steps'),
            '{item}'           => $item->getId(),
            '{otherChecklist}' => TaskApiReadSerializer::checklistId($others->getId(), 'Steps'),
            '{otherItem}'      => $otherItem->getId(),
        ];
    }

    /**
     * @param array{0: int, 1: string}|null $expected null: the write went through (custom fields answer their own 409)
     */
    private function assertOutcome(?array $expected, string $message): void
    {
        $status = $this->client->getResponse()->getStatusCode();
        $ecode  = $this->json()['ECODE'] ?? null;
        if ($expected === null) {
            self::assertTrue($status === 200 || ($status === 409 && $ecode === 'FIELD_001'), sprintf('%s: %d %s', $message, $status, (string) $ecode));

            return;
        }
        self::assertSame($expected, [$status, $ecode], $message);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
