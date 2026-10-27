import { Controller } from '@hotwired/stimulus';

/**
 * Lets an exercise card on "Übung verwalten" be dragged directly onto a
 * routine chip to join that routine - the same membership change the
 * routine editor's own drag board makes, without opening that page first.
 * Native HTML5 drag-and-drop (no library needed for a single-target drop,
 * unlike the routine editor's reorderable lists) - the exercise id travels
 * via the drag event's own data transfer, and dropping just fills and
 * submits that routine's pre-rendered hidden form.
 */
export default class extends Controller {
    static targets = ['dropZone'];

    dragStart(event) {
        event.dataTransfer.setData('text/plain', event.currentTarget.dataset.exerciseId);
        event.dataTransfer.effectAllowed = 'copy';
    }

    dragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('border-primary', 'bg-primary/10');
    }

    dragLeave(event) {
        event.currentTarget.classList.remove('border-primary', 'bg-primary/10');
    }

    drop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('border-primary', 'bg-primary/10');

        const exerciseId = event.dataTransfer.getData('text/plain');
        const form = event.currentTarget.querySelector('form');
        if (!exerciseId || !form) {
            return;
        }

        form.querySelector('input[name="exercise_id"]').value = exerciseId;
        form.requestSubmit();
    }
}
