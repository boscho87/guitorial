import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

const GROUP = 'exercise-to-routine';

/**
 * Lets an exercise card on "Übung verwalten" be dragged directly onto a
 * routine drop zone to join that routine - the same membership change the
 * routine editor's own drag board makes, without opening that page first.
 *
 * Built on SortableJS rather than native HTML5 drag-and-drop (unlike this
 * controller's first version) because native dragstart/dragover/drop never
 * fires on touch devices at all - SortableJS has its own touch handling, so
 * this also works on a phone mid-rehearsal, which is the main way this app
 * gets used for a band setlist.
 *
 * Each source list uses pull: 'clone' + sort: false so dragging only ever
 * copies a card into a drop zone without touching the real list order;
 * the clone is read and discarded immediately in handleDrop(), since a
 * drop zone's job is to receive the id, not to keep displaying the card.
 */
export default class extends Controller {
    static targets = ['source', 'dropZone'];

    connect() {
        this.sortables = [
            ...this.sourceTargets.map((list) => new Sortable(list, {
                group: { name: GROUP, pull: 'clone', put: false },
                sort: false,
                animation: 150,
                // Only an actual press-and-hold starts a drag on touch, so
                // scrolling the page past these cards isn't misread as one;
                // filter keeps every button/link/select/input in the card
                // clickable as normal instead of starting a drag from them.
                filter: 'a, button, input, select, .btn',
                preventOnFilter: false,
                delay: 150,
                delayOnTouchOnly: true,
            })),
            ...this.dropZoneTargets.map((zone) => new Sortable(zone, {
                group: { name: GROUP, pull: false, put: true },
                animation: 150,
                onAdd: (event) => this.handleDrop(zone, event),
            })),
        ];
    }

    disconnect() {
        this.sortables?.forEach((sortable) => sortable.destroy());
    }

    handleDrop(zone, event) {
        const exerciseId = event.item.dataset.exerciseId;
        event.item.remove();

        const form = zone.querySelector('form');
        if (!exerciseId || !form) {
            return;
        }
        form.querySelector('input[name="exercise_id"]').value = exerciseId;
        form.requestSubmit();
    }
}
