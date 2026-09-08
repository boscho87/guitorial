import { Controller } from '@hotwired/stimulus';

/**
 * Shared by every practice tool's answer grid: highlights the clicked (or
 * key-pressed) answer immediately, and lets the first 10 answers be chosen
 * with the 1-9/0 number keys instead of a tap/click - Monkeytype-style,
 * keyboard first.
 */
export default class extends Controller {
    static targets = ['answer'];

    connect() {
        this.submitted = false;
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    choose(event) {
        // A duplicate submit (double-tap, flaky network) would otherwise
        // grade the *next* question against this answer once it arrives.
        if (this.submitted) {
            event.preventDefault();
            return;
        }
        this.submitted = true;
        this.highlight(event.currentTarget);
        // Let this click's own submit go through before disabling it too.
        setTimeout(() => this.disableAllExcept(event.currentTarget), 0);
    }

    onKeydown(event) {
        if (this.submitted || event.metaKey || event.ctrlKey || event.altKey || event.isComposing) {
            return;
        }

        const index = event.key === '0' ? 9 : parseInt(event.key, 10) - 1;
        if (Number.isNaN(index) || index < 0 || index > 9) {
            return;
        }

        const button = this.answerTargets[index];
        if (!button) {
            return;
        }

        event.preventDefault();
        // Dispatches a real click, which choose() (bound on the same
        // button) handles identically to a tap/mouse click.
        button.click();
    }

    highlight(button) {
        this.answerTargets.forEach((el) => el.classList.remove('quiz-answer-chosen'));
        button.classList.add('quiz-answer-chosen');
    }

    disableAllExcept(chosenButton) {
        this.answerTargets.forEach((el) => {
            if (el !== chosenButton) {
                el.disabled = true;
            }
        });
    }
}
