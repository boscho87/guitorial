import { Controller } from '@hotwired/stimulus';

/**
 * "Still there" heartbeat for a manual/external exercise session's running
 * page - unlike the 5 graded tools' rounds (practice-sync controller), this
 * session type otherwise has no sync at all. Lets
 * PracticeSession::finish() cap the credited duration once the tab stops
 * sending these (closed, backgrounded, laptop asleep, ...) long before an
 * eventual, much later finish/discard click.
 *
 * Also stops pinging while timer_controller.js's "are you still there?"
 * check-in is unconfirmed past its grace period (timer:pause/timer:resume,
 * dispatched from the shared element) - a tab can stay open and visible,
 * which the document.hidden check below never catches on its own.
 */
export default class extends Controller {
    static values = {
        url: String,
        resumeUrl: String,
        token: String,
        intervalMs: { type: Number, default: 20000 },
        resourceGraceMinutes: { type: Number, default: 15 },
    };

    connect() {
        this.checkinPaused = false;
        this.resourceOpenedAtMs = null;
        // Fired by external_resource_controller.js from a resource link
        // elsewhere on the page - see timer_controller.js's own copy of
        // this for why it's a plain document event, not an action target.
        this.onResourceOpened = () => {
            this.resourceOpenedAtMs = Date.now();
            this.ping();
        };
        document.addEventListener('resource:opened', this.onResourceOpened);
        // setInterval's first tick only fires after a full intervalMsValue,
        // not immediately - leaving within that first ~20s (e.g. straight
        // back to Heute right after starting) would otherwise mean zero
        // heartbeats ever landed, and PracticeSession::cappedFinishTimestamp()
        // can't tell that apart from "JS never ran at all", so it falls back
        // to the loose 180-minute safety net instead of the tight 90s grace -
        // the away-gap goes completely uncapped. Pinging immediately closes
        // that window down to a network round-trip instead of ~20 seconds.
        this.ping();
        this.timer = setInterval(() => this.ping(), this.intervalMsValue);

        // The browser's own back-forward cache can freeze this whole page -
        // JS heap, running setInterval and all - on navigating away, then
        // resume it exactly as it was on a literal back/forward, without
        // ever re-running a script or hitting the server. No amount of
        // server-side capping or Turbo cache-control (a *different* cache)
        // helps then, since nothing re-renders - the already-running clock
        // just keeps ticking from its old in-memory start value against the
        // real current time. pageshow's persisted flag is the standard way
        // to detect that and force a genuine fresh reload instead.
        this.onPageShow = (event) => {
            if (event.persisted) {
                window.location.reload();
            }
        };
        window.addEventListener('pageshow', this.onPageShow);

        // Switching tabs/apps without ever navigating away inside Guitorial
        // triggers neither a reload nor any request at all - the interval
        // above keeps running in the background regardless (browsers just
        // throttle it), which would keep updatedAt fresh the whole time and
        // defeat the server-side cap entirely. Stop pinging while hidden so
        // "away" means the same thing here as it does for a real
        // navigation, and ping immediately on return (same reasoning as the
        // initial ping above) so the next cap calculation is accurate right
        // away instead of up to intervalMsValue stale.
        this.onVisibilityChange = () => {
            if (!document.hidden) {
                this.ping();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibilityChange);
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
        }
        window.removeEventListener('pageshow', this.onPageShow);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        document.removeEventListener('resource:opened', this.onResourceOpened);
    }

    withinResourceGrace() {
        return this.resourceOpenedAtMs !== null && Date.now() - this.resourceOpenedAtMs < this.resourceGraceMinutesValue * 60000;
    }

    ping() {
        if ((document.hidden && !this.withinResourceGrace()) || this.checkinPaused) {
            return;
        }
        fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            keepalive: true,
            body: `_token=${encodeURIComponent(this.tokenValue)}`,
        }).catch(() => {
            // Best-effort - a missed heartbeat just means a slightly tighter cap later.
        });
    }

    // Shared by the check-in pause and the manual "P" pause (both dispatch
    // timer:pause/timer:resume). One last ping before stopping pins the
    // server's updatedAt to ~the moment the pause began, so the resume
    // endpoint can subtract exactly the paused time
    // (PracticeSession::resumeAfterPause()).
    pauseForCheckin() {
        this.ping();
        this.checkinPaused = true;
    }

    resumeFromCheckin() {
        // Heartbeats stay suppressed until the resume request has landed - a
        // regular ping racing ahead of it would bump updatedAt first and
        // leave the server no gap to subtract.
        fetch(this.resumeUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            keepalive: true,
            body: `_token=${encodeURIComponent(this.tokenValue)}`,
        })
            .catch(() => {
                // Best-effort - worst case the paused gap is credited, same as before.
            })
            .finally(() => {
                this.checkinPaused = false;
            });
    }
}
