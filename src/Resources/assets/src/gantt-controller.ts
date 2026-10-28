import { Controller } from '@hotwired/stimulus';
import { scaleRedirect, scaleToRemember } from './gantt-scale';
import { readMap, writeEntry } from './storage';

const SCALE_KEY = 'nowo-task-board.gantt-scale';

type GanttLink = { from: string; to: string };

/**
 * Draws dependency arrows over the Gantt chart. Since 1.15 it also remembers the scale per board (a visit without
 * `?scale=` opens the saved one) and opens with now in view in every scale.
 */
export default class GanttController extends Controller<HTMLElement> {
    static targets = ['grid', 'svg', 'row', 'bar', 'today'];

    static values = {
        links: { type: Array, default: [] },
        dayCount: { type: Number, default: 0 },
        board: { type: String, default: '' },
        scale: { type: String, default: 'days' },
    };

    declare boardValue: string;

    declare scaleValue: string;

    declare readonly gridTarget: HTMLElement;

    declare readonly svgTarget: SVGSVGElement;

    declare readonly rowTargets: HTMLElement[];

    declare readonly barTargets: HTMLElement[];

    declare linksValue: GanttLink[];

    declare dayCountValue: number;

    private readonly onResize = (): void => {
        this.drawLinks();
    };

    declare readonly hasTodayTarget: boolean;

    declare readonly todayTarget: HTMLElement;

    connect(): void {
        const target = scaleRedirect(window.location.href, this.scaleValue, readMap(SCALE_KEY)[this.boardValue]);
        if (target !== null) {
            window.location.replace(target);

            return;
        }
        const scale = scaleToRemember(window.location.href);
        if (scale !== null) {
            writeEntry(SCALE_KEY, this.boardValue, scale);
        }
        this.drawLinks();
        window.addEventListener('resize', this.onResize);
        this.scrollToToday();
    }

    /** Since 1.13: the chart opens with today in view (a few days of context on its left). */
    private scrollToToday(): void {
        const viewport = this.element.querySelector<HTMLElement>('.nowo-task-gantt__viewport');
        if (!this.hasTodayTarget || viewport === null) {
            return;
        }
        const label = this.element.querySelector<HTMLElement>('.nowo-task-gantt__corner')?.offsetWidth ?? 0;
        viewport.scrollLeft = Math.max(0, this.todayTarget.offsetLeft - label - 3 * this.todayTarget.offsetWidth);
    }

    disconnect(): void {
        window.removeEventListener('resize', this.onResize);
    }

    /** Since 1.15: a click on a scale saves it before the page changes. */
    rememberScale(event: Event): void {
        const scale = (event.currentTarget as HTMLElement).dataset.scale ?? '';
        if (scale !== '') {
            writeEntry(SCALE_KEY, this.boardValue, scale);
        }
    }

    private drawLinks(): void {
        if (!this.hasSvgTarget || !this.hasGridTarget) {
            return;
        }

        const svg = this.svgTarget;
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }

        const links = this.linksValue;
        if (links.length === 0) {
            return;
        }

        const gridRect = this.gridTarget.getBoundingClientRect();
        const corner = this.gridTarget.querySelector('.nowo-task-gantt__corner');
        const labelWidth = corner instanceof HTMLElement ? corner.offsetWidth : 280;
        const timelineWidth = gridRect.width - labelWidth;
        const dayWidth = this.dayCountValue > 0 ? timelineWidth / this.dayCountValue : 0;

        svg.setAttribute('width', String(gridRect.width));
        svg.setAttribute('height', String(gridRect.height));
        svg.style.width = `${gridRect.width}px`;
        svg.style.height = `${gridRect.height}px`;

        const barsByTask = new Map<string, DOMRect>();
        this.barTargets.forEach((bar) => {
            const row = bar.closest('[data-task-id]');
            const taskId = row?.getAttribute('data-task-id');
            if (taskId) {
                barsByTask.set(taskId, bar.getBoundingClientRect());
            }
        });

        links.forEach((link) => {
            const fromRect = barsByTask.get(link.from);
            const toRect = barsByTask.get(link.to);
            if (!fromRect || !toRect) {
                return;
            }

            const x1 = fromRect.right - gridRect.left;
            const y1 = fromRect.top + fromRect.height / 2 - gridRect.top;
            const x2 = toRect.left - gridRect.left;
            const y2 = toRect.top + toRect.height / 2 - gridRect.top;
            const midX = x1 + Math.max(dayWidth * 0.5, (x2 - x1) * 0.5);

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute(
                'd',
                `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`,
            );
            path.setAttribute('class', 'nowo-task-gantt__link-path');
            svg.appendChild(path);

            const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            arrow.setAttribute(
                'points',
                `${x2},${y2} ${x2 - 6},${y2 - 4} ${x2 - 6},${y2 + 4}`,
            );
            arrow.setAttribute('class', 'nowo-task-gantt__link-arrow');
            svg.appendChild(arrow);
        });
    }
}
