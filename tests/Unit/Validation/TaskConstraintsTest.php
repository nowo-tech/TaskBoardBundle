<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Validation;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;

use function str_repeat;

/**
 * Since 2.2.0 (spec 213): the rules outside a form refuse what the forms refuse.
 */
final class TaskConstraintsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: list<Constraint>, 1: mixed, 2: bool}>
     */
    public static function values(): iterable
    {
        yield 'title' => [TaskConstraints::taskTitle(), 'Write the spec', true];
        yield 'title empty' => [TaskConstraints::taskTitle(), '', false];
        yield 'title too long' => [TaskConstraints::taskTitle(), str_repeat('x', 256), false];
        yield 'task colour' => [TaskConstraints::taskColor(), '#a1b2c3', true];
        yield 'task colour short form' => [TaskConstraints::taskColor(), '#abc', false];
        yield 'task colour breaking out' => [TaskConstraints::taskColor(), '#a1b2c3;x:y', false];
        yield 'board name' => [TaskConstraints::boardName(), 'Roadmap', true];
        yield 'board name empty' => [TaskConstraints::boardName(), '', false];
        yield 'board slug too long' => [TaskConstraints::boardSlug(), str_repeat('x', 256), false];
        yield 'icon' => [TaskConstraints::boardIcon('long', 'invalid'), "\u{1F680}", true];
        yield 'icon text' => [TaskConstraints::boardIcon('long', 'invalid'), 'rocket', false];
        yield 'column name' => [TaskConstraints::columnName(), 'Review', true];
        yield 'column name too long' => [TaskConstraints::columnName(), str_repeat('x', 129), false];
        yield 'column colour' => [TaskConstraints::columnColor(), '#abc', true];
        yield 'column colour script' => [TaskConstraints::columnColor(), 'red;background:url(x)', false];
        yield 'comment' => [TaskConstraints::commentBody(), 'Hello', true];
        yield 'comment too long' => [TaskConstraints::commentBody(), str_repeat('x', TaskComment::MAX_LENGTH + 1), false];
        yield 'minutes' => [TaskConstraints::timeMinutes(), 45, true];
        yield 'minutes zero' => [TaskConstraints::timeMinutes(), 0, false];
        yield 'minutes over a week' => [TaskConstraints::timeMinutes(), TaskConstraints::MAX_MINUTES + 1, false];
        yield 'minutes missing' => [TaskConstraints::timeMinutes(), null, false];
        yield 'logged at missing' => [TaskConstraints::timeLoggedAt(), null, false];
        yield 'note too long' => [TaskConstraints::timeNote(), str_repeat('x', 513), false];
        yield 'link' => [TaskConstraints::linkUrl(), 'https://example.com/spec', true];
        yield 'link without a tld' => [TaskConstraints::linkUrl(), 'http://intranet/spec', true];
        yield 'link javascript' => [TaskConstraints::linkUrl(), 'javascript:alert(1)', false];
        yield 'link data' => [TaskConstraints::linkUrl(), 'data:text/html,x', false];
        yield 'link label too long' => [TaskConstraints::linkLabel(), str_repeat('x', 256), false];
        yield 'link external id too long' => [TaskConstraints::linkExternalId(), str_repeat('x', 129), false];
    }

    /**
     * @param list<Constraint> $constraints
     */
    #[DataProvider('values')]
    public function testARuleAcceptsOrRefuses(array $constraints, mixed $value, bool $valid): void
    {
        $violations = Validation::createValidator()->validate($value, $constraints);

        self::assertSame($valid, $violations->count() === 0);
    }

    public function testAStartAfterTheDueDateIsTheOnlyDateError(): void
    {
        $early = new DateTimeImmutable('2026-11-06');
        $late  = new DateTimeImmutable('2026-11-07');

        self::assertTrue(TaskConstraints::startsAfterDue($late, $early));
        self::assertFalse(TaskConstraints::startsAfterDue($early, $late));
        self::assertFalse(TaskConstraints::startsAfterDue($early, $early));
        self::assertFalse(TaskConstraints::startsAfterDue(null, $early));
        self::assertFalse(TaskConstraints::startsAfterDue($early, null));
    }
}
