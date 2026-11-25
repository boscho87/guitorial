import { Controller } from '@hotwired/stimulus';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';
const SEEK_SECONDS = 5;
const WIDE_STORAGE_KEY = 'guitorial.youtubePlayerWide';
const PLAYING = 1;
const BUFFERING = 3;

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
 *
 * The control bar under the player exists because embeds often show only a
 * reduced YouTube UI. It talks to the iframe via the raw postMessage
 * protocol (enablejsapi=1) instead of loading YouTube's iframe_api script,
 * which would come from www.youtube.com rather than the nocookie domain.
 */
export default class extends Controller {
    static targets = ['container', 'playerWrapper', 'playButton', 'playIcon', 'pauseIcon', 'wideButton', 'expandIcon', 'shrinkIcon', 'wideLabel'];
    static values = { labels: Object };

    connect() {
        this.onMessage = this.onMessage.bind(this);
        window.addEventListener('message', this.onMessage);
        this.applyWide(this.readWidePreference());
    }

    disconnect() {
        window.removeEventListener('message', this.onMessage);
        this.stopHandshake();
    }

    load(event) {
        const videoId = event.params.videoId;
        if (this.currentVideoId === videoId) {
            return; // already playing this one
        }
        this.currentVideoId = videoId;
        this.currentTime = 0;
        this.playerState = PLAYING;

        const params = new URLSearchParams({ autoplay: '1', enablejsapi: '1', rel: '0', playsinline: '1', origin: window.location.origin });
        const iframe = document.createElement('iframe');
        iframe.src = `${PLAYER_ORIGIN}/embed/${videoId}?${params}`;
        iframe.title = 'YouTube video player';
        iframe.className = 'h-full w-full';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        iframe.addEventListener('load', () => this.startHandshake());
        this.iframe = iframe;

        this.containerTarget.replaceChildren(iframe);
        this.playerWrapperTarget.classList.remove('hidden');
        this.renderPlayState();
        this.playerWrapperTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    close() {
        // Actually remove the iframe, not just hide it - a hidden-but-still-
        // present embed keeps playing (and stays document-audible) in the
        // background.
        this.stopHandshake();
        this.currentVideoId = null;
        this.iframe = null;
        this.containerTarget.replaceChildren();
        this.playerWrapperTarget.classList.add('hidden');
    }

    togglePlay() {
        const playing = this.playerState === PLAYING || this.playerState === BUFFERING;
        this.command(playing ? 'pauseVideo' : 'playVideo');
        this.playerState = playing ? 2 : PLAYING;
        this.renderPlayState();
    }

    seekBack() {
        this.seekBy(-SEEK_SECONDS);
    }

    seekForward() {
        this.seekBy(SEEK_SECONDS);
    }

    toggleWide() {
        const wide = !this.wide;
        this.applyWide(wide);
        try {
            localStorage.setItem(WIDE_STORAGE_KEY, wide ? '1' : '0');
        } catch {
            // Preference just isn't remembered.
        }
        this.playerWrapperTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    seekBy(seconds) {
        // currentTime only arrives with the player's periodic infoDelivery
        // messages, so track the jump locally too - otherwise quick repeated
        // clicks would all seek relative to the same stale position.
        this.currentTime = Math.max(0, (this.currentTime ?? 0) + seconds);
        this.command('seekTo', [this.currentTime, true]);
    }

    command(func, args = []) {
        this.iframe?.contentWindow?.postMessage(JSON.stringify({ event: 'command', func, args }), PLAYER_ORIGIN);
    }

    // The player only starts reporting state after it has received a
    // "listening" message - and may not be ready for it on the first try.
    startHandshake() {
        this.stopHandshake();
        let attempts = 0;
        const send = () => {
            this.iframe?.contentWindow?.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), PLAYER_ORIGIN);
            if (++attempts >= 20) {
                this.stopHandshake();
            }
        };
        send();
        this.handshakeTimer = setInterval(send, 250);
    }

    stopHandshake() {
        clearInterval(this.handshakeTimer);
        this.handshakeTimer = null;
    }

    onMessage(event) {
        if (event.origin !== PLAYER_ORIGIN || !this.iframe || event.source !== this.iframe.contentWindow) {
            return;
        }
        let data;
        try {
            data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
        } catch {
            return;
        }
        this.stopHandshake();

        const info = data?.event === 'onStateChange' ? { playerState: data.info } : data?.info;
        if (!info || typeof info !== 'object') {
            return;
        }
        if (typeof info.currentTime === 'number') {
            this.currentTime = info.currentTime;
        }
        if (typeof info.playerState === 'number' && info.playerState !== this.playerState) {
            this.playerState = info.playerState;
            this.renderPlayState();
        }
    }

    renderPlayState() {
        const playing = this.playerState === PLAYING || this.playerState === BUFFERING;
        this.pauseIconTarget.classList.toggle('hidden', !playing);
        this.playIconTarget.classList.toggle('hidden', playing);
        const label = playing ? this.labelsValue.pause : this.labelsValue.play;
        this.playButtonTarget.title = label;
        this.playButtonTarget.setAttribute('aria-label', label);
    }

    // Inline styles rather than utility classes: breaking out of the
    // max-w-3xl content column needs arbitrary values, and the committed
    // app.css only contains classes some template already uses. Width is
    // also capped by viewport height so the whole 16:9 player stays visible.
    applyWide(wide) {
        this.wide = wide;
        const style = this.playerWrapperTarget.style;
        if (wide) {
            style.position = 'relative';
            style.left = '50%';
            style.transform = 'translateX(-50%)';
            style.width = 'max(100%, min(calc(100vw - 2rem), calc((100dvh - 9rem) * 16 / 9), 80rem))';
        } else {
            style.position = style.left = style.transform = style.width = '';
        }
        this.expandIconTarget.classList.toggle('hidden', wide);
        this.shrinkIconTarget.classList.toggle('hidden', !wide);
        this.wideLabelTarget.textContent = wide ? this.labelsValue.shrink : this.labelsValue.enlarge;
        this.wideButtonTarget.setAttribute('aria-pressed', wide ? 'true' : 'false');
    }

    readWidePreference() {
        try {
            return localStorage.getItem(WIDE_STORAGE_KEY) === '1';
        } catch {
            return false;
        }
    }
}
