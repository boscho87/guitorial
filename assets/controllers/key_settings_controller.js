import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial:key-recognition:settings';

/**
 * Lets the "Tonart erkennen" settings screen remember the last-used
 * selection in the browser (so returning users don't have to reconfigure)
 * and offers one-click presets (grouped by how often each key actually
 * comes up on guitar) to lower the barrier to start.
 */
export default class extends Controller {
    static targets = ['checkbox', 'questionCount'];

    connect() {
        this.restore();
    }

    restore() {
        let saved;
        try {
            saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch {
            saved = null;
        }
        if (!saved) {
            return;
        }

        if (Array.isArray(saved.pairs) && saved.pairs.length > 0) {
            const selected = new Set(saved.pairs);
            this.checkboxTargets.forEach((checkbox) => {
                checkbox.checked = selected.has(checkbox.value);
            });
        }
        if (saved.questionCount && this.hasQuestionCountTarget) {
            this.questionCountTarget.value = saved.questionCount;
        }
    }

    persist() {
        const pairs = this.checkboxTargets.filter((c) => c.checked).map((c) => c.value);
        const questionCount = this.hasQuestionCountTarget ? this.questionCountTarget.value : null;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ pairs, questionCount }));
        } catch {
            // localStorage unavailable (private mode, quota) - just skip remembering.
        }
    }

    applyPreset(event) {
        const preset = new Set(event.currentTarget.dataset.pairs.split(','));
        this.checkboxTargets.forEach((checkbox) => {
            checkbox.checked = preset.has(checkbox.value);
        });
        this.persist();
    }
}
