<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

/**
 * Spec 213: a host that left the API out of its firewall: the checkers would judge nobody, so it answers 500 and opens nothing.
 */
final class ApiWithoutFirewallTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$withFirewall = false;
        parent::setUp();
    }

    public function testNothingIsAnsweredWithoutTheSecurityUser(): void
    {
        $this->issueToken();

        $this->get('/api/tasks/v2/user');

        $this->assertRefused(500, 'SERVER_001');
    }
}
