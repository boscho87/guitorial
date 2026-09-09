import { Controller } from '@hotwired/stimulus';

/**
 * Toggles one diary item row between its display and inline edit form -
 * purely client-side, no turbo-frame involved.
 */
export default class extends Controller {
    static targets = ['display', 'editForm'];

    showEdit() {
        this.displayTarget.classList.toggle('hidden');
        this.editFormTarget.classList.toggle('hidden');
    }
}
