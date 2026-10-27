import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * Two-column drag board for the routine editor: dragging an exercise from
 * "Verfügbare Übungen" into "In dieser Routine" adds it, dragging the other
 * way removes it, and dragging within "In dieser Routine" reorders it - one
 * gesture for all three, replacing the old checkbox list + separate
 * up/down-button reorder section. The "selected" list's current DOM order
 * is the single source of truth and is rebuilt into hidden exercise_ids[]
 * inputs on every change; RoutineController::edit() treats that order as
 * authoritative (see its own comment).
 */
export default class extends Controller {
    static targets = ['available', 'selected', 'hiddenInputs'];

    connect() {
        const options = {
            group: 'routine-exercises',
            animation: 150,
            ghostClass: 'opacity-40',
            onEnd: () => this.sync(),
        };
        this.sortables = [this.availableTarget, this.selectedTarget].map((list) => new Sortable(list, options));
        this.sync();
    }

    disconnect() {
        this.sortables?.forEach((sortable) => sortable.destroy());
    }

    sync() {
        const ids = [...this.selectedTarget.children].map((item) => item.dataset.id);
        this.hiddenInputsTarget.replaceChildren(
            ...ids.map((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'exercise_ids[]';
                input.value = id;

                return input;
            }),
        );
    }
}
