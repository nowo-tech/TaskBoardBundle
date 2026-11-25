<?php

declare(strict_types=1);

namespace App\TaskBoard;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TenantAccessCheckEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Since 3.2.0: the demo grants the guided import from ClickUp to its demo user. The bundle denies `import_plan` by
 * default (only `security.manage_roles` holders); a real host grants it to the roles that may import (Beacon: project
 * managers and organisation admins).
 */
#[AsEventListener(event: TaskBoardEvents::TENANT_ACCESS_CHECK)]
final class DemoImportPlanAccess
{
    public function __invoke(TenantAccessCheckEvent $event): void
    {
        if ($event->getAction() === TenantAccessCheckEvent::ACTION_IMPORT_PLAN) {
            $event->grant();
        }
    }
}
