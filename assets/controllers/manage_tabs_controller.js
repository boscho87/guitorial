import { Controller } from '@hotwired/stimulus';

/**
 * Switches between the three pre-rendered exercise lists (active/deactivated/
 * archived) without a page reload - same pattern as history_tabs_controller.js,
 * just over diary/manage.html.twig's sections instead of chart panels.
 */
export default class extends Controller {
    static targets = ['panel'];

    switch(event) {
        const tab = event.target.value;
        this.panelTargets.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.tab !== tab);
        });
    }
}
