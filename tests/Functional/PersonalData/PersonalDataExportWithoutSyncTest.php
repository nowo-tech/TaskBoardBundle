<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PersonalData;

use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporterInterface;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 3.4.0: without sync, questions or recurrence their sections are present and empty.
 */
final class PersonalDataExportWithoutSyncTest extends FunctionalTestCase
{
    public function testTheOptionalSectionsAreEmpty(): void
    {
        $this->board('Mine');
        $exporter = self::getContainer()->get(TaskBoardPersonalDataExporterInterface::class);
        self::assertInstanceOf(TaskBoardPersonalDataExporterInterface::class, $exporter);
        $document = $exporter->export($this->owner);
        self::assertCount(1, $document['boards']);
        self::assertSame([[], [], [], []], [$document['sync_people'], $document['import_plans'], $document['questions'], $document['recurrence']]);
    }
}
