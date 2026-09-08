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
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    choose(event) {
        this.highlight(event.currentTarget);
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing) {
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
        this.highlight(button);
        button.click();
    }

    highlight(button) {
        this.answerTargets.forEach((el) => el.classList.remove('quiz-answer-chosen'));
        button.classList.add('quiz-answer-chosen');
    }
}
