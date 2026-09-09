import { Controller } from '@hotwired/stimulus';

/**
 * Auto-submits the form it's attached to after secondsValue seconds - e.g.
 * "30s per tone" in the scale generator. Re-attaches fresh (new timer) each
 * time the turbo-frame swaps in a new question; secondsValue of 0 disables it.
 */
export default class extends Controller {
    static values = { seconds: Number };

    connect() {
        if (this.secondsValue <= 0) {
            return;
        }
        this.timer = setTimeout(() => this.element.requestSubmit(), this.secondsValue * 1000);
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
        }
    }
}
