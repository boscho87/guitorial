import { Controller } from '@hotwired/stimulus';

/**
 * Monkeytype-style "just hit a key to go again": Enter submits the
 * restart form on the round summary screen.
 */
export default class extends Controller {
    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.key !== 'Enter' || event.metaKey || event.ctrlKey || event.altKey || event.isComposing) {
            return;
        }
        if (event.target.tagName === 'BUTTON' || event.target.tagName === 'A') {
            // Already-focused button/link handles its own Enter activation.
            return;
        }
        event.preventDefault();
        this.element.requestSubmit();
    }
}
