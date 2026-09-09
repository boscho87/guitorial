import { Controller } from '@hotwired/stimulus';

/**
 * Switches between pre-rendered chart panels (one per tool + "Alle") without
 * a page reload - all series are already in the DOM, this only toggles
 * visibility, so no turbo-frame is needed here.
 */
export default class extends Controller {
    static targets = ['panel'];

    switch(event) {
        const tool = event.target.value;
        this.panelTargets.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.tool !== tool);
        });
    }
}
