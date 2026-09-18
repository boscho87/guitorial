import { Controller } from '@hotwired/stimulus';

/**
 * A read-only share link: click to select it, or use the button to copy
 * it straight to the clipboard (falls back to just selecting the text if
 * the clipboard API is unavailable, e.g. non-HTTPS local dev).
 */
export default class extends Controller {
    static targets = ['input'];

    select() {
        this.inputTarget.select();
    }

    copy() {
        this.inputTarget.select();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(this.inputTarget.value).catch(() => {});
        }
    }
}
