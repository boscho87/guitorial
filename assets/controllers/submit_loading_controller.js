import { Controller } from '@hotwired/stimulus';

/**
 * Dims the submit button and adds a small spinner while a form's request
 * is in flight - Turbo Drive's navigation can take a moment, and without
 * this a second click before the page changes silently double-submits.
 * `spinner: false` skips the injected icon for small icon-only buttons
 * (e.g. the round play/checkmark buttons), where it would visually
 * collide with the existing icon instead of reading as a loading state.
 */
export default class extends Controller {
    static values = { spinner: { type: Boolean, default: true } };

    submit(event) {
        if (event.defaultPrevented) {
            // Another handler on the same submit event (e.g. confirm-submit,
            // when the user cancelled) already stopped the real submission -
            // nothing is actually in flight.
            return;
        }
        const button = event.submitter || this.element.querySelector('button[type="submit"]');
        if (!button || button.disabled) {
            return;
        }
        button.disabled = true;
        button.classList.add('opacity-60', 'cursor-wait');
        if (this.spinnerValue) {
            button.insertAdjacentHTML('beforeend', '<span class="loading loading-spinner loading-xs ml-1.5" aria-hidden="true"></span>');
        }
    }
}
