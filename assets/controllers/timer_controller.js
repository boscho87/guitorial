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
 *
 * Separately, an optional `bar` target renders THIS round's own elapsed-
 * vs-targetSeconds progress as a warm-to-cool gradient fill (same hue
 * formula as the diary card's), with a brief "pulse" restarted every
 * time a new full minute ticks over - unrelated to the diary daily-
 * target progress above.
 *
 * Also separately, an optional `checkinDialog` target (wired up only when
 * checkinIntervalMinutesValue is present) shows an "are you still there?"
 * confirmation every N minutes once elapsed passes targetSecondsValue - a
 * tab can stay open and visible, heartbeats still firing, long after
 * someone actually walked away, which the hidden-tab pause below never
 * catches on its own. Scheduled against real wall-clock time (not the
 * paused-adjusted elapsed below), so it still fires while the tab is in
 * the background - that's exactly when the title-flash/notification
 * matters. Not confirming within checkinGraceSecondsValue is just a
 * second REASON to pause, folded into the exact same single pausedSince/
 * hiddenMs bookkeeping the hidden-tab case already uses (via
 * updatePauseState() below) rather than a second, separately-tracked
 * pause window - two independently-tracked "since" timestamps covering
 * overlapping spans (e.g. grace expires while already hidden, or the tab
 * is hidden again before a pending check-in is confirmed) would double-
 * count that overlap and under-credit real active time once they
 * confirm. dispatches timer:pause/timer:resume (only for the check-in
 * reason - the hidden-tab reason already pauses session_heartbeat_
 * controller.js independently via its own document.hidden check) so it
 * stops/resumes pinging - reuses the existing heartbeat-staleness capping
 * (PracticeSession::cappedFinishTimestamp()) instead of needing any new
 * server-side logic.
 */
export default class extends Controller {
    static targets = ['clock', 'progress', 'progressLabel', 'bar', 'checkinDialog', 'pauseButton', 'pauseLabel'];
    static values = {
        pauseText: String,
        resumeText: String,
        start: Number,
        targetSeconds: Number,
        progressStartSeconds: Number,
        progressLabelTemplate: String,
        pauseWhenHidden: Boolean,
        checkinIntervalMinutes: Number,
        checkinGraceSeconds: { type: Number, default: 90 },
        checkinFlashTitle: String,
        checkinNotificationBody: String,
        resourceGraceMinutes: { type: Number, default: 15 },
    };

    connect() {
        this.beeped = false;
        this.connectedAtMs = Date.now();
        this.lastMilestoneMinute = 0;
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
        this.pausedSince = this.pauseWhenHiddenValue && document.hidden ? Date.now() : null;
        this.awaitingCheckin = false;
        this.checkinGraceExpired = false;
        this.manuallyPaused = false;
        // Opening a linked resource (a new tab) backgrounds this tab just
        // like walking away does - grant a grace window afterwards so
        // reading/watching along doesn't itself pause the timer. See
        // external_resource_controller.js, which fires this from a
        // different controller scope (the resource link, not this element).
        this.resourceOpenedAtMs = null;
        this.onResourceOpened = () => this.markResourceOpened();
        document.addEventListener('resource:opened', this.onResourceOpened);
        this.nextCheckinAtMs = this.hasCheckinIntervalMinutesValue && this.hasTargetSecondsValue
            ? this.startValue + this.targetSecondsValue * 1000 + this.checkinIntervalMinutesValue * 60000
            : null;

        if (this.pauseWhenHiddenValue) {
            this.onVisibilityChange = () => {
                this.updatePauseState();
                if (document.hidden) {
                    // Switching away while a check-in is still pending is
                    // exactly when the nudge matters most.
                    if (this.awaitingCheckin) {
                        this.startFlashingTitle();
                        this.notify();
                    }
                } else {
                    // Seeing the dialog is enough to stop the flash - the
                    // actual pause only lifts once they click confirm.
                    this.stopFlashingTitle();
                }
            };
            document.addEventListener('visibilitychange', this.onVisibilityChange);
        }

        this.tick();
        this.interval = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.interval);
        clearTimeout(this.graceTimeout);
        clearTimeout(this.resourceGraceTimeout);
        this.stopFlashingTitle();
        if (this.onVisibilityChange) {
            document.removeEventListener('visibilitychange', this.onVisibilityChange);
        }
        document.removeEventListener('resource:opened', this.onResourceOpened);
    }

    markResourceOpened() {
        this.resourceOpenedAtMs = Date.now();
        this.updatePauseState();
        // Nothing else re-evaluates the pause state while hidden (tick()
        // only reads it, visibilitychange won't fire again until they come
        // back) - without this, staying away past the grace still wouldn't
        // start counting as paused until they returned.
        clearTimeout(this.resourceGraceTimeout);
        this.resourceGraceTimeout = setTimeout(() => this.updatePauseState(), this.resourceGraceMinutesValue * 60000);
    }

    withinResourceGrace() {
        return this.resourceOpenedAtMs !== null && Date.now() - this.resourceOpenedAtMs < this.resourceGraceMinutesValue * 60000;
    }

    /**
     * Single source of truth for whether the elapsed-time accounting is
     * currently paused, and why - re-evaluated on every visibility change
     * and check-in state change instead of each reason keeping its own
     * "since" timestamp, so overlapping pause windows (hidden AND an
     * expired check-in at once) never get counted twice.
     */
    updatePauseState() {
        const shouldBePaused = (this.pauseWhenHiddenValue && document.hidden && !this.withinResourceGrace()) || this.checkinGraceExpired || this.manuallyPaused;
        if (shouldBePaused && this.pausedSince === null) {
            this.pausedSince = Date.now();
        } else if (!shouldBePaused && this.pausedSince !== null) {
            this.hiddenMs += Date.now() - this.pausedSince;
            this.pausedSince = null;
        }
    }

    /**
     * Explicit user pause ("P" or the pause button). Dispatches
     * timer:pause/timer:resume only when the overall paused state actually
     * flips, so a manual resume while a check-in pause (or the reverse) is
     * still in effect doesn't wrongly restart the heartbeat.
     */
    togglePause() {
        const wasPaused = this.pausedSince !== null;
        this.manuallyPaused = !this.manuallyPaused;
        this.updatePauseState();
        this.renderPauseState();
        const isPaused = this.pausedSince !== null;
        if (isPaused && !wasPaused) {
            this.dispatch('pause');
        } else if (!isPaused && wasPaused) {
            this.dispatch('resume');
        }
        this.tick();
    }

    renderPauseState() {
        if (this.hasPauseLabelTarget) {
            this.pauseLabelTarget.textContent = this.manuallyPaused ? this.resumeTextValue : this.pauseTextValue;
        }
        if (this.hasPauseButtonTarget) {
            this.pauseButtonTarget.setAttribute('aria-pressed', this.manuallyPaused ? 'true' : 'false');
        }
        const clock = this.hasClockTarget ? this.clockTarget : this.element;
        clock.classList.toggle('opacity-50', this.manuallyPaused);
    }

    tick() {
        const pausedMs = this.hiddenMs + (this.pausedSince !== null ? Date.now() - this.pausedSince : 0);
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

        if (this.hasBarTarget && this.hasTargetSecondsValue && this.targetSecondsValue > 0) {
            const percent = Math.min(100, Math.round((elapsed / this.targetSecondsValue) * 100));
            const hue = Math.round(30 + ((148 - 30) * percent) / 100);
            this.barTarget.style.width = `${percent}%`;
            this.barTarget.style.backgroundColor = `hsl(${hue} 75% 48%)`;

            const currentMinute = Math.floor(elapsed / 60);
            if (currentMinute > this.lastMilestoneMinute) {
                this.lastMilestoneMinute = currentMinute;
                this.pulseMilestone();
            }
        }

        if (this.hasTargetSecondsValue && !this.beeped && elapsed >= this.targetSecondsValue) {
            this.beeped = true;
            this.beep();
        }

        if (this.nextCheckinAtMs !== null && !this.awaitingCheckin && Date.now() >= this.nextCheckinAtMs) {
            this.openCheckin();
        }
    }

    openCheckin() {
        if (!this.hasCheckinDialogTarget) {
            return;
        }
        this.awaitingCheckin = true;
        this.notified = false;
        this.checkinDialogTarget.showModal();
        if (document.hidden) {
            this.startFlashingTitle();
            this.notify();
        }
        this.graceTimeout = setTimeout(() => {
            this.checkinGraceExpired = true;
            this.updatePauseState();
            this.dispatch('pause');
        }, this.checkinGraceSecondsValue * 1000);
    }

    preventCancel(event) {
        // Escape fires this before closing a <dialog> - block it so the
        // check-in can only be dismissed via the actual confirm button.
        event.preventDefault();
    }

    confirmCheckin() {
        this.awaitingCheckin = false;
        this.checkinGraceExpired = false;
        this.checkinDialogTarget.close();
        clearTimeout(this.graceTimeout);
        this.stopFlashingTitle();
        const wasPaused = this.pausedSince !== null;
        this.updatePauseState();
        if (wasPaused && this.pausedSince === null) {
            this.dispatch('resume');
        }
        this.nextCheckinAtMs = Date.now() + this.checkinIntervalMinutesValue * 60000;
    }

    startFlashingTitle() {
        if (this.titleFlashInterval || !this.hasCheckinFlashTitleValue) {
            return;
        }
        this.originalTitle = document.title;
        this.titleFlashInterval = setInterval(() => {
            document.title = document.title === this.originalTitle ? this.checkinFlashTitleValue : this.originalTitle;
        }, 1000);
    }

    stopFlashingTitle() {
        if (!this.titleFlashInterval) {
            return;
        }
        clearInterval(this.titleFlashInterval);
        this.titleFlashInterval = null;
        document.title = this.originalTitle;
    }

    notify() {
        if (this.notified || !('Notification' in window) || !this.hasCheckinNotificationBodyValue) {
            return;
        }
        const show = () => {
            this.notified = true;
            try {
                new Notification(this.originalTitle || document.title, { body: this.checkinNotificationBodyValue });
            } catch {
                // Construction can still fail (e.g. platform requires a service worker) - the title flash already covers it.
            }
        };
        if (Notification.permission === 'granted') {
            show();
        } else if (Notification.permission === 'default') {
            // Only ever requested here, lazily, on the first real check-in -
            // never on page load, so a round that never runs over its
            // target never sees a permission prompt at all.
            Notification.requestPermission().then((permission) => {
                if (permission === 'granted') {
                    show();
                }
            });
        }
    }

    pulseMilestone() {
        this.barTarget.classList.remove('milestone-pulse');
        void this.barTarget.offsetWidth; // eslint-disable-line no-unused-expressions -- forces reflow so the animation restarts
        this.barTarget.classList.add('milestone-pulse');
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
