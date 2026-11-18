<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionDecisionPublisherInterface;
use Nowo\TaskBoardBundle\Question\TaskQuestionPublication;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use RuntimeException;

/**
 * Beacon spec 242: a decision publisher whose answers a test sets (static, reset by the test).
 */
final class ScriptedQuestionPublisher implements TaskQuestionDecisionPublisherInterface
{
    public static ?string $label = 'Publish to wiki';

    public static string $url = 'https://wiki.example/decisions/1';

    public static string $publishedLabel = 'Wiki: decision 1';

    public static bool $fail = false;

    /** @var list<array{0: TaskQuestionSnapshot, 1: TaskQuestionAnswerSnapshot}> */
    public static array $published = [];

    public static function reset(): void
    {
        self::$label          = 'Publish to wiki';
        self::$url            = 'https://wiki.example/decisions/1';
        self::$publishedLabel = 'Wiki: decision 1';
        self::$fail           = false;
        self::$published      = [];
    }

    public function targetLabel(TaskQuestionSnapshot $question, object $user): ?string
    {
        return self::$label;
    }

    public function publish(TaskQuestionSnapshot $question, TaskQuestionAnswerSnapshot $answer, object $user): TaskQuestionPublication
    {
        if (self::$fail) {
            throw new RuntimeException('The wiki is down');
        }
        self::$published[] = [$question, $answer];

        return new TaskQuestionPublication(self::$url, self::$publishedLabel);
    }
}
