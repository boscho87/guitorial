# Guitorial

Symfony app with small, focused practice tools for guitar music theory
(keys/chords/scales), German-only UI. Monkeytype-style: keyboard-driven,
settings → bounded practice round → summary, minimal chrome.

## Stack

- Symfony 7.4 / PHP 8.3 (dev) / PHP 8.5 (prod server), Doctrine ORM + Migrations, MySQL/MariaDB
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
no framework/DB coupling, covered by `tests/Domain/Theory/`. `Scale.php` (tonic + Dur/Moll/Harmonisch/Melodisch,
48 scales total) is wired into the Skalen-Generator: settings offers a mode switch between raw chromatic
tones (original v1 behaviour) and a single chosen scale, whose 7 notes then become the draw pool.

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

## Anonymous history/sync + optional login

`VisitorCookieSubscriber` mints a long-lived anonymous `guitorial_visitor` cookie. `PracticeSession`
entity/repo store per-round progress, upserted by `(visitorId, clientSessionId)`. The
`practice_sync_controller.js` Stimulus controller (attached to each tool's practice turbo-frame) POSTs to
`/api/practice-sync` on round start, every ~20s while practicing, and on completion - so a round survives
a closed tab. `/verlauf` lists a visitor's past rounds.

Login (Google/Microsoft via `knpuniversity/oauth2-client-bundle`) is **optional** and layered on top of
this rather than replacing it: `User` (table `app_user`) just "claims" a visitorId (see
`AccountLinker`/`src/Security/`) - no other entity gets a `user_id` column, every existing
`forVisitor()`-style query keeps working unchanged for logged-in users too. A brand-new account adopts
the current browser's anonymous visitorId as-is (no data migration needed); a returning account keeps
whatever visitorId it claimed at its first-ever login. **Known limitation, accepted on purpose:** logging
in on a second/new browser that already has its own anonymous progress does NOT merge that progress into
the account - only the very first login per account adopts existing data. No password login exists
anywhere in this app (OAuth-only).

## Deployment (LAMP shared hosting, no Docker in prod)

Push-to-deploy via a bare repo on the server (`~/guitorial.git`), triggered from GitHub Actions
(`.github/workflows/deploy.yml`) using an SSH key (`SSH_PRIVATE_KEY` secret - reuses the account's own
default key, not a scoped deploy key). The server's `post-receive` hook decides what happens per ref:

- `refs/heads/next` → staging (`stage.guitorial.com`, HTTP Basic Auth `stage`/see server) - single
  persistent checkout at `/usr/www/users/xlifep/stage.guitorial.com/`
- `refs/tags/v*` → production (`guitorial.com`) - **direct checkout** (`git checkout -f <tag>`) into a
  single, stable, persistent path `/usr/www/users/xlifep/guitorial.com/` - no releases/ directory, no
  symlink (the hook's own comment: "no symlink support on this host"). A future cron job (or anything
  else needing a stable prod path, e.g. for `php bin/console`) can rely on that path never moving.
  **Only tags deploy to prod, plain commits never do** - a plain push to `main` currently does nothing
  server-side at all (see below).
- Anything else (including a plain push to `main`) is logged and ignored.

`next` is the integration/staging branch; `main` is, for now, just kept roughly in sync by pushing to
both in parallel (a manual habit, not automation) - there is no automatic `next`→`main` promotion yet.
The hook itself isn't in this repo (it lives only on the server); its current content is mirrored here
for reference and any edit needs to be applied there by hand (over SSH) to actually take effect - a
change to this doc alone changes nothing live.

Both deploy paths run `composer install --no-dev`, `importmap:install` (vendors JS packages -
`assets/vendor/` is gitignored, not committed), migrations, `cache:clear`, `asset-map:compile`.

`release.yml` (semantic-release) runs on every push to `next` (`workflow_dispatch` still works too,
for a manual re-run) - `.releaserc.json`'s `branches` is `["next"]` to match. It uses a `RELEASE_TOKEN`
PAT rather than the default `GITHUB_TOKEN`, because pushes/releases made with the default token don't
cascade into other workflow runs (deploy.yml would silently never fire after a release).

**Commit messages on `next` must use a [Conventional Commits](https://www.conventionalcommits.org/)
prefix** (`feat:`, `fix:`, `chore:`, `docs:`, …; free text after the colon, German is fine) -
`@semantic-release/commit-analyzer` only detects a release-worthy change from that prefix. A
`chore:`/`docs:`-only push (or one with no new prefix at all) is analyzed and skipped, no release
created. When a release *is* warranted, semantic-release computes the next version, updates
`CHANGELOG.md` **and** `config/services.yaml`'s `app.version` (via `@semantic-release/exec`'s
`prepareCmd` in `.releaserc.json` - no more hand-bumping that parameter), commits both back to `next`,
and pushes a `vX.Y.Z` tag - which is exactly the tag `deploy.yml` watches, so **a qualifying push to
`next` now deploys to prod on its own**, no manual tag/push step anymore. That release commit only
lands on `next` automatically; keeping `main` in sync with it is on whoever next pushes to both.

The release commit's message deliberately does **not** contain `[skip ci]` (unlike
`@semantic-release/git`'s own default template) - that marker suppresses every workflow for *any*
push event whose head commit carries it, including the tag push pointing at that same commit, which
would silently stop `deploy.yml` from ever firing after a release. The harmless cost: that commit's
own push re-triggers `release.yml` once more, which just re-analyzes (sees a `chore:` commit, no
release needed) and exits - not a loop, since `chore:` never produces another release on its own.

`actions/checkout` needs `fetch-depth: 0` in both deploy jobs - a shallow clone has no common ancestor
with the server's existing branch, so the push gets rejected as a non-fast-forward even when it is one.

Env secrets (`DATABASE_URL` etc.) live only in `.env.local` on the server (`shared/prod.env.local` for
prod, directly in the stage checkout for staging), never in git. **A literal `%` in a `.env.local` value
must be escaped as `%%`** - Symfony's container parameter bag treats `%...%` as a parameter reference
otherwise (bit us with a URL-encoded DB password).

Login needs 4 more secrets in the same `.env.local` files: `OAUTH_GOOGLE_CLIENT_ID`,
`OAUTH_GOOGLE_CLIENT_SECRET`, `OAUTH_AZURE_CLIENT_ID`, `OAUTH_AZURE_CLIENT_SECRET` (placeholders/empty in
the committed `.env`). Obtained from:
- **Google**: [console.cloud.google.com](https://console.cloud.google.com) → a project → OAuth consent
  screen (External; testing mode is fine to start) → Credentials → OAuth client ID → Web application →
  redirect URI `https://<host>/connect/google/check` for each environment (`stage.guitorial.com`,
  `guitorial.com`, and `http://localhost:8080` for local dev).
- **Microsoft**: [portal.azure.com](https://portal.azure.com) → Microsoft Entra ID → App registrations →
  New registration → account type "Accounts in any organizational directory and personal Microsoft
  accounts" (matches `tenant: common` in `config/packages/knpu_oauth2_client.yaml`) → redirect URI
  `https://<host>/connect/azure/check` per environment → Certificates & secrets → new client secret.

Current prod version: see the footer of guitorial.com (`app.version`), or [`CHANGELOG.md`](./CHANGELOG.md)
for the full release history. Automatic semantic-release went live 2026-10-22 (see `CHANGELOG.md`'s
"Historie" header) - releases before that were manually tagged and are listed there verbatim from
git history, not from Conventional Commits.

## Conventions

- Single translation file `translations/messages.de.yaml` - German only, no i18n yet. Route *names* are
  English/neutral (`key_recognition_settings`); route *paths* are German (`/tonart-erkennen`) - keep that
  split if adding routes.
- CSRF token per tool via a `csrfIntention` constant in each controller.
- No comments explaining *what* code does - only genuinely non-obvious *why* (see the gotchas above for
  the bar).
