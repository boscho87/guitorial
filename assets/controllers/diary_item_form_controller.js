import { Controller } from '@hotwired/stimulus';

/**
 * Shows the target-minutes field only when a tool is linked - auto-tracking
 * needs both, a bare tool link with no target minutes is just a shortcut.
 * Attached to both the add-item form and every item's edit form.
 */
export default class extends Controller {
    static targets = ['toolSelect', 'minutesField'];

    connect() {
        this.syncMinutesVisibility();
    }

    syncMinutesVisibility() {
        this.minutesFieldTarget.classList.toggle('hidden', this.toolSelectTarget.value === '');
    }
}
