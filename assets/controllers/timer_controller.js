import { Controller } from '@hotwired/stimulus';

/**
 * Live-ticking m:ss elapsed-time display for a practice round, purely
 * informational (Monkeytype-style) - not used for grading.
 */
export default class extends Controller {
    static values = { start: Number };

    connect() {
        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    tick() {
        const elapsed = Math.max(0, Math.floor((Date.now() - this.startValue) / 1000));
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        this.element.textContent = `${minutes}:${String(seconds).padStart(2, '0')}`;
    }
}
