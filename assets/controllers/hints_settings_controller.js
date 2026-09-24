import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial_hints';

function readState() {
    try {
        const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        return { enabled: raw?.enabled !== false, seen: raw?.seen && typeof raw.seen === 'object' ? raw.seen : {} };
    } catch {
        return { enabled: true, seen: {} };
    }
}

function writeState(state) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // Private mode / quota - the choice then only lasts for this page view.
    }
}

/**
 * Profile page: switch the guided hints (see hints_controller.js) on/off and
 * reset which pages already showed theirs. Same localStorage key/shape.
 */
export default class extends Controller {
    static targets = ['toggle', 'status'];
    static values = { replayedText: String };

    connect() {
        this.toggleTarget.checked = readState().enabled;
    }

    toggle() {
        const state = readState();
        state.enabled = this.toggleTarget.checked;
        writeState(state);
        this.statusTarget.textContent = '';
    }

    replay() {
        const state = readState();
        state.enabled = true;
        state.seen = {};
        writeState(state);
        this.toggleTarget.checked = true;
        this.statusTarget.textContent = this.replayedTextValue;
    }
}
