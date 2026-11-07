<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Spec 214: with both options off a host sees nothing new: no number, no route, no tab, no table.
 */
final class TaskNumbersOffTest extends FunctionalTestCase
{
    public function testNothingChangesWithTheOptionsOff(): void
    {
        $board = $this->board('One');
        $task  = $this->task($board, 'a');
        $this->login($this->owner);

        self::assertNull($task->getNumber());
        self::assertTrue($this->em->getConfiguration()->getMetadataDriverImpl()?->isTransient(TaskNumberCounter::class));

        foreach (['/tools/task-board/n/1', '/tools/task-board/board/' . $board->getId() . '/calendar'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->client->request('POST', '/tools/task-board/task/' . $task->getId() . '/schedule');
        self::assertResponseStatusCodeSame(404);

        foreach (['?view=board', '/list', '/gantt'] as $page) {
            $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . $page);
            self::assertResponseIsSuccessful();
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('nowo-task-number', $html);
            self::assertStringNotContainsString('/calendar"', $html);
            self::assertStringNotContainsString('nowo-ui-clipboard.js', $html);
            self::assertStringNotContainsString('value="number"', $html);
        }
    }
}
