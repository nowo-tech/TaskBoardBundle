<?php

declare(strict_types=1);

namespace App\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteComment;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
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
            ->addContainer(new RemoteContainer('L-bugs', 'Bugs', ContainerType::List, 's:1', true, false, 2, ['Product']))
            // Since 3.2.0: a second space and a few more lists for the guided import (Import from ClickUp).
            ->addContainer(new RemoteContainer('s:2', 'Operations', ContainerType::Space, 'w:1', false, true))
            ->addContainer(new RemoteContainer('L-support', 'Support queue', ContainerType::List, 's:2', true, false, 2, ['Operations']))
            ->addContainer(new RemoteContainer('L-infra', 'Infrastructure', ContainerType::List, 's:2', true, false, 1, ['Operations']))
            ->withWorkspace('1', [
                new RemotePerson('101', 'Ada Example', 'ada@example.test', RemoteRole::Admin),
                new RemotePerson('102', 'Grace Sample', 'grace@example.test', RemoteRole::Member),
            ], new TimeAccess(TimeAccessMode::Admin, '101'))
            ->withStatuses('L-backlog', [new RemoteStatus('To do', RemoteStatusType::Open, '#94a3b8'), new RemoteStatus('Doing', RemoteStatusType::Custom, '#3b82f6'), new RemoteStatus('Review', RemoteStatusType::Custom, '#a855f7'), new RemoteStatus('Done', RemoteStatusType::Closed, '#22c55e')])
            ->withStatuses('L-bugs', [new RemoteStatus('Open', RemoteStatusType::Open), new RemoteStatus('Fixed', RemoteStatusType::Closed)])
            ->withStatuses('L-support', [new RemoteStatus('Open', RemoteStatusType::Open), new RemoteStatus('Fixed', RemoteStatusType::Closed)])
            // Since 3.3.0 (CU-3): a date field too, which cannot be a native field (the Fields step says "synced only").
            ->withFieldDefinitions('L-backlog', [new RemoteCustomField('cf-size', 'T-shirt size', SyncFieldType::Dropdown, 'drop_down'), new RemoteCustomField('cf-team', 'Team', SyncFieldType::Text, 'short_text'), new RemoteCustomField('cf-points', 'Story points', SyncFieldType::Number, 'number'), new RemoteCustomField('cf-launch', 'Launch date', SyncFieldType::Date, 'date')])
            ->withFieldDefinitions('L-support', [new RemoteCustomField('cf-customer', 'Customer', SyncFieldType::Text, 'short_text')]);

        $at    = static fn (int $minutes): DateTimeImmutable => new DateTimeImmutable('2026-11-01 09:00:00 +' . $minutes . ' minutes');
        $sizes = [['id' => 'opt-s', 'name' => 'S', 'color' => '#22c55e'], ['id' => 'opt-m', 'name' => 'M', 'color' => '#f59e0b'], ['id' => 'opt-l', 'name' => 'L', 'color' => '#ef4444']];
        // Since 3.3.0 (CU-3): the backlog tasks carry custom field values (the guided import can land them on native fields).
        $fields = static fn (string $size, string $team, string $points): array => [
            new RemoteCustomField('cf-size', 'T-shirt size', SyncFieldType::Dropdown, 'drop_down', $sizes, [], $size, $size),
            new RemoteCustomField('cf-team', 'Team', SyncFieldType::Text, 'short_text', [], [], $team, $team),
            new RemoteCustomField('cf-points', 'Story points', SyncFieldType::Number, 'number', [], [], $points, $points),
            new RemoteCustomField('cf-launch', 'Launch date', SyncFieldType::Date, 'date', [], [], '2026-12-01', '2026-12-01'),
        ];
        $task = static fn (string $id, string $title, string $status, int $updated, array $extra = [], array $custom = []): RemoteTask => new RemoteTask(
            new ImportedTaskDto(...['title' => $title, 'externalId' => $id, 'status' => $status, 'priority' => TaskPriority::Normal, 'hasPriority' => false, 'sourceUrl' => 'https://app.clickup.com/t/' . $id] + $extra),
            $at($updated),
            customFields: $custom,
        );
        foreach ([
            $task('cu-1', 'Design the onboarding', 'To do', 1, ['description' => "Screens for the **first run**.\n\nSee [the brief](https://example.com/brief).", 'tags' => ['ux']], $fields('opt-m', 'Design', '3')),
            $task('cu-2', 'Build the signup API', 'Doing', 2, ['dependsOn' => [['cu-1', TaskDependencyType::BlockedBy]], 'priority' => TaskPriority::High, 'hasPriority' => true], $fields('opt-l', 'Platform', '8')),
            $task('cu-3', 'Write the API tests', 'To do', 3, ['parentExternalId' => 'cu-2']),
            $task('cu-4', 'Release notes', 'Done', 4),
        ] as $remote) {
            $provider->putTask('L-backlog', $remote);
        }
        foreach ([
            ['L-bugs', $task('cu-10', 'Login button misaligned on phones', 'Open', 10)],
            ['L-bugs', $task('cu-11', 'Export drops the last row', 'Fixed', 11)],
            ['L-support', $task('cu-20', 'Customer asks for an invoice copy', 'Open', 20)],
            ['L-support', $task('cu-21', 'Reset the staging password', 'Fixed', 21)],
            ['L-infra', $task('cu-30', 'Rotate the TLS certificates', 'To do', 30)],
        ] as [$list, $remote]) {
            $provider->putTask($list, $remote);
        }
        $provider->setComments('cu-1', [new RemoteComment('c-1', 'Mock-ups are in the shared folder.', null, 'Ada', $at(5))]);

        return $provider;
    }
}
