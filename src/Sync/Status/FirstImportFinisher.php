<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Status;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Throwable;

use function array_map;
use function in_array;
use function sort;
use function usort;

/**
 * Since 1.28.0 (TB-SYNC-4): at the end of the first import, the columns this connection created take their status's
 * colour and the provider's order among themselves (other columns keep their place), and the connection keeps the source's
 * description. Later syncs change nothing here: a colour or an order changed by hand stays. Nothing here can fail a
 * run that has imported everything.
 */
final readonly class FirstImportFinisher
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function finish(SyncRunContext $ctx): void
    {
        $created = $ctx->connection->createdColumns();
        if ($ctx->options->dryRun) {
            return;
        }
        $first = !$ctx->connection->getLastSuccessAt() instanceof DateTimeImmutable;
        try {
            if ($ctx->provider instanceof SourceProviderInterface) {
                if ($first) {
                    $source = $ctx->provider->findSource($ctx->credential, $ctx->connection->getLocation()->containerId());
                    $ctx->connection->setSourceDescription($source?->description);
                }
                if ($created !== []) {
                    $this->order($ctx, $created);
                }
            }
        } catch (Throwable) {
            $ctx->warn('source_details_unavailable');
        }
        $ctx->connection->clearCreatedColumns();
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $created
     */
    private function order(SyncRunContext $ctx, array $created): void
    {
        $statuses = $ctx->provider instanceof SourceProviderInterface ? $ctx->provider->listStatuses($ctx->credential, new RemoteSource($ctx->connection->getLocation(), $ctx->connection->getContainerLabel())) : [];
        $ranked   = [];
        $columns  = [];
        foreach ($ctx->board->getColumns() as $column) {
            if (!in_array($column->getId(), $created, true)) {
                continue;
            }
            foreach ($statuses as $rank => $status) {
                if (StatusColumns::normalize($status->name) === StatusColumns::normalize($column->getName())) {
                    if ($column->getColor() === null && $status->color !== null) {
                        $column->setColor($status->color);
                    }
                    $ranked[$column->getId()] = $rank;
                    $columns[]                = $column;
                    break;
                }
            }
        }
        $slots = array_map(static fn (BoardColumn $column): int => $column->getPosition(), $columns);
        sort($slots);
        usort($columns, static fn (BoardColumn $a, BoardColumn $b): int => $ranked[$a->getId()] <=> $ranked[$b->getId()]);
        foreach ($columns as $index => $column) {
            $column->setPosition($slots[$index]);
        }
    }
}
