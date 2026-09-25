import { Controller } from '@hotwired/stimulus';

/**
 * Click-to-load YouTube embed (youtube-nocookie.com) - nothing from Google
 * loads until the visitor actually asks for it, matching the site's
 * no-tracking-by-default stance. Playing in-page instead of opening a new
 * tab also means the exercise-session tab never becomes document.hidden,
 * so its timer keeps counting instead of pausing.
 *
 * One controller instance covers the whole resource list (a fixed player
 * slot, shared by every "Abspielen" trigger below it - see running.html.twig)
 * rather than one instance per resource, so the video always plays in the
 * same place instead of appearing wherever was clicked and pushing
 * whatever follows further down each time a different resource is chosen.
 */
export default class extends Controller {
    static targets = ['container', 'playerWrapper'];

    load(event) {
        const videoId = event.params.videoId;
        if (this.currentVideoId === videoId) {
            return; // already playing this one
        }
        this.currentVideoId = videoId;

        const iframe = document.createElement('iframe');
        iframe.src = `https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1`;
        iframe.title = 'YouTube video player';
        iframe.className = 'h-full w-full';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';

        this.containerTarget.replaceChildren(iframe);
        this.playerWrapperTarget.classList.remove('hidden');
        this.playerWrapperTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    close() {
        // Actually remove the iframe, not just hide it - a hidden-but-still-
        // present embed keeps playing (and stays document-audible) in the
        // background.
        this.currentVideoId = null;
        this.containerTarget.replaceChildren();
        this.playerWrapperTarget.classList.add('hidden');
    }
}
