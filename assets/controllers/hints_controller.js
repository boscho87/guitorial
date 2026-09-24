import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial_hints';

const ICON_ARROW = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
const ICON_CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>';

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
 * Guided hints: elements marked data-hint="<text>" + data-hint-id="<id>"
 * are explained one at a time (spotlight ring + bubble, Weiter/Zurück/
 * Überspringen), switchable in the profile. "Seen" is tracked per hint id,
 * not per page - a hint whose element only appears later (e.g. the exercise
 * list once the first exercise exists) still gets its turn, and the
 * automatic run only shows what hasn't been seen yet. The header "?"
 * replays every hint on the page. Lives on <body>; Turbo swaps the body on
 * navigation, so this reconnects per page. State is in localStorage like
 * the theme - nothing goes to the server. Hidden targets (e.g. desktop-only
 * elements on mobile) are skipped.
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
        const all = this.collectSteps();
        this.helpButtonTargets.forEach((button) => button.classList.toggle('hidden', !(state.enabled && all.length > 0)));

        if (state.enabled && all.some((step) => !state.seen[step.id])) {
            this.startTimer = setTimeout(() => this.begin(false), 900);
        }
    }

    disconnect() {
        clearTimeout(this.startTimer);
        document.removeEventListener('turbo:before-cache', this.teardownBound);
        this.teardown();
    }

    // Header "?" - replays everything on this page.
    start() {
        this.begin(true);
    }

    collectSteps() {
        return [...document.querySelectorAll('[data-hint]')]
            .filter((el) => el.getClientRects().length > 0)
            .map((el, position) => {
                const explicit = Number(el.dataset.hintOrder ?? Number.NaN);

                return {
                    el,
                    id: el.dataset.hintId || `${this.pageValue}:${position}`,
                    order: Number.isNaN(explicit) ? 100 + position : explicit,
                };
            })
            .sort((a, b) => a.order - b.order);
    }

    begin(replayAll) {
        clearTimeout(this.startTimer);
        this.teardown();

        const seen = readState().seen;
        this.steps = this.collectSteps().filter((step) => replayAll || !seen[step.id]);
        if (this.steps.length === 0) {
            return;
        }
        this.active = true;

        this.ring = document.createElement('div');
        this.ring.className = 'hint-ring';
        this.ring.setAttribute('aria-hidden', 'true');
        this.arrow = document.createElement('div');
        this.arrow.className = 'hint-arrow';
        this.arrow.setAttribute('aria-hidden', 'true');
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
        const step = this.steps[index];
        const labels = this.labelsValue;
        const isLast = index === this.steps.length - 1;

        const state = readState();
        state.seen[step.id] = true;
        writeState(state);

        const text = document.createElement('p');
        text.className = 'hint-text';
        text.textContent = step.el.dataset.hint;

        const dots = document.createElement('div');
        dots.className = 'hint-dots';
        dots.setAttribute('role', 'img');
        dots.setAttribute('aria-label', `${index + 1} ${labels.of ?? '/'} ${this.steps.length}`);
        this.steps.forEach((_, position) => {
            const dot = document.createElement('span');
            dot.className = position === index ? 'hint-dot hint-dot-active' : 'hint-dot';
            dots.append(dot);
        });

        const secondary = document.createElement('div');
        secondary.className = 'hint-secondary';
        if (index > 0) {
            secondary.append(this.button(labels.back, 'btn btn-ghost btn-sm', () => this.show(index - 1)));
        }
        if (!isLast) {
            secondary.append(this.button(labels.skip, 'btn btn-ghost btn-sm', () => this.finish()));
        }

        const next = this.button(isLast ? labels.done : labels.next, 'btn btn-primary hint-next', () => (isLast ? this.finish() : this.show(index + 1)));
        next.insertAdjacentHTML('beforeend', isLast ? ICON_CHECK : ICON_ARROW);
        if (!isLast) {
            const key = document.createElement('kbd');
            key.className = 'hint-key';
            key.textContent = '→';
            next.append(key);
        }

        const footer = document.createElement('div');
        footer.className = 'hint-footer';
        footer.append(dots, next);

        const off = this.button(labels.disable, 'hint-off', () => this.disable());
        const bottom = document.createElement('div');
        bottom.className = 'hint-bottom';
        bottom.append(secondary, off);

        this.bubble.replaceChildren(this.arrow, text, footer, bottom);
        // Restart the entry animation for every step.
        this.bubble.classList.remove('hint-bubble-in');
        void this.bubble.offsetWidth; // eslint-disable-line no-unused-expressions -- forces reflow so the animation restarts
        this.bubble.classList.add('hint-bubble-in');

        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        step.el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
        this.reposition();
        clearTimeout(this.settleTimer);
        this.settleTimer = setTimeout(this.reposition, 450);
        next.focus({ preventScroll: true });
    }

    button(label, className, onClick) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = className;
        const span = document.createElement('span');
        span.textContent = label;
        el.append(span);
        el.addEventListener('click', onClick);

        return el;
    }

    reposition() {
        if (!this.active) {
            return;
        }
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => {
            const target = this.steps[this.index]?.el;
            if (!target || !target.isConnected) {
                this.finish();

                return;
            }
            const rect = target.getBoundingClientRect();
            const pad = 8;
            Object.assign(this.ring.style, {
                left: `${rect.left - pad}px`,
                top: `${rect.top - pad}px`,
                width: `${rect.width + pad * 2}px`,
                height: `${rect.height + pad * 2}px`,
            });

            const vw = window.innerWidth;
            const vh = window.innerHeight;
            if (vw < 640) {
                this.bubble.dataset.placement = 'sheet';
                Object.assign(this.bubble.style, { left: '12px', right: '12px', bottom: '12px', top: 'auto', width: 'auto' });

                return;
            }
            const width = Math.min(380, vw - 24);
            const height = this.bubble.offsetHeight;
            const gap = 16;
            const below = rect.bottom + pad + gap + height <= vh;
            const top = below ? rect.bottom + pad + gap : Math.max(12, rect.top - pad - gap - height);
            const left = Math.min(Math.max(12, rect.left + rect.width / 2 - width / 2), vw - width - 12);
            this.bubble.dataset.placement = below ? 'below' : 'above';
            this.bubble.style.setProperty('--hint-arrow-x', `${Math.min(Math.max(24, rect.left + rect.width / 2 - left), width - 24)}px`);
            Object.assign(this.bubble.style, { left: `${left}px`, top: `${top}px`, right: 'auto', bottom: 'auto', width: `${width}px` });
        });
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing) {
            return;
        }
        if (event.key === 'Escape') {
            this.finish();
        } else if (event.key === 'ArrowRight') {
            if (this.index < this.steps.length - 1) {
                this.show(this.index + 1);
            } else {
                this.finish();
            }
        } else if (event.key === 'ArrowLeft' && this.index > 0) {
            this.show(this.index - 1);
        }
    }

    finish() {
        this.teardown();
    }

    disable() {
        const state = readState();
        state.enabled = false;
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
