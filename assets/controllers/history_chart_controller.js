import { Controller } from '@hotwired/stimulus';

/**
 * Hover crosshair + tooltip for one accuracy-over-time chart. Reads geometry
 * Twig already baked into the SVG (cx/cy/data-accuracy/data-tooltip) - no
 * JSON re-parsing, no chart library.
 */
export default class extends Controller {
    static targets = ['svg', 'point', 'crosshair', 'tooltip'];

    hover(event) {
        const rect = this.svgTarget.getBoundingClientRect();
        const viewBox = this.svgTarget.viewBox.baseVal;
        const clientX = event.touches ? event.touches[0].clientX : event.clientX;
        const x = ((clientX - rect.left) / rect.width) * viewBox.width;

        let nearest = null;
        let nearestDist = Infinity;
        this.pointTargets.forEach((point) => {
            const dist = Math.abs(Number(point.getAttribute('cx')) - x);
            if (dist < nearestDist) {
                nearestDist = dist;
                nearest = point;
            }
        });
        if (!nearest) {
            return;
        }

        this.pointTargets.forEach((point) => point.setAttribute('r', point === nearest ? '6' : '4'));

        const cx = nearest.getAttribute('cx');
        this.crosshairTarget.setAttribute('x1', cx);
        this.crosshairTarget.setAttribute('x2', cx);
        this.crosshairTarget.style.display = '';

        const scaleX = rect.width / viewBox.width;
        const scaleY = rect.height / viewBox.height;
        this.tooltipTarget.textContent = nearest.dataset.tooltip;
        this.tooltipTarget.style.left = `${Number(cx) * scaleX}px`;
        this.tooltipTarget.style.top = `${Number(nearest.getAttribute('cy')) * scaleY}px`;
        this.tooltipTarget.style.display = '';
    }

    clear() {
        this.pointTargets.forEach((point) => point.setAttribute('r', '4'));
        this.crosshairTarget.style.display = 'none';
        this.tooltipTarget.style.display = 'none';
    }
}
