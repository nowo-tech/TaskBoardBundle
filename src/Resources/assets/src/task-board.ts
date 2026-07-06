/**
 * TaskBoard bundle frontend entrypoint (Stimulus controllers).
 */
import { Application } from '@hotwired/stimulus';
import BoardController from './board-controller';
import AddTaskModalController from './add-task-modal-controller';
import AddColumnModalController from './add-column-modal-controller';
import ListFilterController from './list-filter-controller';
import SubtaskTreeController from './subtask-tree-controller';
import TaskStatusController from './task-status-controller';
import TaskMembersModalController from './task-members-modal-controller';
import TaskPriorityModalController from './task-priority-modal-controller';
import TaskLinksModalController from './task-links-modal-controller';
import GanttController from './gantt-controller';
import EditColumnModalController from './edit-column-modal-controller';

declare const __TASK_BOARD_BUILD_TIME__: string;

if (typeof __TASK_BOARD_BUILD_TIME__ !== 'undefined') {
    document.documentElement.dataset.taskBoardBuild = __TASK_BOARD_BUILD_TIME__;
}

const application = Application.start();
application.register('nowo-task-board', BoardController);
application.register('nowo-task-board-add-task-modal', AddTaskModalController);
application.register('nowo-task-board-add-column-modal', AddColumnModalController);
application.register('nowo-task-board-list-filter', ListFilterController);
application.register('nowo-task-board-subtask-tree', SubtaskTreeController);
application.register('nowo-task-board-task-status', TaskStatusController);
application.register('nowo-task-board-task-members-modal', TaskMembersModalController);
application.register('nowo-task-board-task-priority-modal', TaskPriorityModalController);
application.register('nowo-task-board-task-links-modal', TaskLinksModalController);
application.register('nowo-task-board-gantt', GanttController);
application.register('nowo-task-board-edit-column-modal', EditColumnModalController);
