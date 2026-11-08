<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Since 2.5.0 (TB-13): `BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS` guards the seven column and column-set write routes,
 * the board's column controls and a file import that would add columns; reads stay on the view decision.
 */
final class ColumnManagementDecisionTest extends FunctionalTestCase
{
    public function testADenyRefusesEveryColumnWriteRouteAndChangesNothing(): void
    {
        $board  = $this->board();
        $column = $board->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $column);
        $set = new ColumnSet('Kanban', $this->owner);
        $this->em->persist($set);
        $this->em->flush();
        $this->login($this->owner);
        $this->denyColumns();

        $ids = ['boardId' => $board->getId()];
        foreach ([
            ['column_create', $ids, ['board_column_form' => ['name' => 'Review']]],
            ['column_update', $ids + ['columnId' => $column->getId()], ['board_column_form' => ['name' => 'Renamed']]],
            ['column_reorder', $ids, ['_token' => PredictableCsrfTokenManager::value('task_board_column_reorder'), 'columnOrder' => []]],
            ['column_delete', $ids + ['columnId' => $column->getId()], ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]],
            ['column_set_create', $ids, ['column_set_form' => ['name' => 'Copy']]],
            ['column_set_apply', $ids, ['column_set_apply_form' => ['columnSetId' => $set->getId()]]],
            ['column_set_delete', $ids + ['setId' => $set->getId()], ['_token' => PredictableCsrfTokenManager::value('task_board_column_set_delete')]],
        ] as [$route, $parameters, $body]) {
            $this->client->request('POST', $this->url($route, $parameters), $body);
            self::assertResponseStatusCodeSame(403, $route);
        }

        $this->em->clear();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        self::assertSame(['To do', 'Doing', 'Done'], array_map(static fn (BoardColumn $c): string => $c->getName(), $fresh->getColumns()->toArray()));
        self::assertCount(1, $this->em->getRepository(ColumnSet::class)->findAll());
    }

    public function testTheBoardHidesTheColumnControlsAndKeepsTheTaskOnes(): void
    {
        $board = $this->board();
        $this->task($board, 'Card');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.nowo-task-board__add-column'), 'default: as before');
        self::assertCount(3, $crawler->filter('.nowo-task-column__edit'));
        self::assertCount(3, $crawler->filter('[data-column-handle] .nowo-task-board__column-grip'));
        self::assertCount(1, $crawler->filter('.nowo-task-column-sets__save'));

        $this->denyColumns();
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-nowo-task-board-reorder-url-value], [data-nowo-task-board-reorder-csrf-token-value]'), 'audit IM-I3');
        foreach (['.nowo-task-board__add-column', '.nowo-task-column__edit', '[data-column-handle]', '.nowo-task-board__column-grip', '.nowo-task-column-sets__save', '.nowo-task-column-sets__apply', '[data-nowo-ui-dialog-open="nowo-task-column-set-save"]', 'form[action*="/columns"]', '.nowo-task-board__hint'] as $control) {
            self::assertCount(0, $crawler->filter($control), $control);
        }
        self::assertCount(3, $crawler->filter('.nowo-task-board__column'), 'the columns themselves stay');
        self::assertGreaterThan(0, $crawler->filter('.nowo-task-inline-add, [data-action*="add-task-modal#open"]')->count(), 'the task controls stay');
    }

    /** Audit IM-I7 (a): a lower-priority listener that grants every action does not lift the deny over HTTP. */
    public function testALaterBlindGrantDoesNotLiftTheDeny(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        $this->denyColumns(10);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->grant();
        }, -10);

        $this->client->request('POST', $this->url('column_create', ['boardId' => $board->getId()]), ['board_column_form' => ['name' => 'Review']]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnImportDoesNotAddColumnsWithoutTheDecision(): void
    {
        $board = $this->board(columns: ['To do']);
        $this->login($this->owner);
        $this->denyColumns();

        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/import', [
            'task_import_form' => ['source' => 'jira_csv', 'createMissingColumns' => '1', '_token' => PredictableCsrfTokenManager::value('task_import_form')],
        ], ['task_import_form' => ['file' => $this->upload("Issue key,Summary,Status\nPRJ-1,Spec,Review\n")]]);
        self::assertResponseRedirects();

        $this->em->clear();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        self::assertSame(['To do'], array_map(static fn (BoardColumn $c): string => $c->getName(), $fresh->getColumns()->toArray()));
    }

    private function denyColumns(int $priority = 0): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS) {
                $event->deny();
            }
        }, $priority);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters): string
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        return $router->generate('nowo_task_board_' . $route, $parameters);
    }

    private function upload(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-import');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'tasks.csv', null, null, true);
    }
}
