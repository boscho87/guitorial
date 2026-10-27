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
        // Each Sortable instance needs its OWN options object - SortableJS
        // writes instance bookkeeping onto whatever object it's given, so
        // sharing one between the two lists made a drag in either list
        // corrupt the other's state (symptom: dragging one exercise moved
        // every item in the list instead of just the one being dragged).
        this.sortables = [this.availableTarget, this.selectedTarget].map((list) => new Sortable(list, {
            group: 'routine-exercises',
            animation: 150,
            ghostClass: 'opacity-40',
            onEnd: () => this.sync(),
        }));
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
