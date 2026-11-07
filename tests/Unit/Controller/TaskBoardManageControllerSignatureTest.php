<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Controller;

use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

use function array_map;
use function array_slice;
use function count;

/**
 * Audit IA-I1: a minor release keeps the controller's constructor as 2.1 had it, so a host passing arguments by position
 * or by name is not broken. New arguments go at the end.
 */
final class TaskBoardManageControllerSignatureTest extends TestCase
{
    /** The constructor of 2.1.0, in order. */
    private const SINCE_2_1 = [
        'accessChecker', 'boardRepository', 'taskRepository', 'boardCreator', 'columnManager', 'taskManager', 'linkAttacher',
        'memberAssigner', 'ganttBuilder', 'importOrchestrator', 'translator', 'routes', 'templates', 'userClass', 'dashboardRoute',
        'resourceAccessChecker', 'tenantContext', 'topLevelOnly', 'columnSetManager', 'columnIcons', 'dependencyManager',
        'timeLogger', 'weekStartResolver', 'commentManager', 'commentRepository', 'mentionCandidates', 'readersCanComment',
        'maxTasks', 'clock', 'logger', 'avatars', 'defaultViewResolver', 'createDialogHeader', 'syncLimits', 'scopeProvider',
        'boardFormOptions', 'quota', 'openSingleBoard', 'firstBoardProvisioner', 'pages', 'taskNumbers', 'taskNumberScope',
        'calendarBuilder', 'calendarEnabled',
    ];

    public function testTheConstructorKeepsEveryArgumentOf21InItsPlace(): void
    {
        $names = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(TaskBoardManageController::class, '__construct'))->getParameters());

        self::assertSame(self::SINCE_2_1, array_slice($names, 0, count(self::SINCE_2_1)));
    }
}
