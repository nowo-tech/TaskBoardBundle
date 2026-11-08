<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

/**
 * Spec 213 S6: a board is created only where the host's create check allows it, as the UI's New board.
 */
final class ApiBoardCreateCheckTest extends ApiTestCase
{
    /** A checker that is not tenant-aware is asked without the tenant, as the UI asks it. */
    public static bool $plainChecker = false;

    protected function setUp(): void
    {
        static::$hostConfig = ['security' => self::$plainChecker ? ['access_checker' => 'app.plain_checker'] : ['create_roles' => ['ROLE_ADMIN']]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
    }

    public function testAPersonWhoMayNotCreateHereIsRefused(): void
    {
        $this->issueToken();

        $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => 'Refused']);

        $this->assertRefused(403, 'ACCESS_002');
        self::assertSame([], $this->em->getRepository(TaskBoard::class)->findAll());
    }

    public function testACheckerThatIsNotTenantAwareIsAskedToo(): void
    {
        self::$plainChecker = true;
        self::ensureKernelShutdown();
        $this->setUp();
        $this->issueToken();

        try {
            $this->send('POST', '/api/tasks/v2/space/space-tenant-a/list', ['name' => 'Refused']);
        } finally {
            self::$plainChecker = false;
        }

        $this->assertRefused(403, 'ACCESS_002');
    }
}
