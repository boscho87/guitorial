import { Controller } from '@hotwired/stimulus';

const COOKIE = 'guitorial_sort';

/**
 * Profile: how "Heute" orders the active routine - "custom" (the routine's
 * own order, default) or "priority" (recommended). Heute is rendered on the
 * server, so this is a plain cookie the server can read (not localStorage,
 * which only the browser sees). One year, same-site.
 */
export default class extends Controller {
    static targets = ['option'];

    connect() {
        const current = document.cookie
            .split('; ')
            .find((entry) => entry.startsWith(`${COOKIE}=`))
            ?.split('=')[1];
        const value = current === 'priority' ? 'priority' : 'custom';
        this.optionTargets.forEach((option) => {
            option.checked = option.value === value;
        });
    }

    change(event) {
        const value = event.target.value === 'priority' ? 'priority' : 'custom';
        document.cookie = `${COOKIE}=${value}; path=/; max-age=31536000; samesite=lax`;
    }
}
