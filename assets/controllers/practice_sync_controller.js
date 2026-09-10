import { Controller } from '@hotwired/stimulus';

/**
 * Progress sync: reports the round's current state to the server so it
 * survives even if the tab closes mid-round (e.g. a "finished" event that
 * would otherwise never fire). The session already exists in the database
 * by the time this controller connects - the server creates it synchronously
 * at round start (SessionRecorder::start()) and hands back its id, so this
 * controller only ever reports progress against that id, never invents one.
 */
export default class extends Controller {
    static values = {
        tool: String,
        sessionId: Number,
        finished: Boolean,
        questionIndex: Number,
        totalQuestions: Number,
        correctCount: Number,
        intervalMs: { type: Number, default: 20000 },
    };

    connect() {
        this.send(this.finishedValue ? 'complete' : (this.questionIndexValue <= 1 ? 'start' : 'progress'));

        if (!this.finishedValue) {
            this.timer = setInterval(() => this.send('progress'), this.intervalMsValue);
        }
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    }

    send(event) {
        fetch('/api/practice-sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            keepalive: true,
            body: JSON.stringify({
                sessionId: this.sessionIdValue,
                event,
                questionIndex: this.questionIndexValue,
                totalQuestions: this.hasTotalQuestionsValue ? this.totalQuestionsValue : null,
                correctCount: this.hasCorrectCountValue ? this.correctCountValue : null,
            }),
        }).catch(() => {
            // Best-effort - a missed sync just means slightly stale history.
        });
    }
}
