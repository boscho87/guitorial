import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

const GRIP_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/></svg>';

/**
 * Two-column exercise picker for the routine editor. Add/remove is a plain
 * +/x button, not drag - a first version let you drag an exercise between
 * the two columns to add/remove it, which turned out to be exactly the
 * kind of fiddly, low-discoverability interaction "transfer list" widgets
 * are notorious for (Django admin's filter_horizontal is the classic
 * example). Every mainstream drag-and-drop list - Trello cards, Notion
 * blocks, Linear issues - instead keeps add/remove as an explicit click
 * and reserves drag for reordering only, triggered from a visible handle
 * rather than the whole row. This follows that split: dragging only
 * works from the grip icon, and only within "In dieser Routine".
 */
export default class extends Controller {
    static targets = ['available', 'selected', 'hiddenInputs'];
    static values = { exercises: Array, selected: Array, addLabel: String, removeLabel: String };

    connect() {
        this.titleById = new Map(this.exercisesValue.map((entry) => [String(entry.id), entry.title]));
        this.order = this.selectedValue.map(String);

        this.sortable = new Sortable(this.selectedTarget, {
            animation: 150,
            handle: '[data-drag-handle]',
            ghostClass: 'opacity-40',
            onEnd: (event) => {
                const [movedId] = this.order.splice(event.oldIndex, 1);
                this.order.splice(event.newIndex, 0, movedId);
                this.sync();
            },
        });

        this.render();
    }

    disconnect() {
        this.sortable?.destroy();
    }

    add(event) {
        const id = event.currentTarget.dataset.id;
        if (!this.order.includes(id)) {
            this.order.push(id);
        }
        this.render();
    }

    remove(event) {
        const id = event.currentTarget.dataset.id;
        this.order = this.order.filter((existingId) => existingId !== id);
        this.render();
    }

    render() {
        const availableIds = [...this.titleById.keys()]
            .filter((id) => !this.order.includes(id))
            .sort((a, b) => (this.titleById.get(a) ?? '').localeCompare(this.titleById.get(b) ?? ''));

        this.availableTarget.replaceChildren(...availableIds.map((id) => this.buildRow(id, false)));
        this.selectedTarget.replaceChildren(...this.order.map((id) => this.buildRow(id, true)));
        this.sync();
    }

    buildRow(id, isSelected) {
        const li = document.createElement('li');
        li.className = 'card-tile flex items-center gap-2 !p-2 text-sm';
        li.dataset.id = id;

        if (isSelected) {
            const handle = document.createElement('span');
            handle.dataset.dragHandle = '';
            handle.className = 'shrink-0 cursor-grab text-base-content/40 active:cursor-grabbing';
            handle.innerHTML = GRIP_SVG;
            li.append(handle);
        }

        const label = document.createElement('span');
        label.className = 'min-w-0 flex-1 truncate';
        label.textContent = this.titleById.get(id) ?? '';
        li.append(label);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-ghost btn-xs btn-square shrink-0';
        button.dataset.id = id;
        button.dataset.action = isSelected ? 'routine-picker#remove' : 'routine-picker#add';
        button.setAttribute('aria-label', isSelected ? this.removeLabelValue : this.addLabelValue);
        button.textContent = isSelected ? '×' : '+';
        li.append(button);

        return li;
    }

    sync() {
        this.hiddenInputsTarget.replaceChildren(
            ...this.order.map((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'exercise_ids[]';
                input.value = id;

                return input;
            }),
        );
    }
}
