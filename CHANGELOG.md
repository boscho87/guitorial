# [1.18.0](https://github.com/boscho87/guitorial/compare/v1.17.0...v1.18.0) (2026-10-22)


### Features

* **diary:** Deaktivierungs-Schwelle auf 14 Tage, Anzahl pro Tab anzeigen ([fad4255](https://github.com/boscho87/guitorial/commit/fad42551bfcee6dc8f9870c4ffc3218940e90046))
* **profile:** Streak-Erinnerungen im Profil ein-/ausschaltbar ([ac544cb](https://github.com/boscho87/guitorial/commit/ac544cbaa799bed5842eae990b27c076af4480bb))

# [1.17.0](https://github.com/boscho87/guitorial/compare/v1.16.0...v1.17.0) (2026-10-22)


### Features

* **diary:** Streak-Erinnerungsmail bei gefährdetem 7+-Tage-Streak ([be1e653](https://github.com/boscho87/guitorial/commit/be1e65398e19486c4e475d0fc7c81892aaaff2b8))

# [1.16.0](https://github.com/boscho87/guitorial/compare/v1.15.0...v1.16.0) (2026-10-22)


### Features

* **diary:** Konfetti-Animation bei "sicher" abgeschlossener Übung ([ec8bda5](https://github.com/boscho87/guitorial/commit/ec8bda56e8d3ab58643ec395860ca146b05d5907))

# [1.15.0](https://github.com/boscho87/guitorial/compare/v1.14.0...v1.15.0) (2026-10-22)


### Features

* **mail:** Mailer-Infrastruktur einrichten (DSN, Absender, lokales Mailpit) ([5b73abc](https://github.com/boscho87/guitorial/commit/5b73abc3968a73970e7291f9ea4879fc5d88d3ec))

# Changelog

Alle nennenswerten Änderungen an Guitorial werden hier festgehalten.

Ab dem nächsten Release übernimmt [semantic-release](https://semantic-release.gitbook.io/) diese
Datei automatisch, basierend auf [Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`, `fix:`, `fix!:`/`BREAKING CHANGE:` für Major, …) auf `next`. Der Abschnitt unten wurde
einmalig aus der bisherigen Tag-Historie (manuell gepflegte `app.version` + Git-Tags) rekonstruiert
und bildet nicht das Conventional-Commits-Format ab, da die Commits bis hierhin frei formuliert
waren.

## Historie (vor der Automatisierung)

### v1.13.0 — 2026-10-22
Notiz in Verwalten-Liste kürzen, Übung per Klick direkt dort anlegen

### v1.12.0 — 2026-10-22
CSRF-Problem bei lange offenen Seiten beheben: Session aktiv am Leben halten

### v1.11.2 — 2026-10-17
Übungsbeschreibung: Notizfeld nutzt auf grösseren Bildschirmen mehr Platz

### v1.11.1 — 2026-10-17
Bump app.version for v1.11.1

### v1.11.0 — 2026-10-16
Bump app.version for v1.11.0

### v1.10.2 — 2026-10-13
Bump app.version for v1.10.2

### v1.10.1 — 2026-10-13
Bump app.version for v1.10.1

### v1.10.0 — 2026-10-13
Metronome/drone: save per exercise; fix beat-dot count

### v1.9.0 — 2026-09-25
Exercise timer: grace period after opening a resource; player gets a fixed slot

### v1.8.1 — 2026-09-25
Bump app.version for v1.8.1

### v1.8.0 — 2026-09-25
Bump app.version for v1.8.0

### v1.7.0 — 2026-09-24
Bump app.version for v1.7.0

### v1.6.0 — 2026-09-24
Bump app.version for v1.6.0

### v1.5.0 — 2026-09-24
Bump app.version for v1.5.0

### v1.4.2 — 2026-09-24
Bump app.version for v1.4.2

### v1.4.1 — 2026-09-24
Bump app.version for v1.4.1

### v1.4.0 — 2026-09-24
Bump app.version for v1.4.0

### v1.3.0 — 2026-09-20
Bump app.version for v1.3.0

### v1.2.1 — 2026-09-20
Bump app.version for v1.2.1

### v1.2.0 — 2026-09-20
Bump app.version for v1.2.0

### v1.1.3 — 2026-09-20
Bump app.version for v1.1.3

### v1.1.2 — 2026-09-19
Bump app.version for v1.1.2

### v1.1.0 — 2026-09-19
Bump app.version for v1.1.0

### v1.0.0 — 2026-09-19
Bump app.version for v1.0.0

### v0.25.0 — 2026-09-19
Bump app.version for v0.25.0

### v0.24.0 — 2026-09-19
Bump app.version for v0.24.0

### v0.23.0 — 2026-09-19
Bump app.version for v0.23.0

### v0.22.0 — 2026-09-19
Bump app.version for v0.22.0

### v0.21.0 — 2026-09-19
Versionsnummer im Footer + UI/UX Feinschliff Phase 0 (gemeinsame Bausteine)

### v0.20.0 — 2026-09-19
Bist-du-noch-dran-Check-in alle 10 Min. nach Überschreiten der Zielzeit, mit Tab-Titel-Blinken und Browser-Benachrichtigung

### v0.19.0 — 2026-09-19
Toast-Feedback nach Speicheraktionen (Routine, Tagesnotiz), alter Banner durch schwebenden Toast ersetzt

### v0.18.1 — 2026-09-19
Fix Content-missing regression on Neue Übung/Bearbeiten/Teilen/Starten/Aktivieren links, Toast-Position/Kontrast verbessert

### v0.18.0 — 2026-09-19
Modal für Übung löschen/verwerfen, live mitlaufender Fortschrittsbalken mit Minuten-Puls auf der Übungs-Laufseite

### v0.17.0 — 2026-09-19
Modal statt window.confirm() für Löschen, kein Scroll-Sprung mehr; weitere Animations-Details

### v0.16.0 — 2026-09-19
Duplikate beim erneuten Übernehmen einer geteilten Routine verhindern (Herkunfts-Tracking)

### v0.15.1 — 2026-09-18
Mobile-Overflow bei letzten 7 Tagen behoben, Teilen-Button auf Edit-Seite und Routine-Karte

### v0.15.0 — 2026-09-18
Routine teilen: öffentlicher Link, Übernehmen nur für eingeloggte Nutzer

### v0.14.2 — 2026-09-18
Erledigte Tage zuerst, History ohne Edit/Notiz, Fortschritt-Farbverlauf, Lade-/Hover-Feedback

### v0.14.1 — 2026-09-17
Verlauf: Notiz/Lernstand anzeigen, Datum verlinkt zum Tag; Tagesnotiz nur für heute editierbar

### v0.14.0 — 2026-09-17
Per-day note; history no longer filtered by today's routine

### v0.13.7 — 2026-09-17
Stop Turbo serving a stale Start/Fortsetzen snapshot on the overview pages

### v0.13.6 — 2026-09-17
Stop creating duplicate running sessions; accumulate the day's total per exercise instead of resetting

### v0.13.5 — 2026-09-17
Make the away-time cap unconditional and add tab-visibility pause for the running clock

### v0.13.4 — 2026-09-17
Shrink heartbeat grace from 90s to 2s so away-time actually stops counting

### v0.13.3 — 2026-09-17
Fix root cause: no heartbeat landed within the first ~20s, leaving the away-gap uncapped

### v0.13.2 — 2026-09-17
Fix running clock counting through a literal browser back/forward (bfcache)

### v0.13.1 — 2026-09-17
Fix Turbo cache serving a stale running-page clock; prefill today's note as editable

### v0.13.0 — 2026-09-17
Limit exercise notes to one per day, drop quick-note UI from the card

### v0.12.2 — 2026-09-17
Fix Notiz-hinzufuegen being unclickable under the full-card overlay

### v0.12.1 — 2026-09-17
Add history link on exercise card, show last note while practicing, fix running clock counting away-time

### v0.12.0 — 2026-09-17
Fix stale-session timer, extend return-to-routine to all actions and tools, remember tool settings, mobile fullscreen practice mode

### v0.11.0 — 2026-09-16
Heute is always routine-driven; a visitor always has one active routine

### v0.10.0 — 2026-09-16
Fix runaway session durations, active-routine flow, resource-card fix, www redirect

### v0.9.4 — 2026-09-14
Add social share preview image (og:image/twitter:image)

### v0.9.3 — 2026-09-13
v0.9.3

### v0.9.2 — 2026-09-13
v0.9.2

### v0.9.1 — 2026-09-13
v0.9.1

### v0.9.0 — 2026-09-13
v0.9.0

### v0.8.0 — 2026-09-13
v0.8.0

### v0.7.9 — 2026-09-13
Add personal records (best score, fastest time) per practice tool

### v0.7.8 — 2026-09-13
Fix daily-target progress counting time while a round sits abandoned

### v0.7.7 — 2026-09-12
Landing page: more substance before the tool grid, login CTA

### v0.7.6 — 2026-09-12
Theme tweaks: darker Azur theme, more visible contrast-theme outlines

### v0.7.5 — 2026-09-12
Host Microsoft identity association file for domain verification

### v0.7.4 — 2026-09-11
Diary card: more space below the top row, pointer cursor on play buttons

### v0.7.3 — 2026-09-10
Diary card: icon-only green play button, top-left, matching the checkbox

### v0.7.2 — 2026-09-10
Diary card: checkbox moves to top-right, Start/Fortsetzen gets a play icon

### v0.7.1 — 2026-09-10
Diary overview: 2-col grid on desktop, even internal spacing

### v0.7.0 — 2026-09-10
Diary overview: show notes (truncated) and badge rhythm/group per item

### v0.6.9 — 2026-09-10
Add Training nav link back, now that the logo goes to the diary when logged in

### v0.6.8 — 2026-09-10
Redesign manage list: 2-col grid, icon actions, inline rhythm/group edit

### v0.6.7 — 2026-09-10
Fix "mark done" never checking off minute-target exercises

### v0.6.6 — 2026-09-10
Make the diary checkmark itself clickable instead of a separate button

### v0.6.5 — 2026-09-10
Fix quick logout, add login-state indicator, fix mobile exercise cards

### v0.6.4 — 2026-09-10
Improve mobile layout: header nav and practice progress bar

### v0.6.3 — 2026-09-10
Fix Content missing on "Übung übernehmen" across all 5 tools

### v0.6.1 — 2026-09-10
Fix 500 on /verlauf for sessions with no built-in tool

### v0.6.2 — 2026-09-10
Fix 500 on /verlauf for sessions with no built-in tool

### v0.4.0 — 2026-09-10
Exercise/session plan system, optional Google/Microsoft login, SEO polish

### v0.5.0 — 2026-09-10
Add exercise/session plan system, optional Google/Microsoft login, and SEO polish

### v0.6.0 — 2026-09-10
Add exercise/session plan system, optional Google/Microsoft login, and SEO polish

### v0.3.0 — 2026-09-09
v0.3.0: SEO metadata, legal pages, issue templates, cap answers at 10, tab-switch fix

### v0.2.0 — 2026-09-09
v0.2.0: practice diary, Verlauf progress chart + pagination, toast/UX fixes

### v0.1.1 — 2026-09-09
v0.1.1: add CLAUDE.md project context

### v0.1.0 — 2026-09-09
Add anonymous practice history + rework scale generator into bounded rounds
