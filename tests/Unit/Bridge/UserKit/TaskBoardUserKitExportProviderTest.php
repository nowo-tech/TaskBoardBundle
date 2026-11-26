<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Bridge\UserKit;

use Nowo\TaskBoardBundle\Bridge\UserKit\TaskBoardUserKitExportProvider;
use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporterInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 3.4.0: UserKit's `task_board` section is the exporter's document, for a user with an id.
 */
final class TaskBoardUserKitExportProviderTest extends TestCase
{
    public function testTheSectionIsTheExportersDocument(): void
    {
        $exporter = new class implements TaskBoardPersonalDataExporterInterface {
            public function export(object $user): array
            {
                return ['boards' => [['id' => 'b1']]];
            }
        };
        $provider = new TaskBoardUserKitExportProvider($exporter);
        $user     = new class {
            public function getId(): int
            {
                return 7;
            }
        };
        self::assertSame('task_board', $provider->getSectionKey());
        self::assertTrue($provider->supports($user));
        self::assertFalse($provider->supports(new stdClass()));
        self::assertSame(['boards' => [['id' => 'b1']]], $provider->export($user));
    }
}
