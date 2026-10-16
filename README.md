# Chronos

Time tracking tied to your Nextcloud Tasks lists — and, from here on, a project-management layer built on
the same tasks.

Chronos treats every Nextcloud Tasks list as a project. Check in, pause, check out; see where the week
went. Nothing is duplicated: the lists and tasks are the ones your other CalDAV clients already see.

## Features

- Check in and out with a live timer, pause and resume without losing context
- Assign each session to any of your Tasks lists, with an optional note
- Analytics over any date range, aggregated server-side: per day, per project, with weekly goals
- Session editor: see your day as a calendar and correct times or add breaks you forgot to log
- Dashboard widget with the full controls from the Nextcloud home
- Quick links for check-in and check-out, for a desktop shortcut or a hotkey

## Requirements

- Nextcloud 32 or 33
- PHP 8.1 or newer
- Node 24 and npm 11 to build the frontend

## Installing from source

```bash
git clone git@github.com:dos4s/chronos.git
cd chronos
composer install --no-dev -o
npm ci && npm run build
```

Put the directory in your Nextcloud's `custom_apps/` (or `apps/`) and enable it:

```bash
occ app:enable chronos
```

`scripts/build.sh` builds the frontend and `scripts/deploy.sh` syncs a build to a remote instance; both
read their settings from environment variables.

## Developing

```bash
composer install            # PHP dependencies and the dev tools
npm ci

npm run dev                 # build the frontend once, unminified
npm run watch               # rebuild on change

composer run lint           # php -l over everything
composer run cs:check       # coding standard (cs:fix to apply)
composer run psalm           # static analysis, errorLevel 1
composer run test:unit      # PHP tests that need no Nextcloud installation

npm run typecheck           # vue-tsc over src/
npm run test                # Vitest
npm run lint                # eslint
npm run stylelint           # stylelint
```

`composer run test:integration` runs the tests that need a live server; they expect the app to sit inside a
Nextcloud checkout (`apps/chronos` or `custom_apps/chronos`), which is what `tests/bootstrap.php` assumes.

Every one of these runs on pull requests.

## Architecture

[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) is the design document: goals, the data model, how Chronos
stays compatible with other CalDAV clients, the module map, and the roadmap from time tracker to project
management. Worth reading before a larger change — in particular §8, which is why every timestamp in this
app is an integer number of UNIX seconds.

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
