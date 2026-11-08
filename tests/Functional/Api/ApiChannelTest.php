<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

use function array_map;

/**
 * Since 2.4.0 (spec 219): the host marks a principal's channel (`api` or `mcp`); the audit event carries it.
 */
final class ApiChannelTest extends ApiTestCase
{
    public function testTheAuditEventCarriesThePrincipalsChannel(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $task                 = $this->task($this->board('Roadmap', tenantKey: 'tenant-a'), 'Own');
        $this->issueToken();
        ApiTestHost::$tokens['bt_placeholder_mcp'] = ['channel' => TaskApiPrincipal::CHANNEL_MCP] + ApiTestHost::$tokens[self::TOKEN];

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Through the API']);
        self::assertResponseIsSuccessful();
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Through MCP'], [], 'bt_placeholder_mcp');
        self::assertResponseIsSuccessful();

        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);
        self::assertSame(['api', 'mcp'], array_map(static fn (TaskApiWriteEvent $event): string => $event->channel, $audit->events));
        self::assertSame(['api', 'mcp'], array_map(static fn (TaskApiWriteEvent $event): string => $event->principal->channel, $audit->events));
    }

    public function testAChannelSentByTheClientIsIgnored(): void
    {
        // Audit II-I5 (d): only the host's authenticator sets the channel.
        ApiTestHost::$tenants = ['tenant-a'];
        $task                 = $this->task($this->board('Roadmap', tenantKey: 'tenant-a'), 'Own');
        $this->issueToken();

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId() . '?channel=mcp', ['name' => 'Renamed', 'channel' => 'mcp'], ['HTTP_X_CHANNEL' => 'mcp']);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $task->getId(), 'start' => 1790000000000, 'duration' => 60000, 'channel' => 'mcp']);
        self::assertResponseIsSuccessful();

        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);
        self::assertSame(['api', 'api'], array_map(static fn (TaskApiWriteEvent $event): string => $event->channel, $audit->events));
    }
}
