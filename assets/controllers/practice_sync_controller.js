import { Controller } from '@hotwired/stimulus';

/**
 * Prototype progress sync: reports the round's current state to the server
 * so it survives even if the tab closes mid-round (e.g. a "finished" event
 * that would otherwise never fire). The session id lives in sessionStorage,
 * keyed per tool, so re-connecting on every turbo-frame swap (each answer
 * replaces the frame's content, tearing this controller down and back up)
 * reuses the same round instead of starting a new one each question.
 */
export default class extends Controller {
    static values = {
        tool: String,
        finished: Boolean,
        questionIndex: Number,
        totalQuestions: Number,
        correctCount: Number,
        intervalMs: { type: Number, default: 20000 },
    };

    connect() {
        this.storageKey = `guitorial:sync-session:${this.toolValue}`;

        try {
            this.sessionId = sessionStorage.getItem(this.storageKey);
        } catch {
            this.sessionId = null;
        }
        if (!this.sessionId) {
            this.sessionId = this.generateId();
            this.persistSessionId();
        }

        this.send(this.finishedValue ? 'complete' : (this.questionIndexValue <= 1 ? 'start' : 'progress'));

        if (this.finishedValue) {
            this.clearSessionId();
        } else {
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
                sessionId: this.sessionId,
                tool: this.toolValue,
                event,
                questionIndex: this.questionIndexValue,
                totalQuestions: this.hasTotalQuestionsValue ? this.totalQuestionsValue : null,
                correctCount: this.hasCorrectCountValue ? this.correctCountValue : null,
            }),
        }).catch(() => {
            // Best-effort - a missed sync just means slightly stale history.
        });
    }

    generateId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    persistSessionId() {
        try {
            sessionStorage.setItem(this.storageKey, this.sessionId);
        } catch {
            // Private mode / quota - sync still works, just won't dedupe across reconnects.
        }
    }

    clearSessionId() {
        try {
            sessionStorage.removeItem(this.storageKey);
        } catch {
            // Nothing to clean up if storage was never usable.
        }
    }
}
