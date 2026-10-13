import { Controller } from '@hotwired/stimulus';

/**
 * Bridges one diary Exercise's saved metronome/drone setup (Exercise::
 * practiceToolsSnapshot) to the global practice-tools widget. That widget
 * (base.html.twig) is data-turbo-permanent - it never reconnects on
 * navigation, so it can't read a fresh page's own server-rendered data by
 * itself. This element is an ordinary (non-permanent) part of the running
 * page instead: connecting fires once per visit and tells the widget to
 * load this exercise's config (never to play anything); disconnecting
 * (navigating away) tells it to stop saving further changes against this
 * exercise.
 */
export default class extends Controller {
    static values = { exerciseId: Number, config: Object, saveUrl: String, token: String };

    connect() {
        document.dispatchEvent(new CustomEvent('practice-tools:bind-exercise', {
            detail: {
                exerciseId: this.exerciseIdValue,
                config: this.hasConfigValue ? this.configValue : null,
                saveUrl: this.saveUrlValue,
                token: this.tokenValue,
            },
        }));
    }

    disconnect() {
        document.dispatchEvent(new CustomEvent('practice-tools:unbind-exercise'));
    }
}
