import { Controller } from '@hotwired/stimulus';

/**
 * Native window.confirm() gate before a destructive form submits (discard a
 * session, hard-delete an exercise/group, wipe history) - the simplest
 * possible confirmation mechanic, no new modal system.
 */
export default class extends Controller {
    static values = { message: String };

    submit(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
