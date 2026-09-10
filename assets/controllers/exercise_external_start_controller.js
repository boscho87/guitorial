import { Controller } from '@hotwired/stimulus';

/**
 * Starting an external-resource exercise has to do two things from one
 * click: open the resource in a new tab (must happen synchronously in the
 * click handler, or popup blockers kill it) and create the PracticeSession
 * server-side, then move the current tab to the running/timer page - a
 * plain form submit or link can't do both, so this posts in the background.
 */
export default class extends Controller {
    static values = { url: String, startUrl: String, token: String };

    start(event) {
        event.preventDefault();
        window.open(this.urlValue, '_blank', 'noopener');

        fetch(this.startUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `_token=${encodeURIComponent(this.tokenValue)}`,
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.runningUrl) {
                    window.location.href = data.runningUrl;
                }
            })
            .catch(() => {
                // The external tab already opened either way - only the
                // timer/tracking page navigation is lost here.
            });
    }
}
