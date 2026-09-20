import { Controller } from '@hotwired/stimulus';

/**
 * Click-to-load YouTube embed (youtube-nocookie.com) - nothing from Google
 * loads until the visitor actually asks for it, matching the site's
 * no-tracking-by-default stance. Playing in-page instead of opening a new
 * tab also means the exercise-session tab never becomes document.hidden,
 * so its timer keeps counting instead of pausing.
 */
export default class extends Controller {
    static targets = ['container', 'loadButton'];
    static values = { videoId: String };

    load() {
        const iframe = document.createElement('iframe');
        iframe.src = `https://www.youtube-nocookie.com/embed/${this.videoIdValue}?autoplay=1`;
        iframe.title = 'YouTube video player';
        iframe.className = 'h-full w-full';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';

        this.containerTarget.replaceChildren(iframe);
        this.containerTarget.classList.remove('hidden');
        this.loadButtonTarget.classList.add('hidden');
    }
}
