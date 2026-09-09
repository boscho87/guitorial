# Guitorial

Symfony app with small, focused practice tools for guitar music theory
(keys/chords/scales), German-only UI. Monkeytype-style: keyboard-driven,
settings → bounded practice round → summary, minimal chrome.

## Stack

- Symfony 7.3 / PHP 8.3 (dev) / PHP 8.5 (prod server), Doctrine ORM + Migrations, MySQL/MariaDB
- Twig, server-rendered; Hotwire Turbo (frames) + Stimulus via Symfony AssetMapper (no Node needed at runtime)
- Tailwind CSS v4 + DaisyUI 5 - compiled `assets/styles/app.css` is **committed to git**, so prod never needs Node.
  After any template/CSS change: `docker compose exec guitorial-php-fpm npm run build:css`, then check `git diff --stat assets/styles/app.css`.
- Local dev: Docker Compose (`guitorial-php-fpm`, `guitorial-webserver` nginx on :8080, `guitorial-db` mysql on :9080). `docker compose up -d`.
- Tests: `docker compose exec guitorial-php-fpm vendor/bin/phpunit` (pure PHP domain-logic tests, no DB).

## The 5 tools (`src/Controller/*Controller.php`, `templates/<tool>/`, registered in `src/Domain/Quiz/ToolRegistry.php`)

1. **Tonart erkennen** (`/tonart-erkennen`) - chord progression → key
2. **Kapo-Trainer** (`/kapo-trainer`) - capo shape transposition
3. **Funktion bestimmen** (`/funktion`) - chord/note → scale degree
4. **Griffbrett** (`/griffbrett`) - fretboard note/string/fret
5. **Skalen-Generator** (`/skalen-generator`) - random-tone practice generator (no grading; bounded round, optional auto-advance timer)

Domain logic lives in `src/Domain/Theory/` (Note, MusicKey, CapoTransposer, GuitarString, Scale) - pure PHP,
no framework/DB coupling, covered by `tests/Domain/Theory/`. `Scale.php`/`ScaleType` (Dur/Moll/Harmonisch/Melodisch)
is built and tested but **not yet wired into the Skalen-Generator controller** - v1 only draws single tones,
scale types are the planned next step.

### Shared pattern (tools 1-4, graded)

Session-based bounded round: settings (chip-toggle key/mode selection, persisted to localStorage via
`key_settings_controller.js`) → start → practice (Turbo-frame, answer grid, toast feedback, immediate
auto-advance to next question) → summary (score, restart or change settings). `WeightedQuestionPicker`
weights question selection toward previously-wrong answers (global, not per-visitor - shared frequency
heuristic across all visitors).

**Grading always recomputes correctness from the guess** (e.g. `CapoTransposer::soundingChord()`,
`GuitarString::noteAt()`) rather than comparing to one stored "expected" value - several early bugs were
exactly this (e.g. C-Dur/G-Dur chord-progression ambiguity, string 1/6 sharing every open note).

### Known gotcha: Turbo frame scoping

Content outside the matching `<turbo-frame id="...">` in a response is silently discarded on
frame-scoped navigation, and a link/form *inside* a frame defaults to frame-scoped navigation even when
it should leave the tool entirely (e.g. "change settings" going to a whole different page). Fix: add
`data-turbo-frame="_top"` to any such link. Bit this twice already (toast markup, progress counter,
the settings-change link) before it became a checklist item - **any new interactive element inside a
practice turbo-frame needs a moment's thought about which navigations should stay scoped vs. go top-level.**

### Known gotcha: PHP array literal evaluation order

`['queue' => $queue, 'current' => array_shift($queue)]` captures `'queue'` as a snapshot *before* the
shift runs (PHP evaluates array literal entries left-to-right) - duplicated the first draw in the scale
generator. Do the mutation as its own statement before building the array.

## Anonymous history/sync (prototype, no login yet)

`VisitorCookieSubscriber` mints a long-lived anonymous `guitorial_visitor` cookie. `PracticeSession`
entity/repo store per-round progress, upserted by `(visitorId, clientSessionId)`. The
`practice_sync_controller.js` Stimulus controller (attached to each tool's practice turbo-frame) POSTs to
`/api/practice-sync` on round start, every ~20s while practicing, and on completion - so a round survives
a closed tab. `/verlauf` lists a visitor's past rounds. **Login/account migration of this history is
explicitly future work**, not built yet.

## Deployment (LAMP shared hosting, no Docker in prod)

Push-to-deploy via a bare repo on the server (`~/guitorial.git`), triggered from GitHub Actions
(`.github/workflows/deploy.yml`) using an SSH key (`SSH_PRIVATE_KEY` secret - reuses the account's own
default key, not a scoped deploy key). The server's `post-receive` hook decides what happens per ref:

- `refs/heads/main` → staging (`stage.guitorial.com`, HTTP Basic Auth `stage`/see server) - single
  persistent checkout at `/usr/www/users/xlifep/stage.guitorial.com/`
- `refs/tags/v*` → production (`guitorial.com`) - atomic release: checks out to
  `/usr/www/users/xlifep/guitorial.com/releases/<tag>/`, then re-points the `public` symlink; keeps the
  last 5 releases. **Only tags deploy to prod, plain commits never do.**

Both run `composer install --no-dev`, `importmap:install` (vendors JS packages - `assets/vendor/` is
gitignored, not committed), migrations, `cache:clear`, `asset-map:compile`.

`release.yml` (semantic-release) is manual-only (`workflow_dispatch`), not triggered on push - and uses a
`RELEASE_TOKEN` PAT rather than the default `GITHUB_TOKEN`, because pushes/releases made with the default
token don't cascade into other workflow runs (deploy.yml would silently never fire after a release).

`actions/checkout` needs `fetch-depth: 0` in both deploy jobs - a shallow clone has no common ancestor
with the server's existing branch, so the push gets rejected as a non-fast-forward even when it is one.

Env secrets (`DATABASE_URL` etc.) live only in `.env.local` on the server (`shared/prod.env.local` for
prod, directly in the stage checkout for staging), never in git. **A literal `%` in a `.env.local` value
must be escaped as `%%`** - Symfony's container parameter bag treats `%...%` as a parameter reference
otherwise (bit us with a URL-encoded DB password).

Current prod version: `v0.1.0`. No formal semantic-release cycle has run yet - tags so far were pushed
manually.

## Conventions

- Single translation file `translations/messages.de.yaml` - German only, no i18n yet. Route *names* are
  English/neutral (`key_recognition_settings`); route *paths* are German (`/tonart-erkennen`) - keep that
  split if adding routes.
- CSRF token per tool via a `csrfIntention` constant in each controller.
- No comments explaining *what* code does - only genuinely non-obvious *why* (see the gotchas above for
  the bar).
