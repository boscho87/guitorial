import { Controller } from '@hotwired/stimulus';

/**
 * Clicking a suggestion chip prefills the free-text add-item form (label,
 * category, tool, minutes) rather than submitting anything directly - the
 * user can still tweak before saving.
 */
export default class extends Controller {
    static targets = ['labelInput', 'toolSelect', 'minutesField', 'categoryGroup'];

    applySuggestion(event) {
        const { label, category, tool, minutes } = event.currentTarget.dataset;

        this.labelInputTarget.value = label;
        this.toolSelectTarget.value = tool || '';
        this.toolSelectTarget.dispatchEvent(new Event('change', { bubbles: true }));
        if (tool && minutes) {
            this.minutesFieldTarget.value = minutes;
        }

        const radio = this.categoryGroupTarget.querySelector(`input[value="${category}"]`);
        if (radio) {
            radio.checked = true;
        }
    }
}
