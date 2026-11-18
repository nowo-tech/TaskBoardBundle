<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TaskBoardBundle\Api\Security\TaskApiCommitWatermark;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 2.22.0 (audit SU-L1): with transaction-doctrine's commit detection on, the API's commit watermark reads that
 * bundle's ledger; without it, the bundle's own.
 */
final class CommitLedgerPassTest extends TestCase
{
    public function testTheWatermarkReadsTransactionDoctrinesLedgerWhenThere(): void
    {
        $without = $this->container(false);
        (new TaskBoardPortsPass())->process($without);
        self::assertSame('nowo_task_board.api.commit_ledger', (string) $without->getDefinition(TaskApiCommitWatermark::class)->getArgument(0));

        $with = $this->container(true);
        (new TaskBoardPortsPass())->process($with);
        self::assertSame(TaskBoardPortsPass::TRANSACTION_DOCTRINE_LEDGER, (string) $with->getDefinition(TaskApiCommitWatermark::class)->getArgument(0));
        // Without the API there is nothing to point.
        (new TaskBoardPortsPass())->process(new ContainerBuilder());
    }

    private function container(bool $transactionDoctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('nowo_task_board.api.commit_ledger', new Definition(CommitLedger::class));
        $container->setDefinition(TaskApiCommitWatermark::class, new Definition(TaskApiCommitWatermark::class, [new Reference('nowo_task_board.api.commit_ledger')]));
        if ($transactionDoctrine) {
            $container->setDefinition(TaskBoardPortsPass::TRANSACTION_DOCTRINE_LEDGER, new Definition(CommitLedger::class));
        }

        return $container;
    }
}
