# Guitorial

[guitorial.com](https://guitorial.com) — small, focused practice tools for guitar/bass music
theory (keys, chords, scales), plus a practice diary to plan and track recurring exercises.
German-only UI. Monkeytype-style: keyboard-driven, settings → bounded practice round → summary,
minimal chrome.

## The tools

1. **Tonart erkennen** (`/tonart-erkennen`) — chord progression → key
2. **Kapo-Trainer** (`/kapo-trainer`) — capo shape transposition
3. **Funktion bestimmen** (`/funktion`) — chord/note → scale degree
4. **Griffbrett** (`/griffbrett`) — fretboard note/string/fret
5. **Skalen-Generator** (`/skalen-generator`) — random-tone practice generator

Plus a **practice diary** (`/tagebuch`): recurring exercises with a rhythm (daily/weekly), an
optional time target, linked resources (one of the 5 tools or an external link/video), notes, and
a history/progress view — all scoped to an anonymous per-visitor cookie, with optional
Google/Microsoft login layered on top (no password auth).

## Stack

- Symfony 7.4 / PHP 8.3 (dev) / PHP 8.5 (prod), Doctrine ORM + Migrations, MySQL/MariaDB
- Twig, server-rendered; Hotwire Turbo (frames) + Stimulus via Symfony AssetMapper — no Node
  needed at runtime
- Tailwind CSS v4 + DaisyUI 5 — compiled `assets/styles/app.css` is committed to git, so
  production never needs Node

See [`CLAUDE.md`](./CLAUDE.md) for the full architecture rundown (domain model, conventions,
deployment pipeline) — it's written for an AI coding agent working in this repo, but it's the most
complete map of the codebase either way.

## Local development

Requires Docker.

```bash
docker compose up -d
```

- App: http://localhost:8080
- DB: localhost:9080

After any template/CSS change:

```bash
docker compose exec guitorial-php-fpm npm run build:css
```

## Tests

```bash
docker compose exec guitorial-php-fpm vendor/bin/phpunit
```

Pure PHP domain-logic tests (`tests/Domain/...`) — no database required.

## Deployment

Push-to-deploy via GitHub Actions against a bare repo on the hosting server:

- `main` → staging (`stage.guitorial.com`)
- `v*` tags → production (`guitorial.com`), atomic release with the last 5 kept

Releases (tags, `CHANGELOG.md`, GitHub Releases) are generated automatically by
[semantic-release](https://semantic-release.gitbook.io/) from
[Conventional Commits](https://www.conventionalcommits.org/) on `main` — see
[`CHANGELOG.md`](./CHANGELOG.md) for the release history. Commit messages should use a
`feat:`/`fix:`/`chore:`/… prefix; everything after the colon can stay in German, matching the
rest of the project.

Full pipeline details (secrets, the exact deploy steps, known gotchas) live in `CLAUDE.md`.

## License

No license file yet — all rights reserved by default. Ask before reusing.
