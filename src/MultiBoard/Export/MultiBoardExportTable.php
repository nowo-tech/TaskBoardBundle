<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard\Export;

use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskRow;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function implode;
use function preg_replace;

/**
 * Since 2.13.0: the columns of the multi-board export (CSV and XLSX alike): one row per task, dates as `Y-m-d`, minutes
 * as numbers. The cells are raw values; the writers guard text against formula injection.
 *
 * @internal
 */
final readonly class MultiBoardExportTable
{
    /** Header translation keys, in column order. */
    public const COLUMNS = [
        'task_board.multi.export.number',
        'task_board.multi.export.title',
        'task_board.multi.export.board',
        'task_board.multi.export.column',
        'task_board.multi.export.status',
        'task_board.multi.export.priority',
        'task_board.multi.export.assignees',
        'task_board.multi.export.start',
        'task_board.multi.export.due',
        'task_board.multi.export.overdue',
        'task_board.multi.export.labels',
        'task_board.multi.export.estimate',
        'task_board.multi.export.logged',
        'task_board.multi.export.task_id',
    ];

    /**
     * A formula trigger at the start of the text or of a joined part (after a separator), behind optional whitespace
     * (audit NI-6): it gets a leading `'`, so no spreadsheet cell split out of this one starts with a formula, even
     * after a space. ControllerKit's `CsvSafeWriter` then guards the cell as a whole.
     */
    private const TRIGGER = '/(^|[;,|\t\r\n])(\s*)(?=[=+\-@])/u';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        return array_map(fn (string $key): string => $this->translator->trans($key, [], TaskBoardBundle::TRANSLATION_DOMAIN), self::COLUMNS);
    }

    /**
     * @return list<int|string|null>
     */
    public function cells(MultiBoardTaskRow $row): array
    {
        return [
            $row->number,
            self::guard($row->title),
            self::guard($row->board->label),
            $row->columnName !== null ? self::guard($row->columnName) : null,
            $this->translator->trans($row->isCompleted() ? 'task_board.multi.status.done' : 'task_board.multi.status.open', [], TaskBoardBundle::TRANSLATION_DOMAIN),
            $this->translator->trans('task_board.priority.' . $row->priority->value, [], TaskBoardBundle::TRANSLATION_DOMAIN),
            implode(', ', array_map(static fn (MultiBoardPerson $person): string => self::guard($person->label), $row->assignees)),
            $row->startAt?->format('Y-m-d'),
            $row->dueAt?->format('Y-m-d'),
            $this->translator->trans($row->overdue ? 'task_board.multi.export.yes' : 'task_board.multi.export.no', [], TaskBoardBundle::TRANSLATION_DOMAIN),
            implode(', ', array_map(self::guard(...), $row->labels)),
            $row->estimatedMinutes,
            $row->loggedMinutes,
            $row->taskId,
        ];
    }

    /** Text with a `'` before every formula trigger that starts it or one of its parts, whitespace or not. */
    public static function guard(string $text): string
    {
        return (string) preg_replace(self::TRIGGER, '$1$2\'', $text);
    }
}
