<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Validation;

use DateTimeInterface;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Support\BoardIcon;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Since 2.2.0 (spec 213): the rules of the fields a write takes, in one place, so that a write that does not go through
 * a form cannot store what the form refuses (a title over the column's size, a `javascript:` link, a colour that breaks
 * out of `style="…"`). The form types use these and only add their labels and widgets.
 */
final class TaskConstraints
{
    /** The `task_board_tasks.title` column. */
    public const TITLE_MAX = 255;

    /** Rendered inside style="…": hex colours only (#rgb, #rgba, #rrggbb, #rrggbbaa). */
    public const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D';

    /** A task colour is #rrggbb (the column holds 7 characters). */
    public const TASK_COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/D';

    /** At most a week of work in one time entry. */
    public const MAX_MINUTES = 10080;

    public const COLUMN_NAME_MAX = 128;

    public const NOTE_MAX = 512;

    public const URL_MAX = 2048;

    public const LABEL_MAX = 255;

    public const EXTERNAL_ID_MAX = 128;

    public const BOARD_TEXT_MAX = 255;

    /** @return list<Constraint> */
    public static function taskTitle(): array
    {
        // Since 1.15: the column is VARCHAR(255) — a longer title is an error, not a database error.
        return [new NotBlank(), new Length(max: self::TITLE_MAX)];
    }

    /** @return list<Constraint> */
    public static function taskColor(): array
    {
        return [new Regex(pattern: self::TASK_COLOR_PATTERN)];
    }

    /** @return list<Constraint> */
    public static function boardName(): array
    {
        return [new NotBlank(), new Length(max: self::BOARD_TEXT_MAX)];
    }

    /** @return list<Constraint> */
    public static function boardSlug(): array
    {
        return [new Length(max: self::BOARD_TEXT_MAX)];
    }

    /** @return list<Constraint> */
    public static function boardIcon(string $tooLongMessage, string $invalidMessage): array
    {
        return [
            new Length(max: BoardIcon::MAX_BYTES, countUnit: Length::COUNT_BYTES, maxMessage: $tooLongMessage),
            new Regex(pattern: BoardIcon::PATTERN, message: $invalidMessage),
        ];
    }

    /** @return list<Constraint> */
    public static function columnName(): array
    {
        return [new NotBlank(), new Length(max: self::COLUMN_NAME_MAX)];
    }

    /** @return list<Constraint> */
    public static function columnColor(): array
    {
        return [new Regex(pattern: self::COLOR_PATTERN)];
    }

    /** @return list<Constraint> */
    public static function commentBody(): array
    {
        return [new NotBlank(), new Length(max: TaskComment::MAX_LENGTH)];
    }

    /** @return list<Constraint> */
    public static function timeMinutes(): array
    {
        return [new NotNull(), new Range(min: 1, max: self::MAX_MINUTES)];
    }

    /** @return list<Constraint> */
    public static function timeLoggedAt(): array
    {
        return [new NotNull()];
    }

    /** @return list<Constraint> */
    public static function timeNote(): array
    {
        return [new Length(max: self::NOTE_MAX)];
    }

    /** @return list<Constraint> */
    public static function linkUrl(): array
    {
        return [
            new NotBlank(),
            // Rendered as href: only http(s) — rejects javascript:, data:, vbscript:…
            new Url(protocols: ['http', 'https'], requireTld: false),
            new Length(max: self::URL_MAX),
        ];
    }

    /** @return list<Constraint> */
    public static function linkLabel(): array
    {
        return [new Length(max: self::LABEL_MAX)];
    }

    /** @return list<Constraint> */
    public static function linkExternalId(): array
    {
        return [new Length(max: self::EXTERNAL_ID_MAX)];
    }

    /** The rule of a task's dates: a start after the due date is an error. Either may be absent. */
    public static function startsAfterDue(mixed $startAt, mixed $dueAt): bool
    {
        return $startAt instanceof DateTimeInterface && $dueAt instanceof DateTimeInterface && $startAt > $dueAt;
    }
}
