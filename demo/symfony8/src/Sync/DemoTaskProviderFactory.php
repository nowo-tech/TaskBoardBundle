<?php

declare(strict_types=1);

namespace App\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

/**
 * Demo: the bundle's in-memory provider registered as "clickup", seeded with a small workspace — the connect, browse,
 * refresh and unlink flow works without a ClickUp account or network.
 */
final class DemoTaskProviderFactory
{
    public static function create(): InMemoryTaskProvider
    {
        $provider = new InMemoryTaskProvider('clickup', DemoCredentialResolver::SECRET);
        $provider
            ->addContainer(new RemoteContainer('w:1', 'Acme workspace', ContainerType::Workspace, null, false, true))
            ->addContainer(new RemoteContainer('s:1', 'Product', ContainerType::Space, 'w:1', false, true))
            ->addContainer(new RemoteContainer('f:1', 'Roadmap', ContainerType::Folder, 's:1', false, true))
            ->addContainer(new RemoteContainer('L-backlog', 'Product backlog', ContainerType::List, 'f:1', true, false, 4, ['Product', 'Roadmap']))
            ->addContainer(new RemoteContainer('L-bugs', 'Bugs', ContainerType::List, 's:1', true, false, 0, ['Product']));

        $at   = static fn (int $minutes): DateTimeImmutable => new DateTimeImmutable('2026-11-01 09:00:00 +' . $minutes . ' minutes');
        $task = static fn (string $id, string $title, string $status, int $updated, array $extra = []): RemoteTask => new RemoteTask(
            new ImportedTaskDto(...['title' => $title, 'externalId' => $id, 'status' => $status, 'priority' => TaskPriority::Normal, 'hasPriority' => false, 'sourceUrl' => 'https://app.clickup.com/t/' . $id] + $extra),
            $at($updated),
        );
        foreach ([
            $task('cu-1', 'Design the onboarding', 'To do', 1, ['description' => "Screens for the **first run**.\n\nSee [the brief](https://example.com/brief).", 'tags' => ['ux']]),
            $task('cu-2', 'Build the signup API', 'Doing', 2, ['dependsOn' => [['cu-1', TaskDependencyType::BlockedBy]], 'priority' => TaskPriority::High, 'hasPriority' => true]),
            $task('cu-3', 'Write the API tests', 'To do', 3, ['parentExternalId' => 'cu-2']),
            $task('cu-4', 'Release notes', 'Done', 4),
        ] as $remote) {
            $provider->putTask('L-backlog', $remote);
        }
        $provider->setComments('cu-1', [new RemoteComment('c-1', 'Mock-ups are in the shared folder.', null, 'Ada', $at(5))]);

        return $provider;
    }
}
