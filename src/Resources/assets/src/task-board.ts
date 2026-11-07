/**
 * TaskBoard bundle frontend entrypoint (Stimulus controllers).
 */
import { Application } from '@hotwired/stimulus';
import BoardController from './board-controller';
import AddTaskModalController from './add-task-modal-controller';
import AddColumnModalController from './add-column-modal-controller';
import ListFilterController from './list-filter-controller';
import TaskNavController from './task-nav-controller';
import SubtaskTreeController from './subtask-tree-controller';
import TaskStatusController from './task-status-controller';
import TaskMembersModalController from './task-members-modal-controller';
import TaskPriorityModalController from './task-priority-modal-controller';
import TaskLinksModalController from './task-links-modal-controller';
import GanttController from './gantt-controller';
import EditColumnModalController from './edit-column-modal-controller';
import PickerController from './picker-controller';
import OptionFilterController from './option-filter-controller';
import MentionController from './mention-controller';
import ActivityFilterController from './activity-filter-controller';
import ContainerBrowserController from './container-browser-controller';
import SyncProgressController from './sync-progress-controller';
import SelectAnchorController from './select-anchor-controller';
import BoardCreateStepsController from './board-create-steps-controller';
import FilterFoldController from './filter-fold-controller';
import CalendarController from './calendar-controller';
import { installFormConfirm } from './ask-confirm';
import { installBoardOpeners } from './board-openers';
import { observeCssVars } from './css-vars';

declare const __TASK_BOARD_BUILD_TIME__: string;

if (typeof __TASK_BOARD_BUILD_TIME__ !== 'undefined') {
    document.documentElement.dataset.taskBoardBuild = __TASK_BOARD_BUILD_TIME__;
}

const application = Application.start();
application.register('nowo-task-board', BoardController);
application.register('nowo-task-board-create-steps', BoardCreateStepsController);
application.register('nowo-task-board-add-task-modal', AddTaskModalController);
application.register('nowo-task-board-add-column-modal', AddColumnModalController);
application.register('nowo-task-board-list-filter', ListFilterController);
// Since 2.0.0: the filter fold works with no host peer (a bundle identifier, so a host's own peer never toggles it twice).
application.register('nowo-task-board-filter-fold', FilterFoldController);
application.register('nowo-task-board-subtask-tree', SubtaskTreeController);
application.register('nowo-task-board-task-status', TaskStatusController);
application.register('nowo-task-board-task-members-modal', TaskMembersModalController);
application.register('nowo-task-board-task-priority-modal', TaskPriorityModalController);
application.register('nowo-task-board-task-links-modal', TaskLinksModalController);
application.register('nowo-task-board-gantt', GanttController);
// Since 2.1.0 (spec 214).
application.register('nowo-task-board-calendar', CalendarController);
application.register('nowo-task-board-edit-column-modal', EditColumnModalController);
application.register('nowo-task-board-picker', PickerController);
application.register('nowo-task-board-option-filter', OptionFilterController);
application.register('nowo-task-board-mention', MentionController);
application.register('nowo-task-board-activity-filter', ActivityFilterController);
application.register('nowo-task-board-task-nav', TaskNavController);
// Since 1.23: provider connections.
application.register('nowo-task-board-container-browser', ContainerBrowserController);
application.register('nowo-task-board-sync-progress', SyncProgressController);
// Since 1.24: the import help link follows the selected source (help_link.anchor_from: source).
application.register('nowo-task-board-select-anchor', SelectAnchorController);

// Since 2.0.0: forms with data-confirm ask through the kit's dialog.
installFormConfirm(document);
// Since 2.0.0 (TB-10): a host's button opens the column-set dialogs.
installBoardOpeners(document);

// Since 1.11: CSS custom properties from data-nowo-task-vars (no inline style), also inside panels loaded later.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => observeCssVars(document.documentElement));
} else {
    observeCssVars(document.documentElement);
}
