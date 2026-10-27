import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial_manage_tab';

/**
 * Switches between the three pre-rendered exercise lists (active/deactivated/
 * archived) without a page reload - same pattern as history_tabs_controller.js,
 * just over diary/manage.html.twig's sections instead of chart panels.
 *
 * Remembers the last-opened tab in sessionStorage (a per-viewer convenience,
 * not shared state) so an action that reloads the page - e.g. reactivating
 * an exercise from "Deaktiviert" - lands back on that same tab instead of
 * always snapping to "Aktiv", which is what the server always renders as
 * checked.
 */
export default class extends Controller {
    static targets = ['panel', 'radio'];

    connect() {
        let tab;
        try {
            tab = sessionStorage.getItem(STORAGE_KEY);
        } catch {
            return;
        }
        const radio = tab && this.radioTargets.find((r) => r.value === tab);
        if (radio) {
            radio.checked = true;
            this.show(tab);
        }
    }

    switch(event) {
        const tab = event.target.value;
        try {
            sessionStorage.setItem(STORAGE_KEY, tab);
        } catch {
            // Best-effort - worst case the tab just doesn't stick across a reload.
        }
        this.show(tab);
    }

    show(tab) {
        this.panelTargets.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.tab !== tab);
        });
    }
}
