import { Controller } from '@hotwired/stimulus';

/**
 * Tracks the order exercises get checked in and mirrors it into hidden
 * exercise_ids[] inputs - plain checkboxes submit in DOM order, not click
 * order, but the server already assigns sortIndex in whatever order
 * exercise_ids[] arrives (RoutineController::add()), so reordering the
 * hidden inputs here is enough; no backend change needed.
 */
export default class extends Controller {
    static targets = ['checkbox', 'hiddenInputs', 'preview'];

    connect() {
        this.order = this.checkboxTargets.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value);
        this.render();
    }

    toggle(event) {
        const id = event.target.value;
        if (event.target.checked) {
            if (!this.order.includes(id)) {
                this.order.push(id);
            }
        } else {
            this.order = this.order.filter((existingId) => existingId !== id);
        }
        this.render();
    }

    render() {
        this.hiddenInputsTarget.replaceChildren(
            ...this.order.map((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'exercise_ids[]';
                input.value = id;

                return input;
            }),
        );

        if (this.hasPreviewTarget) {
            const labelById = new Map(this.checkboxTargets.map((checkbox) => [checkbox.value, checkbox.dataset.label]));
            this.previewTarget.replaceChildren(
                ...this.order.map((id, index) => {
                    const line = document.createElement('p');
                    line.textContent = `${index + 1}. ${labelById.get(id) ?? ''}`;

                    return line;
                }),
            );
        }
    }
}
