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
    static values = { start: Number, targetSeconds: Number, progressStartSeconds: Number, progressLabelTemplate: String, pauseWhenHidden: Boolean };

    connect() {
        this.beeped = false;
        this.connectedAtMs = Date.now();
        // Opt-in only (pauseWhenHiddenValue) - the 5 tools' rounds
        // deliberately keep counting real wall-clock time regardless of tab
        // visibility (see the class docblock). The exercise-session running
        // page opts in: switching tabs/apps without ever navigating away in
        // Guitorial itself doesn't trigger any request at all - no server
        // recalculation, no Turbo/bfcache reload - so the display has to
        // freeze itself via the Page Visibility API instead of just
        // computing Date.now() - startValue, or it visibly counts the whole
        // time spent elsewhere once the tab is foregrounded again.
        this.hiddenMs = 0;
        this.hiddenSince = this.pauseWhenHiddenValue && document.hidden ? Date.now() : null;
        if (this.pauseWhenHiddenValue) {
            this.onVisibilityChange = () => {
                if (document.hidden) {
                    this.hiddenSince = Date.now();
                } else if (this.hiddenSince !== null) {
                    this.hiddenMs += Date.now() - this.hiddenSince;
                    this.hiddenSince = null;
                }
            };
            document.addEventListener('visibilitychange', this.onVisibilityChange);
        }
        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
        if (this.onVisibilityChange) {
            document.removeEventListener('visibilitychange', this.onVisibilityChange);
        }
    }

    tick() {
        const pausedMs = this.hiddenMs + (this.hiddenSince !== null ? Date.now() - this.hiddenSince : 0);
        const elapsed = Math.max(0, Math.floor((Date.now() - this.startValue - pausedMs) / 1000));
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
