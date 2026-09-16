import { Controller } from '@hotwired/stimulus';

/**
 * "Still there" heartbeat for a manual/external exercise session's running
 * page - unlike the 5 graded tools' rounds (practice-sync controller), this
 * session type otherwise has no sync at all. Lets
 * PracticeSession::finish() cap the credited duration once the tab stops
 * sending these (closed, backgrounded, laptop asleep, ...) long before an
 * eventual, much later finish/discard click.
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
        intervalMs: { type: Number, default: 20000 },
    };

    connect() {
        this.timer = setInterval(() => this.ping(), this.intervalMsValue);
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    }

    ping() {
        fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            keepalive: true,
            body: `_token=${encodeURIComponent(this.tokenValue)}`,
        }).catch(() => {
            // Best-effort - a missed heartbeat just means a slightly tighter cap later.
        });
    }
}
