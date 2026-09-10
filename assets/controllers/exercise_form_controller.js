import { Controller } from '@hotwired/stimulus';

/**
 * The exercise setup form: an appendable list of resource rows (cloned from
 * a <template>, so each new row gets its own resource-row controller
 * automatically via Stimulus's MutationObserver) plus showing the
 * repeat-count field only for the "pro_woche" rhythm.
 */
export default class extends Controller {
    static targets = ['resourceList', 'resourceTemplate', 'rhythmSelect', 'repeatCountField'];

    connect() {
        this.syncRepeatVisibility();
    }

    addResource() {
        this.resourceListTarget.appendChild(this.resourceTemplateTarget.content.cloneNode(true));
    }

    removeResource(event) {
        event.currentTarget.closest('[data-controller~="resource-row"]')?.remove();
    }

    syncRepeatVisibility() {
        this.repeatCountFieldTarget.classList.toggle('hidden', this.rhythmSelectTarget.value !== 'pro_woche');
    }
}
