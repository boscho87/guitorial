import { Controller } from '@hotwired/stimulus';

/**
 * Lets the hub's tool cards be opened with the 1-9/0 number keys, same
 * idea as the in-quiz answer shortcuts. Harmless on touch-only devices
 * (no keyboard, no keydown events) - the visible keycap hints are hidden
 * there via CSS since they'd otherwise just be confusing clutter.
 */
export default class extends Controller {
    static targets = ['link'];

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing) {
            return;
        }

        const index = event.key === '0' ? 9 : parseInt(event.key, 10) - 1;
        if (Number.isNaN(index) || index < 0 || index > 9) {
            return;
        }

        const link = this.linkTargets[index];
        if (!link) {
            return;
        }

        event.preventDefault();
        link.click();
    }
}
