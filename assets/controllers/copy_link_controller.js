import { Controller } from '@hotwired/stimulus';

/**
 * A read-only share link: click to select it, or use the button to copy
 * it straight to the clipboard (falls back to just selecting the text if
 * the clipboard API is unavailable, e.g. non-HTTPS local dev). The button
 * briefly confirms the copy actually happened - silently succeeding
 * clipboard writes otherwise give no feedback at all.
 */
export default class extends Controller {
    static targets = ['input', 'button'];

    select() {
        this.inputTarget.select();
    }

    copy() {
        this.inputTarget.select();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(this.inputTarget.value).then(() => this.confirmCopied()).catch(() => {});
        }
    }

    confirmCopied() {
        if (!this.hasButtonTarget) {
            return;
        }
        clearTimeout(this.resetTimeout);
        const original = this.buttonTarget.dataset.copyLinkOriginalLabel ?? this.buttonTarget.textContent;
        this.buttonTarget.dataset.copyLinkOriginalLabel = original;
        this.buttonTarget.textContent = this.buttonTarget.dataset.copyLinkConfirmedLabel ?? original;
        this.buttonTarget.classList.add('text-success');
        this.resetTimeout = setTimeout(() => {
            this.buttonTarget.textContent = original;
            this.buttonTarget.classList.remove('text-success');
        }, 1500);
    }

    disconnect() {
        clearTimeout(this.resetTimeout);
    }
}
