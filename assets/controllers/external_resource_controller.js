import { Controller } from '@hotwired/stimulus';

/**
 * Marks that an external resource link was just opened (new tab), so the
 * timer/heartbeat elsewhere on the page (a different controller scope, out
 * of reach for a plain Stimulus action target) can grant a grace window
 * before treating the resulting tab-hidden as a real pause - see
 * timer_controller.js/session_heartbeat_controller.js's markResourceOpened.
 * A plain bubbling document event rather than an action target, since this
 * link and the timer live in different controller scopes.
 */
export default class extends Controller {
    opened() {
        document.dispatchEvent(new CustomEvent('resource:opened'));
    }
}
