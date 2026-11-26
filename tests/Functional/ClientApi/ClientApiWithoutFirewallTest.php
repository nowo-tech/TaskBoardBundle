<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

/**
 * Since 3.4.0: a host that forgot the client API's firewall gets a closed API (`500 server_error`, logged), never an
 * answer as nobody.
 */
final class ClientApiWithoutFirewallTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        self::$withFirewall = false;
        parent::setUp();
    }

    public function testTheApiFailsClosed(): void
    {
        $this->call('GET', '/me');
        $this->assertError(500, 'server_error');
    }
}
