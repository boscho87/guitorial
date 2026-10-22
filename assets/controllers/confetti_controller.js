import { Controller } from '@hotwired/stimulus';

const COLORS = ['var(--color-primary)', 'var(--color-secondary)', 'var(--color-accent)', 'var(--color-info)', 'var(--color-success)'];
const PIECE_COUNT = 28;
const FALL_DURATION_MS = 2200;

/**
 * One-shot celebration burst for a "sicher" session finish - see
 * ExerciseSessionController::finish()'s celebrate flash and
 * diary/index.html.twig / routine/show.html.twig's data-confetti-active-value,
 * which is only ever true right after that specific redirect lands (the
 * flash bag clears itself on read, so a reload/back never replays it).
 * Hand-rolled DOM/CSS instead of a library, matching the "no Node needed at
 * runtime" house style - see assets/styles/tailwind.css's
 * .confetti-piece/@keyframes confetti-fall.
 */
export default class extends Controller {
    static values = { active: Boolean };

    connect() {
        if (!this.activeValue) {
            return;
        }
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        this.burst();
    }

    burst() {
        const container = document.createElement('div');
        container.className = 'pointer-events-none fixed inset-0 z-50 overflow-hidden';
        container.setAttribute('aria-hidden', 'true');

        for (let i = 0; i < PIECE_COUNT; i++) {
            const piece = document.createElement('span');
            piece.className = 'confetti-piece';
            piece.style.left = `${Math.random() * 100}%`;
            piece.style.backgroundColor = COLORS[i % COLORS.length];
            piece.style.animationDelay = `${Math.random() * 0.4}s`;
            piece.style.setProperty('--confetti-spin', `${360 + Math.random() * 360}deg`);
            container.appendChild(piece);
        }

        document.body.appendChild(container);
        setTimeout(() => container.remove(), FALL_DURATION_MS + 400);
    }
}
