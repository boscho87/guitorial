import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial:metronome-advanced';
const LOOKAHEAD_MS = 25;
const SCHEDULE_AHEAD_S = 0.1;

/**
 * Standalone metronome (own page, /metronom) with bar-level mute cycling
 * ("Takt-Pausen"/"silent bars" - a classic practice technique: play N bars
 * out loud, then go silent for M, forcing the tempo to be held internally
 * instead of leaned on) - deliberately a separate controller/engine from
 * practice_tools_controller.js rather than bolted onto it: that one is
 * already juggling the drone, exercise-binding persistence and the
 * embedded-vs-global widget split, none of which apply here.
 */
export default class extends Controller {
    static targets = ['bpm', 'bpmOutput', 'beats', 'activeBars', 'restBars', 'startButton', 'beatDot', 'statusLabel', 'volume'];
    static values = {
        playLabel: String,
        pauseLabel: String,
    };

    connect() {
        this.ctx = null;
        this.bus = null;
        this.timer = null;
        this.beatIndex = 0;
        this.barIndex = 0;
        this.nextBeatTime = 0;
        this.taps = [];
        this.state = { bpm: 100, beats: 4, activeBars: 4, restBars: 0, volume: 0.6 };

        try {
            Object.assign(this.state, JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'));
        } catch {
            // storage unavailable - defaults are fine
        }

        this.bpmTarget.value = this.state.bpm;
        this.beatsTarget.value = this.state.beats;
        this.activeBarsTarget.value = this.state.activeBars;
        this.restBarsTarget.value = this.state.restBars;
        this.volumeTarget.value = this.state.volume;
        this.renderBpm();
        this.renderBeatDots();

        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        this.stop();
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing || event.repeat) {
            return;
        }
        const target = event.target;
        if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
            return;
        }

