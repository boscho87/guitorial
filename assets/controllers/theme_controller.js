import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial_theme';

const THEME_COLORS = {
    'guitorial-light': '#f5f3ed',
    'guitorial-dark': '#1c211e',
    'guitorial-slate': '#d3dbe2',
    'guitorial-contrast': '#000000',
};

/**
 * Applying/persisting the choice happens here; avoiding a flash of the
 * wrong theme on first paint is handled by a separate blocking inline
 * <script> in base.html.twig's <head> (this controller only connects after
 * Stimulus bootstraps, too late to prevent that flash).
 */
export default class extends Controller {
    static targets = ['option'];

    connect() {
        this.highlight(localStorage.getItem(STORAGE_KEY));
    }

    select(event) {
        const theme = event.currentTarget.dataset.themeName;
        document.documentElement.dataset.theme = theme;
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch {
            // Privater Modus o.ä. - Wahl gilt dann nur für die aktuelle Seite.
        }
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLORS[theme]);
        this.highlight(theme);
        this.element.open = false;
    }

    highlight(theme) {
        this.optionTargets.forEach((el) => {
            el.classList.toggle('menu-active', el.dataset.themeName === theme);
        });
    }
}
