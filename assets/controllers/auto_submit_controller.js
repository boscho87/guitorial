import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form it's attached to whenever a field inside it fires
 * change - e.g. the rhythm/group quick-edit dropdowns on the manage list,
 * so picking a new value applies immediately without a separate save click.
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
