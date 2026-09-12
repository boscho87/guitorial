import { Controller } from '@hotwired/stimulus';

/**
 * Mobile hamburger menu. The panel is a fixed full-screen overlay (see
 * markup) rather than a dropdown, so it needs its own open/close state
 * instead of relying on :hover/native <details>. Turbo Drive replaces the
 * whole <body> on a normal link navigation, so the panel always re-renders
 * closed after clicking a nav link - no explicit close-on-navigate needed.
 */
export default class extends Controller {
    static targets = ['panel', 'button', 'openIcon', 'closeIcon'];

    toggle() {
        this.panelTarget.classList.contains('hidden') ? this.open() : this.close();
    }

    open() {
        this.panelTarget.classList.remove('hidden');
        this.panelTarget.classList.add('flex');
        this.openIconTarget.classList.add('hidden');
        this.closeIconTarget.classList.remove('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        document.body.classList.add('overflow-hidden');
    }

    close() {
        this.panelTarget.classList.add('hidden');
        this.panelTarget.classList.remove('flex');
        this.openIconTarget.classList.remove('hidden');
        this.closeIconTarget.classList.add('hidden');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('overflow-hidden');
    }

    disconnect() {
        document.body.classList.remove('overflow-hidden');
    }
}
