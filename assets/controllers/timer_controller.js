import { Controller } from '@hotwired/stimulus';

/**
 * Live-ticking m:ss elapsed-time display for a practice round, purely
 * informational (Monkeytype-style) - not used for grading. Optionally, once
 * elapsed reaches targetSecondsValue, fires a single Web Audio beep (a
 * synthesized tone, not a shipped audio file) - used by the exercise
 * running page to signal a target time was reached without forcing an
 * auto-completion.
 *
 * Optionally also ticks a diary daily-target <progress> bar + its text
 * label live, second by second, instead of only moving once per question
 * answered (a server round-trip) or jumping in whole-minute steps.
 * progressStartSecondsValue is the server's already-capped "today, toward
 * the target" total as of this page load (see ExerciseTargetProgress -
 * capped at the round's last practice-sync heartbeat, not wall-clock time,
 * so an abandoned round doesn't look like it kept running). This only adds
 * time spent on THIS page view on top (measured from connect(), not from
 * the round's original start) - deliberately NOT the same elapsed value as
 * the round clock above, which intentionally does count time since the
 * round's original start. Only wired up where a `progress`/`progressLabel`
 * target is actually present - every other usage of this controller is
 * unaffected.
 */
export default class extends Controller {
    static targets = ['clock', 'progress', 'progressLabel'];
    static values = { start: Number, targetSeconds: Number, progressStartSeconds: Number, progressLabelTemplate: String };

    connect() {
        this.beeped = false;
        this.connectedAtMs = Date.now();
        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    tick() {
        const elapsed = Math.max(0, Math.floor((Date.now() - this.startValue) / 1000));
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        const clock = this.hasClockTarget ? this.clockTarget : this.element;
        clock.textContent = `${minutes}:${String(seconds).padStart(2, '0')}`;

        if (this.hasProgressTarget && this.hasProgressStartSecondsValue) {
            const sinceThisPageview = Math.max(0, Math.floor((Date.now() - this.connectedAtMs) / 1000));
            const totalSeconds = this.progressStartSecondsValue + sinceThisPageview;
            this.progressTarget.value = totalSeconds;
            if (this.hasProgressLabelTarget && this.hasProgressLabelTemplateValue) {
                this.progressLabelTarget.textContent = this.progressLabelTemplateValue.replace('{m}', Math.floor(totalSeconds / 60));
            }
        }

        if (this.hasTargetSecondsValue && !this.beeped && elapsed >= this.targetSecondsValue) {
            this.beeped = true;
            this.beep();
        }
    }

    beep() {
        try {
            const context = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.frequency.value = 880;
            gain.gain.setValueAtTime(0.15, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.5);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.5);
        } catch {
            // Web Audio unavailable/blocked - the visual timer already shows the target was reached.
        }
    }
}
