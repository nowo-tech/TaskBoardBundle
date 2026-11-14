<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Folder;

use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;

/**
 * Since 2.19.0: `moveTasks()` asks the host's column transition policy for every task that changes board (operation
 * `board_move`, the caller's channel), before anything changes, so a move cannot reach a column the policy keeps.
 */
final class RestructureTransitionPolicyTest extends CopyTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['column_transition_policy' => 'app.transition_policy', 'write_provenance_provider' => 'app.write_provenance'];
        PathWriteProvenance::reset();
        ScriptedTransitionPolicy::$asked   = [];
        ScriptedTransitionPolicy::$message = 'A blocked task needs a reason';
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testARefusedTaskMovesNothing(): void
    {
        $source = $this->board('Pilot', ['To do', 'Blocked'], tenantKey: 'acme');
        $target = $this->board('Story', ['To do', 'Blocked'], tenantKey: 'acme');
        $free   = $this->task($source, 'Free');
        $parent = $this->task($source, 'Parent');
        $this->task($source, 'Stuck', 1, $parent);
        $this->clear();

        try {
            $this->restructurer()->moveTasks($this->owner, [$free->getId(), $parent->getId()], $target->getId(), [], new ColumnTransitionVia(ColumnTransitionVia::MCP));
            self::fail('Blocked refuses');
        } catch (ColumnTransitionRefusedException $refused) {
            self::assertSame('BLOCK_REASON_REQUIRED', $refused->decision->code);
        }
        $asked = ScriptedTransitionPolicy::$asked;
        self::assertSame(['mcp', 'board_move'], [$asked[0]->via->channel, $asked[0]->via->operation]);
        self::assertSame($target->getId(), $asked[0]->board->id);
        self::assertSame(['To do', 'To do', 'Blocked'], array_map(static fn (ColumnTransition $transition): string => $transition->to->name, $asked), 'the subtask enters its column of the same name');
        self::assertSame('Blocked', $asked[2]->from?->name);
        self::assertTrue($asked[2]->to->last);
        $this->clear();
        self::assertSame($source->getId(), $this->reload($free)->getBoard()->getId(), 'nothing moved');
        self::assertTrue($this->em->isOpen());
    }

    public function testAllowedTasksMoveAndAWebCallerIsTheDefault(): void
    {
        $source = $this->board('Pilot', ['To do', 'Doing'], tenantKey: 'acme');
        $target = $this->board('Story', ['To do', 'Blocked'], tenantKey: 'acme');
        $task   = $this->task($source, 'Free', 1);
        $same   = $this->task($target, 'Already there');
        $story  = $this->task($target, 'Story');
        $child  = $this->task($target, 'Child', 1, $story);

        $result = $this->restructurer()->moveTasks($this->owner, [$task->getId(), $same->getId(), $child->getId()], $target->getId(), ['Doing' => 'To do']);

        self::assertSame([$task->getId(), $child->getId()], $result->movedTaskIds, 'the subtask becomes a top-level task of its own board');
        self::assertSame([$same->getId()], $result->unchangedTaskIds);
        self::assertCount(1, ScriptedTransitionPolicy::$asked, 'a task that stays on its board is not asked');
        self::assertSame(['web', 'board_move'], [ScriptedTransitionPolicy::$asked[0]->via->channel, ScriptedTransitionPolicy::$asked[0]->via->operation]);
    }

    public function testWithoutAViaTheWriteProvenanceChannelIsAsked(): void
    {
        $source                       = $this->board('Pilot', ['To do', 'Blocked'], tenantKey: 'acme');
        $target                       = $this->board('Story', ['To do', 'Blocked'], tenantKey: 'acme');
        $task                         = $this->task($source, 'Free');
        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::Mcp, 'Agent');

        $this->restructurer()->moveTasks($this->owner, [$task->getId()], $target->getId());

        self::assertSame(['mcp', 'board_move'], [ScriptedTransitionPolicy::$asked[0]->via->channel, ScriptedTransitionPolicy::$asked[0]->via->operation]);
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => TaskChangeType::BoardMoved]);
        self::assertSame(WriteChannel::Mcp, $row?->getChannel(), 'the board_moved row carries the provenance too');
        PathWriteProvenance::reset();
    }

    private function restructurer(): TaskBoardRestructureInterface
    {
        $service = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $service);

        return $service;
    }
}
