import { Controller } from '@hotwired/stimulus';

/**
 * One button that opens/closes every <details> group at once - used by
 * "Übung verwalten" when there are several exercise groups, so a visitor
 * with many active exercises isn't stuck scrolling past groups they
 * already know the contents of.
 */
export default class extends Controller {
    static targets = ['details', 'button'];
    static values = { expandLabel: String, collapseLabel: String };

    connect() {
        this.render(this.detailsTargets.every((details) => details.open));
    }

    toggle() {
        const shouldOpen = this.detailsTargets.some((details) => !details.open);
        this.detailsTargets.forEach((details) => {
            details.open = shouldOpen;
        });
        this.render(shouldOpen);
    }

    render(isOpen) {
        if (this.hasButtonTarget) {
            this.buttonTarget.textContent = isOpen ? this.collapseLabelValue : this.expandLabelValue;
        }
    }
}
