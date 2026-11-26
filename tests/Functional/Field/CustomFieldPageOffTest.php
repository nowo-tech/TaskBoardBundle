<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Nowo\TaskBoardBundle\Controller\TaskCustomFieldController;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 3.3.0: `web_ui.custom_fields_page: false` — no route, no controller, no header action (a host with its own
 * field screens).
 */
final class CustomFieldPageOffTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['web_ui' => ['custom_fields_page' => false]];
        parent::setUp();
    }

    public function testThePageAndItsActionAreGone(): void
    {
        $board = $this->board('Fields');
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/fields"]'));
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/fields');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::getContainer()->has(TaskCustomFieldController::class));
    }
}
