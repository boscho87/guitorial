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
        // Private mode / quota - hints then just show again next time.
    }
}

/**
 * Guided hints: elements marked data-hint="<text>" are explained one at a
 * time (pulsing ring + bubble, Weiter/Zurück/Überspringen), once per page
 * per browser, switchable in the profile. Lives on <body>; Turbo swaps the
 * body on navigation, so this reconnects per page. State (enabled, seen
 * pages) is in localStorage like the theme - nothing goes to the server.
 * Hidden targets (e.g. desktop-only elements on mobile) are skipped.
 */
export default class extends Controller {
    static targets = ['helpButton'];
    static values = { page: String, labels: Object };

    connect() {
        this.steps = [];
        this.index = 0;
        this.active = false;

        this.onKeydown = this.onKeydown.bind(this);
        this.reposition = this.reposition.bind(this);
        this.teardownBound = () => this.teardown();
        document.addEventListener('turbo:before-cache', this.teardownBound);

        const state = readState();
        const hasSteps = this.collectSteps().length > 0;
        this.helpButtonTargets.forEach((button) => button.classList.toggle('hidden', !(state.enabled && hasSteps)));

        if (state.enabled && hasSteps && !state.seen[this.pageValue]) {
            this.startTimer = setTimeout(() => this.start(), 900);
        }
    }

    disconnect() {
        clearTimeout(this.startTimer);
        document.removeEventListener('turbo:before-cache', this.teardownBound);
        this.teardown();
    }

    collectSteps() {
        return [...document.querySelectorAll('[data-hint]')]
            .filter((el) => el.getClientRects().length > 0)
            .map((el, position) => ({ el, position, order: Number(el.dataset.hintOrder ?? Number.NaN) }))
            .sort((a, b) => (Number.isNaN(a.order) || Number.isNaN(b.order) ? a.position - b.position : a.order - b.order))
            .map((step) => step.el);
    }

    start() {
        clearTimeout(this.startTimer);
        this.teardown();
        this.steps = this.collectSteps();
        if (this.steps.length === 0) {
            return;
        }
        this.active = true;
        this.index = 0;

        this.ring = document.createElement('div');
        this.ring.className = 'hint-ring';
        this.ring.setAttribute('aria-hidden', 'true');
        this.bubble = document.createElement('div');
        this.bubble.className = 'hint-bubble';
        this.bubble.setAttribute('role', 'dialog');
        this.bubble.setAttribute('aria-live', 'polite');
        document.body.append(this.ring, this.bubble);

        document.addEventListener('keydown', this.onKeydown);
        window.addEventListener('resize', this.reposition);
        window.addEventListener('scroll', this.reposition, true);
        this.show(0);
    }

    show(index) {
        this.index = index;
        const target = this.steps[index];
        const labels = this.labelsValue;
        const isLast = index === this.steps.length - 1;

        const text = document.createElement('p');
        text.className = 'hint-text';
        text.textContent = target.dataset.hint;

        const footer = document.createElement('div');
        footer.className = 'hint-footer';
        const counter = document.createElement('span');
        counter.className = 'hint-counter';
        counter.textContent = `${index + 1} ${labels.of ?? '/'} ${this.steps.length}`;
        const actions = document.createElement('div');
        actions.className = 'hint-actions';
        if (index > 0) {
            actions.append(this.button(labels.back, 'btn btn-ghost btn-xs', () => this.show(index - 1)));
        }
        if (!isLast) {
            actions.append(this.button(labels.skip, 'btn btn-ghost btn-xs', () => this.finish()));
        }
        const next = this.button(isLast ? labels.done : labels.next, 'btn btn-primary btn-xs', () => (isLast ? this.finish() : this.show(index + 1)));
        actions.append(next);
        footer.append(counter, actions);

        const off = this.button(labels.disable, 'hint-off', () => this.disable());

        this.bubble.replaceChildren(text, footer, off);
        // Restart the entry animation for every step.
        this.bubble.classList.remove('hint-bubble-in');
        void this.bubble.offsetWidth; // eslint-disable-line no-unused-expressions -- forces reflow so the animation restarts
        this.bubble.classList.add('hint-bubble-in');

        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
        this.reposition();
        clearTimeout(this.settleTimer);
        this.settleTimer = setTimeout(this.reposition, 450);
        next.focus({ preventScroll: true });
    }

    button(label, className, onClick) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = className;
        el.textContent = label;
        el.addEventListener('click', onClick);

        return el;
    }

    reposition() {
        if (!this.active) {
            return;
        }
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => {
            const target = this.steps[this.index];
            if (!target || !target.isConnected) {
                this.finish();

                return;
            }
            const rect = target.getBoundingClientRect();
            const pad = 6;
            Object.assign(this.ring.style, {
                left: `${rect.left - pad}px`,
                top: `${rect.top - pad}px`,
                width: `${rect.width + pad * 2}px`,
                height: `${rect.height + pad * 2}px`,
            });

            const vw = window.innerWidth;
            const vh = window.innerHeight;
            if (vw < 640) {
                Object.assign(this.bubble.style, { left: '12px', right: '12px', bottom: '12px', top: 'auto', width: 'auto' });

                return;
            }
            const width = Math.min(340, vw - 24);
            const height = this.bubble.offsetHeight;
            const below = rect.bottom + pad + 12 + height <= vh;
            const top = below ? rect.bottom + pad + 12 : Math.max(12, rect.top - pad - 12 - height);
            const left = Math.min(Math.max(12, rect.left + rect.width / 2 - width / 2), vw - width - 12);
            Object.assign(this.bubble.style, { left: `${left}px`, top: `${top}px`, right: 'auto', bottom: 'auto', width: `${width}px` });
        });
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing) {
            return;
        }
        if (event.key === 'Escape') {
            this.finish();
        } else if (event.key === 'ArrowRight' && this.index < this.steps.length - 1) {
            this.show(this.index + 1);
        } else if (event.key === 'ArrowLeft' && this.index > 0) {
            this.show(this.index - 1);
        }
    }

    finish() {
        const state = readState();
        state.seen[this.pageValue] = true;
        writeState(state);
        this.teardown();
    }

    disable() {
        const state = readState();
        state.enabled = false;
        state.seen[this.pageValue] = true;
        writeState(state);
        this.helpButtonTargets.forEach((button) => button.classList.add('hidden'));
        this.teardown();
    }

    teardown() {
        this.active = false;
        clearTimeout(this.settleTimer);
        cancelAnimationFrame(this.frame);
        document.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('resize', this.reposition);
        window.removeEventListener('scroll', this.reposition, true);
        this.ring?.remove();
        this.bubble?.remove();
        this.ring = null;
        this.bubble = null;
    }
}
