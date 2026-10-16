import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'guitorial:practice-tools';
const LOOKAHEAD_MS = 25;
const SCHEDULE_AHEAD_S = 0.1;
const DRONE_OCTAVE_C2 = 65.406;

export default class extends Controller {
    static targets = ['panel', 'bpm', 'bpmOutput', 'beats', 'metroButton', 'droneNote', 'fifth', 'octave', 'metroVolume', 'droneVolume', 'beatDot', 'toggleButton'];

    connect() {
        this.ctx = null;
        this.metroBus = null;
        this.droneBus = null;
        this.metroTimer = null;
        this.beatIndex = 0;
        this.nextBeatTime = 0;
        this.taps = [];
        this.drone = null;
        this.state = { bpm: 100, beats: 4, droneNote: null, fifth: false, octave: 3, metroVolume: 0.6, droneVolume: 0.6 };

        try {
            Object.assign(this.state, JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'));
        } catch {
            // storage unavailable - defaults are fine
        }

        this.bpmTarget.value = this.state.bpm;
        this.beatsTarget.value = this.state.beats;
        this.fifthTarget.checked = this.state.fifth;
        this.state.octave = Math.min(4, Math.max(2, Number(this.state.octave) || 3));
        this.octaveTarget.value = this.state.octave;
        this.metroVolumeTarget.value = this.state.metroVolume;
        this.droneVolumeTarget.value = this.state.droneVolume;
        this.renderBpm();
        this.renderDroneButtons();
        this.renderBeatDots();

        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);

        // Set by exercise_practice_tools_controller.js while a specific
        // diary exercise's running page is open - see bindExercise()/
        // unbindExercise() below.
        this.exerciseBinding = null;
        this.onBindExercise = (event) => this.bindExercise(event);
        this.onUnbindExercise = () => this.unbindExercise();
        document.addEventListener('practice-tools:bind-exercise', this.onBindExercise);
        document.addEventListener('practice-tools:unbind-exercise', this.onUnbindExercise);
        // On a cold page load this widget (appended at the very end of
        // base.html.twig) can connect after the exercise bridge controller
        // (near the top of <body>) already fired its own bind-exercise -
        // which is then lost since nothing was listening yet. Asking here
        // makes the bridge controller (still listening after its own
        // connect()) re-announce, regardless of connect order.
        document.dispatchEvent(new CustomEvent('practice-tools:request-exercise-binding'));
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('practice-tools:bind-exercise', this.onBindExercise);
        document.removeEventListener('practice-tools:unbind-exercise', this.onUnbindExercise);
        clearTimeout(this.exerciseSaveTimer);
        this.stopMetronome();
        this.stopDrone(true);
    }

    /**
     * Loads one exercise's own remembered setup into the panel - never
     * starts anything (the whole point: opening an exercise must not make
     * sound by itself). Further changes while bound also save back to this
     * exercise (see persist()), on top of the usual global-default save.
     */
    bindExercise(event) {
        const { exerciseId, config, saveUrl, token } = event.detail;
        this.exerciseBinding = { exerciseId, saveUrl, token };
        if (!config) {
            return;
        }

        if (config.bpm) {
            this.state.bpm = this.clampBpm(config.bpm);
            this.bpmTarget.value = this.state.bpm;
        }
        if (config.beats) {
            this.state.beats = config.beats;
            this.beatsTarget.value = this.state.beats;
        }
        this.state.droneNote = config.droneNote ?? null;
        this.state.fifth = Boolean(config.fifth);
        this.fifthTarget.checked = this.state.fifth;
        if (config.octave) {
            this.state.octave = Math.min(4, Math.max(2, config.octave));
            this.octaveTarget.value = this.state.octave;
        }
        this.renderBpm();
        this.renderDroneButtons();
        this.renderBeatDots();
    }

    unbindExercise() {
        this.exerciseBinding = null;
    }

    onKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing || event.repeat) {
            return;
        }
        const target = event.target;
        if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
            return;
        }
        if (document.querySelector('dialog[open], .hint-bubble')) {
            return;
        }

        const key = event.key.toLowerCase();
        if (key === 'm') {
            this.toggleMetronome();
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

    // Closed + running means the panel can't show the stop button - so the
    // first click on the toggle itself has to stop everything (metronome
    // and, if one's going, the drone) instead of opening the panel; any
    // other combination keeps the plain open/close behaviour.
    toggle() {
        if (this.panelTarget.classList.contains('hidden') && this.metroTimer) {
            this.stopMetronome();
            this.stopDrone();

            return;
        }
        this.panelTarget.classList.toggle('hidden');
        this.updateToggleButtonBlink();
    }

    // Only visible while the panel is closed - once it's open the running
    // state is obvious from the panel itself.
    updateToggleButtonBlink() {
        if (!this.hasToggleButtonTarget) {
            return;
        }
        const blinking = Boolean(this.metroTimer) && this.panelTarget.classList.contains('hidden');
        this.toggleButtonTarget.classList.toggle('practice-tools-blink', blinking);
    }

    persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.state));
        } catch {
            // ignore
        }
        this.persistToBoundExerciseIfAny();
    }

    persistToBoundExerciseIfAny() {
        if (!this.exerciseBinding) {
            return;
        }
        const { saveUrl, token } = this.exerciseBinding;
        clearTimeout(this.exerciseSaveTimer);
        this.exerciseSaveTimer = setTimeout(() => {
            const body = new URLSearchParams({
                _token: token,
                bpm: this.state.bpm,
                beats: this.state.beats,
                droneNote: this.state.droneNote ?? '',
                fifth: this.state.fifth ? '1' : '0',
                octave: this.state.octave,
            });
            fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                keepalive: true,
                body,
            }).catch(() => {
                // Best-effort - worst case this exercise just keeps its previous saved setup.
            });
        }, 600);
    }

    audio() {
        if (!this.ctx) {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            this.metroBus = this.ctx.createGain();
            this.metroBus.gain.value = this.state.metroVolume;
            this.metroBus.connect(this.ctx.destination);
            this.droneBus = this.ctx.createGain();
            this.droneBus.gain.value = this.state.droneVolume;
            this.droneBus.connect(this.ctx.destination);
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume();
        }

        return this.ctx;
    }

    setMetroVolume() {
        this.state.metroVolume = Number(this.metroVolumeTarget.value);
        if (this.metroBus) {
            this.metroBus.gain.setTargetAtTime(this.state.metroVolume, this.ctx.currentTime, 0.02);
        }
        this.persist();
    }

    setDroneVolume() {
        this.state.droneVolume = Number(this.droneVolumeTarget.value);
        if (this.droneBus) {
            this.droneBus.gain.setTargetAtTime(this.state.droneVolume, this.ctx.currentTime, 0.02);
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
        this.updateToggleButtonBlink();
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
        this.updateToggleButtonBlink();
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
        osc.connect(gain).connect(this.metroBus);
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

    setOctave() {
        this.state.octave = Number(this.octaveTarget.value);
        this.persist();
        if (this.drone && this.state.droneNote !== null) {
            this.startDrone(this.state.droneNote);
        }
    }

    startDrone(note) {
        const ctx = this.audio();
        this.stopDrone();

        const base = DRONE_OCTAVE_C2 * 2 ** (this.state.octave - 2 + note / 12);
        const freqs = this.state.fifth ? [base, base * 1.5] : [base];
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + 0.15);
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = Math.max(900, base * 8);
        filter.connect(gain).connect(this.droneBus);

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

    // Only as many dots as the current time signature has beats (4 for
    // 4/4, 6 for 6/8, ...) - the markup renders a fixed 6, the max any
    // option needs, so the rest just stay hidden instead of showing extra
    // dots that never light up.
    renderBeatDots() {
        this.beatDotTargets.forEach((dot, index) => {
            dot.classList.toggle('hidden', index >= this.state.beats);
        });
    }
}
