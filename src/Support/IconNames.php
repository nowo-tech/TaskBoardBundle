<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function str_starts_with;
use function strrpos;
use function substr;

/**
 * Since 2.9.0 (audit JI-L3): the bundle names its icons after Tabler (`tabler:<name>` with `ux_icon`, `ti ti-<name>` with the
 * Tabler webfont). With UiKit's `bootstrap-icons` set those names were printed as `bi bi-<name>`, and many of them do not exist
 * there. {@see self::BOOTSTRAP} gives the Bootstrap Icons 1.13 name of every bundle icon (its own icons and the default
 * `board.column_icons`) whose Tabler name Bootstrap Icons lacks. Only names Bootstrap Icons does not have are keys, so a host
 * name that is a real Bootstrap icon is never changed; every other set gets the name as before.
 */
final class IconNames
{
    /** @var array<string, string> Tabler name => Bootstrap Icons 1.13 name */
    public const BOOTSTRAP = [
        'alert-triangle'   => 'exclamation-triangle',
        'arrows-sort'      => 'arrow-down-up',
        'bulb'             => 'lightbulb',
        'chart-bar'        => 'bar-chart',
        'circle-check'     => 'check-circle',
        'circle-dashed'    => 'dash-circle-dotted',
        'circle-dot'       => 'record-circle',
        'clock-play'       => 'stopwatch',
        'corner-left-up'   => 'arrow-90deg-up',
        'device-floppy'    => 'floppy',
        'external-link'    => 'box-arrow-up-right',
        'flame'            => 'fire',
        'git-branch'       => 'git',
        'git-merge'        => 'git',
        'git-pull-request' => 'git',
        'help'             => 'question-circle',
        'history'          => 'clock-history',
        'layout-columns'   => 'layout-three-columns',
        'layout-grid'      => 'grid',
        'layout-kanban'    => 'kanban',
        'line-dashed'      => 'dash-lg',
        'list-tree'        => 'diagram-3',
        'message-circle'   => 'chat',
        'notes'            => 'journal-text',
        'package'          => 'box-seam',
        'player-play'      => 'play',
        'plug-connected'   => 'plug-fill',
        'progress'         => 'hourglass-split',
        'refresh'          => 'arrow-clockwise',
        'subtask'          => 'diagram-2',
        'test-pipe'        => 'flask',
        'unlink'           => 'slash-circle',
        'user'             => 'person',
        'user-minus'       => 'person-dash',
        'user-plus'        => 'person-plus',
        'users'            => 'people',
    ];

    /**
     * The name UiKit `components/_icon` gets for a bare Tabler `$name` under `$iconSet` (not `ux_icon`, which takes
     * `tabler:<name>`): the Bootstrap Icons name with `bootstrap-icons`, the same name otherwise.
     */
    public static function forSet(string $name, string $iconSet): string
    {
        return $iconSet === 'bootstrap-icons' ? (self::BOOTSTRAP[$name] ?? $name) : $name;
    }

    /**
     * The bare name of a column icon id for a webfont set: the part after the colon, mapped only when the id is a Tabler one
     * (`tabler:<name>`, or a bare name): `lucide:bug` stays `bug`.
     */
    public static function columnIcon(string $icon, string $iconSet): string
    {
        $colon = strrpos($icon, ':');
        if ($colon === false) {
            return self::forSet($icon, $iconSet);
        }
        $name = substr($icon, $colon + 1);

        return str_starts_with($icon, 'tabler:') ? self::forSet($name, $iconSet) : $name;
    }
}
