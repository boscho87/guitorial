import { Controller } from '@hotwired/stimulus';

/**
 * Auto-dismissing feedback toast - shown after answering so the next
 * question can appear immediately without an extra click, without the
 * feedback blocking it (especially on small screens).
 */
export default class extends Controller {
    static values = { dismissAfter: { type: Number, default: 2500 } };

    connect() {
        this.timeout = setTimeout(() => {
            this.element.classList.add('opacity-0');
            setTimeout(() => this.element.remove(), 300);
        }, this.dismissAfterValue);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
