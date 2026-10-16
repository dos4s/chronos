# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.1] - 2026-10-16

Found by deploying 0.3.0 to a real instance and running the integration suite there, which is the only
place it could have been found.

### ⚠ If you installed 0.3.0

Creating a workflow failed with *Field 'vtodo_status' doesn't have a default value*, so no workflow could
be created at all. Nothing was lost — the insert was refused, not half-written. Upgrade to 0.3.1 and
create it again.

### Fixed

- **A workflow template could not be created on MySQL.** `QBMapper` builds its insert from the fields an
  entity reports as changed, and an entity reports no change when given the value the property already
  holds — so a status whose standard status was `NEEDS-ACTION`, the entity's own default, left that column
  out of the insert, and a NOT NULL column with no default refused the row (`docs/ARCHITECTURE.md` D30).
  The property now defaults to a value no status has, the column gets a database default, and a unit test
  asserts every NOT NULL field would actually be sent.

## [0.3.0] - 2026-10-16

The task layer, finished: projects can now be grouped and configured, and tasks can move through statuses
of your own rather than only the four iCalendar ones.

### Added

- **Areas and project settings.** Projects can be grouped into areas — Work, Personal, Homelab — which
  exist only in Chronos, since CalDAV has nothing above a calendar. A project can also be marked archived,
  which keeps it out of the pickers without deleting anything, and recorded as part of another project, for
  one split out of a larger one. Deleting an area never deletes a project: the projects in it simply stop
  being in an area.
- A project only gets a settings row once it is configured, and loses it again when its settings go back to
  the defaults, so an untouched task list still needs no row anywhere (`docs/ARCHITECTURE.md` §6, §10.2).
- **Workflows.** A project can have statuses of its own, created from a **Support**, **Development** or
  **Personal** template. Every status maps to exactly one standard iCalendar `STATUS`, so a task in
  "Waiting for customer" is still `IN-PROCESS` to the Tasks app and to your phone, and one in "Rejected"
  is still `CANCELLED`. Statuses can be renamed, coloured and given a work-in-progress limit; their keys
  and their mapping stay as they are, because changing one would quietly change what the tasks already in
  that status mean.
- **Transition rules.** A workflow with no rules allows every move, which is how a template arrives; add
  rules and only those moves are allowed, each able to require a note. A status can also require that the
  task have an assignee. Both are enforced on the server, before anything is written.
- **Status history.** Every status change and every assignee change is recorded — whoever made it, from
  whichever side of CalDAV — with who, when, from where and the note, and it is shown on the task. This
  ships now rather than with service levels because time in a state cannot be worked out afterwards.
- **Settings pages.** An administrator chooses which modules this instance offers; each user chooses which
  of those they see. `projects` and `workflow` are the first two there is anything to choose. A module
  switched off keeps its data, and its endpoints answer like endpoints that are not there.
- The workflow status travels in `X-CHRONOS-STATUS`, and the Chronos tables remain the authority on it: a
  client that strips the property loses nothing, and Chronos writes it again on its next write. If another
  client ticks a task off, the workflow status follows the standard one to a final status — which is also
  what keeps the Chronos checkbox working under a workflow.
- Attaching a workflow to a project gives the tasks already in it the status that matches the standard
  status they have, so a list never sits without statuses waiting for something to re-read it.

### Changed

- `GET /tasklists` is now `GET /projects`, and reports what Chronos knows about each list — its area, its
  parent, whether it is archived, which workflow it uses — beside the list itself. The old route is gone;
  nothing outside this app used it.
- The task API takes a `statusKey` and a `comment`, and `GET /tasks/{uid}/history` returns a task's status
  history.

## [0.2.1] - 2026-10-16

Found by upgrading a real instance, which is the only place some of these could have been found.

### ⚠ If you already upgraded to 0.2.0

Check whether any session lost its pause time. 0.2.0 recomputed each session's paused total from its pause
rows, so a session whose pause time was recorded only as a total — a quick check-out, or a session older
than the `pauses_blob` column — had that total set to zero, and the time became *worked* time instead.

Restore it from the backup taken before the upgrade: for every session whose old `paused_duration` was
greater than zero while its `pauses_blob` was empty or missing, insert one pause row of that length inside
the session and set `paused_seconds` to match. On the instance this was found on, two sessions had lost
2 h 31 m between them.

### Fixed

- **The seconds migration no longer drops pause time that no pause described.** It compares the legacy
  total with what the pause rows account for and adds the difference back as a pause, placed as late in the
  session as there is room for, since only the duration was ever recorded and never when it happened
  (`docs/ARCHITECTURE.md` §8.7, D25).
- The integration suite can run on a real instance. It required the server's own test bootstrap, which a
  release tarball does not ship; it now boots `lib/base.php` instead (D26).
- Two integration tests assumed a pause could be measured inside the same second, and one defined a method
  PHPUnit declares final (D27).

### Changed

- The README's verification section now covers what an upgrade on a real instance actually involves,
  including that `occ upgrade` processes every app, so an unrelated broken one can leave the instance in
  maintenance mode.

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
- **A Tasks view.** Pick a project and see its tasks, nested under their parents, open work before
  finished. Add a task, rename one in place, tick it off, add subtasks, set dates, priority, progress and
  kind, or delete it. A date can be all-day or at a time, and stays the kind you chose. A list shared with
  you read-only says so instead of pretending to be editable, and an edit someone else got to first
  reloads the task and tells you, rather than quietly losing one of the two changes.

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
