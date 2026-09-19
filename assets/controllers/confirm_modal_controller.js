import { Controller } from '@hotwired/stimulus';

/**
 * A native <dialog>-based confirmation, replacing window.confirm() for
 * destructive actions - styled to match the app instead of the browser's
 * own dialog chrome. showModal() already gives us a focus trap and
 * Escape-to-close for free; only open/close need wiring up.
 */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }
}
