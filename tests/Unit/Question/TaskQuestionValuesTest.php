<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskQuestionAnswerFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionWithdrawFormData;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionConflictException;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Beacon spec 242: the form payloads hand the manager what was typed (empty option rows left out, the recommended row,
 * the version only when it is a positive integer), and the refusals name no id.
 */
final class TaskQuestionValuesTest extends TestCase
{
    public function testTheAskFormKeepsTheFilledRowsAndTheRecommendedOne(): void
    {
        $data = new TaskQuestionFormData(
            text: 'Which?',
            context: 'ctx',
            option1: 'A',
            option1Description: 'first',
            option2: ' ',
            option3: '',
            option3Description: 'only a description',
            option4: 'D',
            recommended: '4',
            choiceMode: 'multiple',
            allowOther: true,
            recipients: ['user:1', 7, 'contact:2'],
            dueOn: new DateTimeImmutable('2099-01-01'),
        );
        $input = $data->toInput();
        self::assertSame(['A', '', 'D'], array_map(static fn (TaskQuestionOptionInput $o): string => $o->label, $input->options));
        self::assertSame([false, false, true], array_map(static fn (TaskQuestionOptionInput $o): bool => $o->recommended, $input->options));
        self::assertSame('only a description', $input->options[1]->description);
        self::assertSame(TaskQuestionChoiceMode::Multiple, $input->choiceMode);
        self::assertSame(['user:1', 'contact:2'], $input->recipients);
        self::assertTrue($input->allowOther);
        self::assertSame('ctx', $input->context);
        self::assertSame('2099-01-01', $input->dueOn?->format('Y-m-d'));
        self::assertSame(TaskQuestionChoiceMode::Single, (new TaskQuestionFormData(choiceMode: 'weird'))->toInput()->choiceMode);
    }

    public function testTheAnswerAndWithdrawFormsReadTheirVersion(): void
    {
        self::assertSame(['o1'], (new TaskQuestionAnswerFormData('o1', null, '3'))->toInput()->optionIds);
        self::assertSame(['o1', 'o2'], (new TaskQuestionAnswerFormData(['o1', '', 'o2'], 'x'))->toInput()->optionIds);
        self::assertSame([], (new TaskQuestionAnswerFormData())->toInput()->optionIds);
        self::assertSame(3, (new TaskQuestionAnswerFormData(null, null, '3'))->versionNumber());
        foreach ([null, '', '0', '-1', '1.5', 'x', '99999999999', '2147483648'] as $bad) {
            self::assertNull((new TaskQuestionAnswerFormData(null, null, $bad))->versionNumber(), (string) $bad);
            self::assertNull((new TaskQuestionWithdrawFormData('r', $bad))->versionNumber(), (string) $bad);
        }
        self::assertSame(2147483647, (new TaskQuestionWithdrawFormData('r', '2147483647'))->versionNumber());
    }

    public function testTheRefusalsAndTheConflictSayNoId(): void
    {
        self::assertSame('The question write was refused: invalid_question (text)', (new TaskQuestionRefusedException(TaskQuestionRefusedException::INVALID_QUESTION, 'text'))->getMessage());
        self::assertSame('The question write was refused: forbidden', (new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN))->getMessage());
        self::assertSame('Custom', (new TaskQuestionRefusedException(TaskQuestionRefusedException::WITHDRAWN, '', 'Custom'))->getMessage());
        $conflict = new TaskQuestionConflictException(4);
        self::assertSame(4, $conflict->currentVersion);
        self::assertSame('The question changed: read it again', $conflict->getMessage());
    }
}
