<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Characterization;

use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskCommentFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskTimeEntryFormType;
use Nowo\TaskBoardBundle\Support\BoardIcon;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

use function array_keys;
use function array_values;
use function count;
use function str_repeat;

/**
 * Spec 213 S0: the validation the six write forms apply, pinned before their constraints get a shared home. A form that
 * lost or relaxed a rule would fail here, and with it a stored title over the column's size or a `javascript:` link.
 */
final class UiFormsCharacterizationTest extends FunctionalTestCase
{
    use CapturesUiOutput;

    /**
     * @return iterable<string, array{0: class-string, 1: array<string, mixed>, 2: array<string, list<array<string, mixed>>>}>
     */
    public static function forms(): iterable
    {
        yield 'task' => [TaskFormType::class, ['column_choices' => []], [
            'title'   => [['class' => NotBlank::class], ['class' => Length::class, 'max' => 255]],
            'startAt' => [['class' => Callback::class]],
        ]];
        yield 'board' => [TaskBoardFormType::class, ['icon_field' => true, 'icon_too_long_message' => 'long', 'icon_invalid_message' => 'invalid'], [
            'name' => [['class' => NotBlank::class], ['class' => Length::class, 'max' => 255]],
            'slug' => [['class' => Length::class, 'max' => 255]],
            'icon' => [
                ['class' => Length::class, 'max' => BoardIcon::MAX_BYTES, 'countUnit' => Length::COUNT_BYTES, 'maxMessage' => 'long'],
                ['class' => Regex::class, 'pattern' => BoardIcon::PATTERN, 'message' => 'invalid'],
            ],
        ]];
        yield 'column' => [BoardColumnFormType::class, [], [
            'name'  => [['class' => NotBlank::class], ['class' => Length::class, 'max' => 128]],
            'color' => [['class' => Regex::class, 'pattern' => BoardColumnFormType::COLOR_PATTERN]],
        ]];
        yield 'comment' => [TaskCommentFormType::class, [], [
            'body' => [['class' => NotBlank::class], ['class' => Length::class, 'max' => TaskComment::MAX_LENGTH]],
        ]];
        yield 'time' => [TaskTimeEntryFormType::class, [], [
            'minutes'  => [['class' => NotNull::class], ['class' => Range::class, 'min' => 1, 'max' => TaskTimeEntryFormType::MAX_MINUTES]],
            'loggedAt' => [['class' => NotNull::class]],
            'note'     => [['class' => Length::class, 'max' => 512]],
        ]];
        yield 'link' => [TaskLinkFormType::class, [], [
            'url' => [
                ['class' => NotBlank::class],
                ['class' => Url::class, 'protocols' => ['http', 'https'], 'requireTld' => false],
                ['class' => Length::class, 'max' => 2048],
            ],
            'label'      => [['class' => Length::class, 'max' => 255]],
            'externalId' => [['class' => Length::class, 'max' => 128]],
        ]];
    }

    /**
     * @param class-string $type
     * @param array<string, mixed> $options
     * @param array<string, list<array<string, mixed>>> $expected
     */
    #[DataProvider('forms')]
    public function testAFormKeepsItsConstraints(string $type, array $options, array $expected): void
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $form = $factory->create($type, null, $options);

        foreach ($expected as $field => $constraints) {
            self::assertSame($constraints, $this->described($form->get($field), $constraints), $field);
        }
    }

    public function testATitleOverTheColumnSizeIsAFlashAndNothingIsStored(): void
    {
        $board  = $this->board();
        $column = $board->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $column);
        $this->login($this->owner);
        $this->logger()->records = [];

        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/create', ['task_form' => ['title' => str_repeat('x', 256), 'columnId' => $column->getId(), '_token' => PredictableCsrfTokenManager::value('task_form')]]);

        $captured = $this->captured();
        self::assertSame(302, $captured['status']);
        self::assertSame([], $captured['writes']);
        self::assertCount(1, $captured['flashes']['error'] ?? []);
        self::assertSame([], $this->em->getRepository(Task::class)->findBy(['board' => $board]));
    }

    public function testAJavascriptLinkIsNotStored(): void
    {
        $task = $this->task($this->board(), 'Linked');
        $this->login($this->owner);
        $this->logger()->records = [];

        $this->client->request('POST', '/tools/task-board/task/' . $task->getId() . '/link', ['task_link_form' => ['linkType' => 'url', 'url' => 'javascript:alert(1)', '_token' => PredictableCsrfTokenManager::value('task_link_form')]]);

        self::assertSame([], $this->captured()['writes']);
        self::assertSame([], $this->em->getRepository(TaskLink::class)->findBy(['task' => $task]));
    }

    public function testAStartAfterTheDueDateIsAFormError(): void
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $form = $factory->create(TaskFormType::class, new TaskFormData(), ['column_choices' => [], 'csrf_protection' => false, 'rich_description' => false, 'tag_input' => false]);

        $form->submit(['title' => 'Dates', 'priority' => 'normal', 'startAt' => '2026-11-07', 'dueAt' => '2026-11-06']);

        self::assertFalse($form->isValid());
        self::assertSame(1, $form->get('startAt')->getErrors()->count());
    }

    public function testTheBoardPagesRenderTheirForms(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Rendered');
        $this->login($this->owner);

        foreach (['board' => '/board/' . $board->getId(), 'list' => '/board/' . $board->getId() . '/list', 'index' => '', 'task' => '/task/' . $task->getId(), 'import' => '/board/' . $board->getId() . '/import'] as $name => $path) {
            $this->client->request('GET', '/tools/task-board' . $path);
            self::assertResponseIsSuccessful($name);
            $this->dumpBody('page-' . $name);
        }
    }

    /**
     * @param FormInterface<mixed> $field
     * @param list<array<string, mixed>> $expected what to read of each constraint
     *
     * @return list<array<string, mixed>>
     */
    private function described(FormInterface $field, array $expected): array
    {
        $constraints = $field->getConfig()->getOption('constraints');
        self::assertIsArray($constraints);
        self::assertCount(count($expected), $constraints);
        $described = [];
        foreach (array_values($constraints) as $index => $constraint) {
            self::assertInstanceOf(Constraint::class, $constraint);
            $row = ['class' => $constraint::class];
            foreach (array_keys($expected[$index]) as $property) {
                if ($property !== 'class') {
                    $row[$property] = $constraint->{$property};
                }
            }
            $described[] = $row;
        }

        return $described;
    }
}
