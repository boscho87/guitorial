import { Controller } from '@hotwired/stimulus';

/**
 * Lets a settings screen (Tonart erkennen, Funktion bestimmen, ...)
 * remember the last-used selection in the browser (so returning users
 * don't have to reconfigure) and offers one-click presets to lower the
 * barrier to start. storageKeyValue keeps each tool's memory separate.
 */
export default class extends Controller {
    static targets = ['checkbox', 'questionCount', 'modeRadio'];
    static values = { storageKey: { type: String, default: 'guitorial:key-recognition:settings' } };

    connect() {
        this.restore();
    }

    restore() {
        let saved;
        try {
            saved = JSON.parse(localStorage.getItem(this.storageKeyValue) || 'null');
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
        if (saved.mode && this.hasModeRadioTarget) {
            this.modeRadioTargets.forEach((radio) => {
                radio.checked = radio.value === saved.mode;
            });
        }
    }

    persist() {
        const pairs = this.checkboxTargets.filter((c) => c.checked).map((c) => c.value);
        const questionCount = this.hasQuestionCountTarget ? this.questionCountTarget.value : null;
        const checkedMode = this.hasModeRadioTarget ? this.modeRadioTargets.find((r) => r.checked) : null;
        try {
            localStorage.setItem(
                this.storageKeyValue,
                JSON.stringify({ pairs, questionCount, mode: checkedMode ? checkedMode.value : null }),
            );
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
