import { Controller } from '@hotwired/stimulus';

/**
 * One resource row in an exercise form: toggles between the internal-tool
 * select and the external-URL input depending on the row's own type select
 * - a resource is exactly one or the other, never both.
 */
export default class extends Controller {
    static targets = ['typeSelect', 'toolField', 'urlField'];

    connect() {
        this.syncVisibility();
    }

    syncVisibility() {
        const isInternal = this.typeSelectTarget.value === 'internal_tool';
        this.toolFieldTarget.classList.toggle('hidden', !isInternal);
        this.urlFieldTarget.classList.toggle('hidden', isInternal);
    }
}
