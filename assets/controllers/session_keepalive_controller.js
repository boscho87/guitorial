import { Controller } from '@hotwired/stimulus';

/**
 * Global "still here" ping so a page left open and idle - reading, typing
 * a long note, ... - doesn't quietly lose its PHP session to the host's
 * out-of-band session cleanup (session.gc_maxlifetime is far shorter than
 * people realistically leave a tab open for). Once that happens, the CSRF
 * token already baked into any open form's _token field stops matching -
 * see SessionKeepAliveController's doc comment for the full mechanism.
 * Attached once, globally, on <body> (base.html.twig) - every page gets
 * this for free, not just ones with their own heartbeat (session-heartbeat
 * is a different, narrower mechanism scoped to the exercise-running page's
 * elapsed-time tracking).
 */
export default class extends Controller {
    static values = {
        url: { type: String, default: '/api/session-keepalive' },
        intervalMs: { type: Number, default: 300000 },
    };

    connect() {
        this.ping();
        this.timer = setInterval(() => this.ping(), this.intervalMsValue);

        // Same bfcache gotcha as session_heartbeat_controller.js - a frozen
        // page resumed via back/forward never re-runs connect(), so the
        // timer above would just keep ticking against a since-stale session.
        this.onPageShow = (event) => {
            if (event.persisted) {
                this.ping();
            }
        };
        window.addEventListener('pageshow', this.onPageShow);

        // No point pinging while nobody's looking - same reasoning as
        // session_heartbeat_controller.js. Ping immediately on return so a
        // submit right after switching back doesn't race a session that's
        // already gone stale while hidden.
        this.onVisibilityChange = () => {
            if (!document.hidden) {
                this.ping();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibilityChange);
    }

    disconnect() {
        clearInterval(this.timer);
        window.removeEventListener('pageshow', this.onPageShow);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    ping() {
        if (document.hidden) {
            return;
        }
        fetch(this.urlValue, { method: 'GET', keepalive: true, credentials: 'same-origin', cache: 'no-store' }).catch(() => {
            // Best-effort - a missed ping just means a slightly earlier timeout.
        });
    }
}