        const key = event.key.toLowerCase();
        if (key === 'm') {
            this.toggle();
        } else if (key === 't') {
            this.tap();
        } else if (key === '+' || key === '=' || key === '-') {
            this.state.bpm = this.clampBpm(this.state.bpm + (key === '-' ? -5 : 5));
            this.bpmTarget.value = this.state.bpm;
            this.renderBpm();
            this.persist();
        } else {
            return;
        }
        event.preventDefault();
    }

    audio() {
        if (!this.ctx) {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            this.bus = this.ctx.createGain();
            this.bus.gain.value = this.state.volume;
            this.bus.connect(this.ctx.destination);
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }

        return this.ctx;
    }

    setVolume() {
        this.state.volume = Number(this.volumeTarget.value);
        if (this.bus) {
            this.bus.gain.setTargetAtTime(this.state.volume, this.ctx.currentTime, 0.02);
        }
        this.persist();
    }

    setBpm() {
        this.state.bpm = this.clampBpm(Number(this.bpmTarget.value));
        this.renderBpm();
        this.persist();
    }

    nudgeBpm(event) {
        this.state.bpm = this.clampBpm(this.state.bpm + Number(event.params.delta));
        this.bpmTarget.value = this.state.bpm;
        this.renderBpm();
        this.persist();
    }

    tap() {
        const now = performance.now();
        if (this.taps.length && now - this.taps[this.taps.length - 1] > 2000) {
            this.taps = [];
        }
        this.taps.push(now);
        this.taps = this.taps.slice(-5);
        if (this.taps.length >= 2) {
            const interval = (this.taps[this.taps.length - 1] - this.taps[0]) / (this.taps.length - 1);
            this.state.bpm = this.clampBpm(Math.round(60000 / interval));
            this.bpmTarget.value = this.state.bpm;
            this.renderBpm();
            this.persist();
        }
    }

    setBeats() {
        this.state.beats = Number(this.beatsTarget.value);
        this.beatIndex = 0;
        this.renderBeatDots();
        this.persist();
    }

    setActiveBars() {
        this.state.activeBars = Math.max(1, Math.min(32, Number(this.activeBarsTarget.value) || 1));
        this.activeBarsTarget.value = this.state.activeBars;
        this.persist();
    }

    setRestBars() {
        this.state.restBars = Math.max(0, Math.min(32, Number(this.restBarsTarget.value) || 0));
        this.restBarsTarget.value = this.state.restBars;
        this.persist();
    }

    toggle() {
        if (this.timer) {
            this.stop();
        } else {
            this.start();
        }
    }

    start() {
        const ctx = this.audio();
        this.beatIndex = 0;
        this.barIndex = 0;
        this.nextBeatTime = ctx.currentTime + 0.05;
        this.timer = setInterval(() => this.schedule(), LOOKAHEAD_MS);
        this.schedule();
        this.startButtonTarget.dataset.active = 'true';
        this.startButtonTarget.classList.add('btn-primary');
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        if (this.hasStartButtonTarget) {
            delete this.startButtonTarget.dataset.active;
            this.startButtonTarget.classList.remove('btn-primary');
        }
        this.beatDotTargets.forEach((dot) => dot.classList.remove('bg-primary'));
        if (this.hasStatusLabelTarget) {
            this.statusLabelTarget.textContent = '';
        }
    }

    // A rest bar is silent AND unflashed on purpose (see the class
    // docblock) - the whole point is holding tempo without any cue at all,
    // not just muting the speaker while still visually ticking along.
    schedule() {
        while (this.nextBeatTime < this.ctx.currentTime + SCHEDULE_AHEAD_S) {
            const cycleLength = this.state.activeBars + this.state.restBars;
            const isRestBar = cycleLength > this.state.activeBars && this.barIndex % cycleLength >= this.state.activeBars;

            if (!isRestBar) {
                this.click(this.nextBeatTime, this.beatIndex === 0);
            }
            this.flash(this.beatIndex, this.nextBeatTime, isRestBar);

            if (this.beatIndex === 0) {
                this.updateStatusLabel(this.nextBeatTime, isRestBar);
            }

            this.beatIndex++;
            if (this.beatIndex >= this.state.beats) {
                this.beatIndex = 0;
                this.barIndex++;
            }
            this.nextBeatTime += 60 / this.state.bpm;
        }
    }

    click(time, accent) {
        const osc = this.ctx.createOscillator();
        const gain = this.ctx.createGain();
        osc.frequency.value = accent ? 1500 : 1000;
        gain.gain.setValueAtTime(0.0001, time);
        gain.gain.exponentialRampToValueAtTime(accent ? 1 : 0.6, time + 0.002);
        gain.gain.exponentialRampToValueAtTime(0.0001, time + 0.05);
        osc.connect(gain).connect(this.bus);
        osc.start(time);
        osc.stop(time + 0.06);
    }

    flash(index, time, isRestBar) {
        const delay = Math.max(0, (time - this.ctx.currentTime) * 1000);
        setTimeout(() => {
            this.beatDotTargets.forEach((dot, i) => {
                dot.classList.toggle('bg-primary', this.timer !== null && !isRestBar && i === index);
            });
        }, delay);
    }

    updateStatusLabel(time, isRestBar) {
        if (!this.hasStatusLabelTarget) {
            return;
        }
        const cycleLength = this.state.activeBars + this.state.restBars;
        if (cycleLength <= this.state.activeBars) {
            this.statusLabelTarget.textContent = '';
            return;
        }
        const position = (this.barIndex % cycleLength) + 1;
        const delay = Math.max(0, (time - this.ctx.currentTime) * 1000);
        setTimeout(() => {
            if (this.timer === null) {
                return;
            }
            const template = isRestBar ? this.pauseLabelValue : this.playLabelValue;
            this.statusLabelTarget.textContent = template.replace('{bar}', position).replace('{total}', cycleLength);
        }, delay);
    }

    clampBpm(value) {
        return Math.min(240, Math.max(30, value || 100));
    }

    renderBpm() {
        this.bpmOutputTarget.textContent = this.state.bpm;
    }

    renderBeatDots() {
        this.beatDotTargets.forEach((dot, index) => {
            dot.classList.toggle('hidden', index >= this.state.beats);
        });
    }

    persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.state));
        } catch {
            // ignore
        }
    }
}
