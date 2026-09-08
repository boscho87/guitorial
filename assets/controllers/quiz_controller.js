import { Controller } from '@hotwired/stimulus';

/**
 * Shared by every practice tool's answer grid: highlights the clicked
 * answer immediately, before the Turbo Frame request finishes.
 */
export default class extends Controller {
    static targets = ['answer'];

    choose(event) {
        this.answerTargets.forEach((el) => el.classList.remove('quiz-answer-chosen'));
        event.currentTarget.classList.add('quiz-answer-chosen');
    }
}
