import { Controller } from '@hotwired/stimulus';

/**
 * Desktop keyboard shortcuts for the exercise-running page: P pauses/
 * resumes the timer, 1/2/3 finish the session with the matching learning
 * state (same order as the three buttons). Ignored while typing in a field
 * (the note textarea) or while a dialog (check-in, discard confirmation) is
 * open. Keys pressed while the embedded YouTube player has focus never
 * reach the page - that's the iframe's own document.
 */
export default class extends Controller {
    static targets = ['pause', 'state'];

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing || event.repeat) {
            return;
        }
        if (this.isTypingTarget(event.target) || document.querySelector('dialog[open]')) {
            return;
        }

        if (event.key === 'p' || event.key === 'P') {
            if (this.hasPauseTarget) {
                event.preventDefault();
                this.pauseTarget.click();
            }
            return;
        }

        const index = ['1', '2', '3'].indexOf(event.key);
        if (index !== -1 && this.stateTargets[index]) {
            event.preventDefault();
            this.stateTargets[index].click();
        }
    }

    isTypingTarget(target) {
        return target instanceof HTMLElement
            && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
    }
}
