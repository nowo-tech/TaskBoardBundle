import { Controller } from '@hotwired/stimulus';

/**
 * Kanban board: drag tasks between columns, reorder columns, POST changes to the server.
 */
export default class BoardController extends Controller<HTMLElement> {
    static targets = ['column', 'task'];

    static values = {
        reorderUrl: String,
    };

    declare readonly columnTargets: HTMLElement[];

    declare readonly taskTargets: HTMLElement[];

    declare readonly reorderUrlValue: string;

    private draggedTask: HTMLElement | null = null;

    private draggingColumn: HTMLElement | null = null;

    connect(): void {
        this.taskTargets.forEach((task) => {
            task.addEventListener('dragstart', this.onTaskDragStart);
            task.addEventListener('dragend', this.onTaskDragEnd);
        });

        this.columnTargets.forEach((column) => {
            column.addEventListener('dragover', this.onDragOver);
            column.addEventListener('drop', this.onDrop);

            const header = column.querySelector('[data-column-handle]');
            if (header instanceof HTMLElement) {
                header.addEventListener('dragstart', this.onColumnDragStart);
                header.addEventListener('dragend', this.onColumnDragEnd);
            }
        });
    }

    disconnect(): void {
        this.taskTargets.forEach((task) => {
            task.removeEventListener('dragstart', this.onTaskDragStart);
            task.removeEventListener('dragend', this.onTaskDragEnd);
        });

        this.columnTargets.forEach((column) => {
            column.removeEventListener('dragover', this.onDragOver);
            column.removeEventListener('drop', this.onDrop);

            const header = column.querySelector('[data-column-handle]');
            if (header instanceof HTMLElement) {
                header.removeEventListener('dragstart', this.onColumnDragStart);
                header.removeEventListener('dragend', this.onColumnDragEnd);
            }
        });
    }

    private onTaskDragStart = (event: Event): void => {
        const task = event.currentTarget as HTMLElement;
        this.draggedTask = task;
        this.draggingColumn = null;
        task.classList.add('is-dragging');
        if (event instanceof DragEvent && event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', task.dataset.taskId ?? '');
        }
    };

    private onTaskDragEnd = (): void => {
        if (this.draggedTask) {
            this.draggedTask.classList.remove('is-dragging');
            this.draggedTask = null;
        }
        this.clearColumnDropTargets();
    };

    private onColumnDragStart = (event: Event): void => {
        const header = event.currentTarget as HTMLElement;
        const column = header.closest('[data-nowo-task-board-target="column"]');
        if (!(column instanceof HTMLElement) || column.classList.contains('nowo-task-board__column--add')) {
            return;
        }

        this.draggingColumn = column;
        this.draggedTask = null;
        column.classList.add('is-dragging-column');
        if (event instanceof DragEvent && event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', column.dataset.columnId ?? '');
        }
    };

    private onColumnDragEnd = (): void => {
        if (this.draggingColumn) {
            this.draggingColumn.classList.remove('is-dragging-column');
            this.draggingColumn = null;
        }
        this.clearColumnDropTargets();
    };

    private onDragOver = (event: Event): void => {
        if (!(event.currentTarget instanceof HTMLElement)) {
            return;
        }

        const column = event.currentTarget;
        if (column.classList.contains('nowo-task-board__column--add')) {
            return;
        }

        event.preventDefault();
        if (event instanceof DragEvent && event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        if (this.draggingColumn && this.draggingColumn !== column) {
            column.classList.add('is-drop-target');
        }
    };

    private onDrop = async (event: Event): Promise<void> => {
        event.preventDefault();
        if (!(event.currentTarget instanceof HTMLElement)) {
            return;
        }

        const column = event.currentTarget;
        if (column.classList.contains('nowo-task-board__column--add')) {
            return;
        }

        if (this.draggingColumn) {
            this.reorderColumn(column);
            return;
        }

        if (!this.draggedTask) {
            return;
        }

        const columnId = column.dataset.columnId ?? '';
        const moveUrl = this.draggedTask.dataset.moveUrl ?? '';
        if (columnId === '' || moveUrl === '') {
            return;
        }

        const list = column.querySelector('[data-nowo-task-board-target="taskList"]');
        const position = list instanceof HTMLElement
            ? list.querySelectorAll('[data-nowo-task-board-target="task"]').length
            : 0;

        const body = new URLSearchParams();
        body.set('columnId', columnId);
        body.set('position', String(position));

        const response = await fetch(moveUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
        });

        if (response.ok) {
            window.location.reload();
        }

        this.clearColumnDropTargets();
    };

    private reorderColumn(targetColumn: HTMLElement): void {
        if (!this.draggingColumn || this.draggingColumn === targetColumn) {
            return;
        }

        targetColumn.parentNode?.insertBefore(this.draggingColumn, targetColumn);
        this.submitColumnOrder();
        this.draggingColumn = null;
        this.clearColumnDropTargets();
    }

    private submitColumnOrder(): void {
        if (this.reorderUrlValue === '') {
            window.location.reload();
            return;
        }

        const body = new URLSearchParams();
        this.columnTargets.forEach((column) => {
            if (column.classList.contains('nowo-task-board__column--add')) {
                return;
            }

            const columnId = column.dataset.columnId ?? '';
            if (columnId !== '') {
                body.append('columnOrder[]', columnId);
            }
        });

        fetch(this.reorderUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
        }).then((response) => {
            if (response.ok) {
                window.location.reload();
            }
        });
    }

    private clearColumnDropTargets(): void {
        this.columnTargets.forEach((column) => column.classList.remove('is-drop-target'));
    }
}
