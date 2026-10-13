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
 *
 * On a cold page load both this element and the widget connect in the same
 * batch, in document order - this element sits near the top of <body>, the
 * widget is appended at the very end of base.html.twig, so a plain
 * connect()-time dispatch can fire before the widget's listener exists and
 * get lost. Re-announcing on request (and the widget asking on its own
 * connect()) makes it work regardless of which one connects first.
 */
export default class extends Controller {
    static values = { exerciseId: Number, config: Object, saveUrl: String, token: String };

    connect() {
        this.onRequestBinding = () => this.announce();
        document.addEventListener('practice-tools:request-exercise-binding', this.onRequestBinding);
        this.announce();
    }

    disconnect() {
        document.removeEventListener('practice-tools:request-exercise-binding', this.onRequestBinding);
        document.dispatchEvent(new CustomEvent('practice-tools:unbind-exercise'));
    }

    announce() {
        document.dispatchEvent(new CustomEvent('practice-tools:bind-exercise', {
            detail: {
                exerciseId: this.exerciseIdValue,
                config: this.hasConfigValue ? this.configValue : null,
                saveUrl: this.saveUrlValue,
                token: this.tokenValue,
            },
        }));
    }
}
