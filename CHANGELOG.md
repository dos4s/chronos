# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Chronos reads and writes tasks.** Every task list that takes VTODOs is a project, and the tasks in it
  are indexed in `chronos_tasks`, kept level with CalDAV by a listener on Nextcloud's calendar events — so
  a task edited on a phone shows up here, and one edited here shows up there.
- Dependencies between tasks are read from `RELATED-TO` as RFC 9253 describes them, including `GAP` lead
  and lag, and stored with their direction normalised.
- A task API: list, create, edit and delete, each write carrying the etag it is based on, so two people
  editing the same task cannot silently overwrite one another.
- An editor's courtesy: changing a task in Chronos leaves every property it does not manage — descriptions,
  categories, alarms, recurrence rules, other clients' own extensions — exactly as it found them.
- Background jobs that read a user's existing lists the first time they open Chronos, and that check the
  index against CalDAV hourly and repair whatever drifted.

### Changed

- The lint stack moves to eslint 10 with `@nextcloud/eslint-config` 9 and a flat `eslint.config.js`. The
  new rule set's findings are fixed throughout: sorted imports, static classes separated from dynamic ones,
  and camelCase component props. `@typescript-eslint/parser` becomes a direct dev dependency, which the
  config requires by name.
- The dashboard widget reports a failure the way the app does, with a message, instead of writing to the
  browser console where nobody would see it.
- TypeScript 7 stays declined: `vue-tsc` cannot load it, so `.vue` files would lose type checking
  (`docs/ARCHITECTURE.md` §17, D14).

### Fixed

- A calendar whose component set cannot be read is no longer offered as a project (D12): an event-only
  calendar appearing as a project is worse than a list the user can fix in the Tasks app.

### Removed

- `pauses_blob`, the safety copy kept for one release after the seconds migration.

## [0.2.0] - 2026-10-16

The foundation for everything in the roadmap: correct data, one place for each rule, and a frontend that
can grow. See `docs/ARCHITECTURE.md` §20 (M1).

### ⚠ Before upgrading

- **Back up your database.** This release converts every session from milliseconds to seconds and moves
  pauses into their own table. Nextcloud migrations cannot be rolled back. The conversion is idempotent and
  keeps `pauses_blob` as a safety copy until the next release.
- **The minimum Nextcloud version is now 32** (was 31).
- Sessions that had been left running alongside a newer one — possible because of the race fixed here — are
  closed at the moment the next session started, and the migration reports how many it closed.

### Added

- Analytics endpoint that aggregates over any date range, bucketed by day in your own time zone, with
  sessions crossing midnight split at midnight
- `chronos_pauses`: pauses are rows now, so they can be queried and validated
- Feature toggles for the modules the roadmap adds, admin and per user (`/settings/modules`)
- Session editor: breaks can be added from the calendar and edited in the dialog, which no longer loses
  pauses it did not understand
- Unit tests that need no Nextcloud installation (63 PHP, 33 frontend), a typecheck, and CI workflows that
  run all of them on every pull request

### Changed

- Dependencies brought up to date one at a time, each behind the full check suite: `@nextcloud/vue` 9.13,
  `@nextcloud/dialogs` 7.5, `@nextcloud/vite-config` 2.5.4, `@nextcloud/stylelint-config` 3.2.2, Vite 7.3.7,
  Vue 3.5.44, `@vue/tsconfig` 0.9.1, rector 2.7 and `nextcloud/coding-standard` 1.5. Vite 8 is declined
  while `@nextcloud/vite-config` requires Vite 7; TypeScript 7 waits on the eslint config, which has to move
  to eslint 10 first (`docs/ARCHITECTURE.md` §17, D14)
- All stored timestamps and durations are UNIX seconds; the API sends and accepts seconds only, and rejects
  millisecond values from a stale page with `400 timestamp_unit`
- One `SessionService` holds every session rule, used by the app, the quick links and the widget alike
- The frontend is a router with Pinia stores and lazy-loaded views; `App.vue` went from 2,050 lines to 150,
  and the calendar library is only fetched when the session editor is opened
- One typed API client, shared with the dashboard widget, on `@nextcloud/axios` and `@nextcloud/router`
  instead of `OC.*` globals
- Every string goes through `@nextcloud/l10n`
- Errors are typed exceptions mapped to HTTP once, so each failure gets a message that says what happened

### Fixed

- Analytics counted only the newest 100 sessions, so every total was quietly short
- A quick check-out added its pause to the total but never recorded it, leaving the editor disagreeing with
  the duration
- Overlapping pauses were counted twice, and a pause could be stored outside its session
- Two simultaneous check-ins could both open a session; a unique index now makes that impossible
- Buttons passed `type` where `@nextcloud/vue` 9 expects `variant`, so every button rendered unstyled
- The Timer navigation entry never appeared active
- eslint could not parse the TypeScript in any component, stylelint had no rules to load, and psalm could
  not resolve Doctrine or DAV types — all three now pass, and their configuration is in the repository
  instead of being gitignored

### Removed

- The template `ApiController` and its placeholder test, and the template README
- `openapi.json` and its CI check: the spec described only that template endpoint, and every route the UI
  uses is internal. Both return with the OCS API (`docs/ARCHITECTURE.md` §14.1)
- The millisecond columns `start_time`, `end_time`, `paused_at` and `paused_duration`

## [0.1.1]

### Added

- First release
