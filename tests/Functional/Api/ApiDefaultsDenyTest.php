<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

/**
 * Spec 213: with only the authenticator set, every other hook is the bundle's default, and every default denies.
 */
final class ApiDefaultsDenyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$api = ['enabled' => true, 'hooks' => ['authenticator' => 'app.api_host']];
        parent::setUp();
    }

    public function testTheDefaultRateLimiterRefusesEveryRequest(): void
    {
        $this->issueToken();

        $this->get('/api/tasks/v2/user');

        // Failed-token limit first: the default's IP state refuses before the token is read.
        $this->assertRefused(429, 'RATE_002');
        self::assertSame(0, ApiTestHost::$consumed);
    }
}
