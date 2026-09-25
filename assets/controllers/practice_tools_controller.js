import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial:practice-tools';
const LOOKAHEAD_MS = 25;
const SCHEDULE_AHEAD_S = 0.1;
const DRONE_OCTAVE_C2 = 65.406;

export default class extends Controller {
    static targets = ['panel', 'bpm', 'bpmOutput', 'beats', 'metroButton', 'droneNote', 'fifth', 'volume', 'beatDot'];

    connect() {
        this.ctx = null;
        this.master = null;
        this.metroTimer = null;
        this.beatIndex = 0;
        this.nextBeatTime = 0;
        this.taps = [];
        this.drone = null;
        this.state = { bpm: 100, beats: 4, droneNote: null, fifth: false, volume: 0.6 };

        try {
            Object.assign(this.state, JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'));
        } catch {
            // storage unavailable - defaults are fine
        }

        this.bpmTarget.value = this.state.bpm;
        this.beatsTarget.value = this.state.beats;
        this.fifthTarget.checked = this.state.fifth;
        this.volumeTarget.value = this.state.volume;
        this.renderBpm();
        this.renderDroneButtons();
    }

    disconnect() {
        this.stopMetronome();
        this.stopDrone(true);
    }

    toggle() {
        this.panelTarget.classList.toggle('hidden');
    }

    persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.state));
        } catch {
            // ignore
        }
    }

    audio() {
        if (!this.ctx) {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            this.master = this.ctx.createGain();
            this.master.gain.value = this.state.volume;
            this.master.connect(this.ctx.destination);
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }

        return this.ctx;
    }

    setVolume() {
        this.state.volume = Number(this.volumeTarget.value);
        if (this.master) {
            this.master.gain.setTargetAtTime(this.state.volume, this.ctx.currentTime, 0.02);
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
        this.persist();
    }

    toggleMetronome() {
        if (this.metroTimer) {
            this.stopMetronome();
        } else {
            this.startMetronome();
        }
    }

    startMetronome() {
        const ctx = this.audio();
        this.beatIndex = 0;
        this.nextBeatTime = ctx.currentTime + 0.05;
        this.metroTimer = setInterval(() => this.schedule(), LOOKAHEAD_MS);
        this.schedule();
        this.metroButtonTarget.dataset.active = 'true';
        this.metroButtonTarget.classList.add('btn-primary');
    }

    stopMetronome() {
        if (this.metroTimer) {
            clearInterval(this.metroTimer);
            this.metroTimer = null;
        }
        if (this.hasMetroButtonTarget) {
            delete this.metroButtonTarget.dataset.active;
            this.metroButtonTarget.classList.remove('btn-primary');
        }
        this.beatDotTargets.forEach((dot) => dot.classList.remove('bg-primary'));
    }

    schedule() {
        while (this.nextBeatTime < this.ctx.currentTime + SCHEDULE_AHEAD_S) {
            this.click(this.nextBeatTime, this.beatIndex === 0);
            this.flash(this.beatIndex, this.nextBeatTime);
            this.beatIndex = (this.beatIndex + 1) % this.state.beats;
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
        osc.connect(gain).connect(this.master);
        osc.start(time);
        osc.stop(time + 0.06);
    }

    flash(index, time) {
        const delay = Math.max(0, (time - this.ctx.currentTime) * 1000);
        setTimeout(() => {
            this.beatDotTargets.forEach((dot, i) => {
                dot.classList.toggle('bg-primary', this.metroTimer !== null && i === index);
            });
        }, delay);
    }

    selectDrone(event) {
        const note = Number(event.params.note);
        if (this.drone && this.state.droneNote === note) {
            this.stopDrone();
            this.state.droneNote = null;
        } else {
            this.startDrone(note);
            this.state.droneNote = note;
        }
        this.renderDroneButtons();
        this.persist();
    }

    toggleFifth() {
        this.state.fifth = this.fifthTarget.checked;
        this.persist();
        if (this.drone && this.state.droneNote !== null) {
            this.startDrone(this.state.droneNote);
        }
    }

    startDrone(note) {
        const ctx = this.audio();
        this.stopDrone();

        const base = DRONE_OCTAVE_C2 * 2 ** (note / 12);
        const freqs = this.state.fifth ? [base, base * 1.5] : [base];
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + 0.15);
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 900;
        filter.connect(gain).connect(this.master);

        const oscs = freqs.flatMap((freq) => [['sawtooth', 0.4], ['sine', 1]].map(([type, level]) => {
            const osc = ctx.createOscillator();
            const g = ctx.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            g.gain.value = level / freqs.length;
            osc.connect(g).connect(filter);
            osc.start();

            return osc;
        }));

        this.drone = { oscs, gain };
    }

    stopDrone(immediate = false) {
        if (!this.drone || !this.ctx) {
            this.drone = null;

            return;
        }
        const { oscs, gain } = this.drone;
        const end = this.ctx.currentTime + (immediate ? 0.01 : 0.15);
        gain.gain.cancelScheduledValues(this.ctx.currentTime);
        gain.gain.setValueAtTime(Math.max(gain.gain.value, 0.0001), this.ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.0001, end);
        oscs.forEach((osc) => osc.stop(end + 0.01));
        this.drone = null;
    }

    clampBpm(value) {
        return Math.min(240, Math.max(30, value || 100));
    }

    renderBpm() {
        this.bpmOutputTarget.textContent = this.state.bpm;
    }

    renderDroneButtons() {
        this.droneNoteTargets.forEach((button) => {
            const active = Number(button.dataset.practiceToolsNoteParam) === this.state.droneNote && this.drone !== null;
            button.classList.toggle('btn-primary', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }
}
