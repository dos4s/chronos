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
- **Tasks**: read and written as VTODOs in your own lists, nested under their parents, with dates,
  priority, progress and subtasks — edited on your phone, they show up here, and the other way round
- **Projects and areas**: group your lists, archive one without deleting anything, and record that one
  project is part of another
- **Workflows**: statuses of your own, from a Support, Development or Personal template, with transition
  rules and a history of every status change. Every status maps to one of the four standard iCalendar
  statuses, so your other clients always see something sensible
- **Kanban**: a board whose columns *are* those statuses — moving a card changes the real status, in your
  other clients too. Drag one card or several, from the mouse, a finger or the keyboard; undo the last move
  with a button or `Ctrl+Z`; set how many cards belong in a column and which moves are allowed. A project
  without a workflow gets a board of the four standard statuses
- **Subtasks on the board**: a subtask is a card of its own — it has a status of its own — but it says
  whose it is, and a card says how much of the work under it is done. Roll the subtasks up when a board has
  too many of them, and a parent dropped into *Done* can take its unfinished subtasks along
- **Timeline**: your tasks' dates as bars. Drag one to move it, its edges to change a date, and the dot on
  its right onto another task to make that task wait for this one — written into the task as a standard
  iCalendar relationship, so anything that reads your calendar can see it. Choose per project whether a
  move warns you about what it breaks or carries the dependents along; either way the bars show you what
  will happen before you let go. Most tasks have one date or none, so one date draws a suggestion and a
  double-click on an empty row gives a task a day to live in
- **Roadmap**: every project at once, grouped by area, with its milestones marked on it. Click a line to
  open its timeline
- **Finding things**: a search box in every task view, and finished work hidden until you ask for it — most
  of a real list is already done. The running timer stays in the sidebar and in the browser tab wherever
  you are, and `?` lists everything the app can be told to do
- **Promote a subproject to a list** once it has outgrown being a task: its tasks go with it, keeping their
  history, their dependencies and the time logged against them

Projects, workflows, the kanban board, the timeline and the roadmap are modules: an administrator chooses what the instance offers under
*Administration settings → Additional settings*, and each user chooses what they see under *Personal
settings → Additional settings*. Switching a module off keeps its data.

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

## Verifying an upgrade on your own instance

**Back up the database first.** Nextcloud migrations cannot be rolled back, and 0.2.0 converts every
session from milliseconds to seconds and moves pauses into their own table.

```bash
# 1. deploy, then run the migrations
sudo -u www-data php occ upgrade                     # or: occ migrations:migrate chronos
sudo -u www-data php occ app:enable chronos

# 2. ask the app whether the schema is what it expects, on your database
cd /var/www/nextcloud/custom_apps/chronos
composer install
composer run test:integration
```

The integration suite is the quickest honest answer to "did that upgrade work?". It checks, against the
database this instance really uses:

- every table and second-based column is there, and the millisecond columns are gone
- no session is still holding a millisecond timestamp, and no pause is orphaned
- two simultaneous check-ins cannot both open a session — the unique index that replaced a
  check-then-insert race — while any number of finished sessions can coexist
- a session runs, pauses, resumes and ends with its pause recorded, and an edit merges overlapping pauses
- a VTODO lands in the task index with its dates and dependencies, and re-indexing replaces rather than
  duplicates
- a card has one position and not two, a column comes back in rank order from the database's own collation,
  and rewriting a column's ranks keeps the order they described

It creates its own rows under `chronos-itest-*` user ids and a calendar id that cannot exist, and removes
them afterwards, so it does not touch real data.

Then open Chronos once as a normal user: that queues the one-off import of your existing task lists. The
hourly reconcile job keeps the index level with CalDAV afterwards; `occ background-job:list` shows both,
and `occ background-job:execute <id>` runs one immediately.

### Checking the parts that need a real calendar

The suite above never writes a VTODO: it works on a calendar id that cannot exist. For the rest —
workflows, statuses, history, the board, CalDAV writes — there is a second script, which creates a
workflow, attaches it to a list you name, walks one throwaway task through its statuses, drops it around
its board, reads the history back and then undoes all of it:

```bash
sudo -u www-data php /var/www/nextcloud/custom_apps/chronos/scripts/live-check.php admin personal
```

It prints a line per check and ends with a pass or fail count. It writes one task into the list given and
deletes it again, and puts the project's settings and the board's card positions back as it found them. The
last check is whether the `kanban` module is switched on for that user — a module ships off, so a fresh
install is expected to say no, and the line tells you the `occ` command that changes it.

### Two things worth knowing before you run `occ upgrade`

**It processes every app, not just this one.** If any other app is broken — a directory with a dot in its
name, or one whose files have gone missing — the upgrade stops at that app and **leaves the instance in
maintenance mode**, however well this app's own migration went. Check `ls` over your `apps` and
`custom_apps` directories for leftovers first. If it does happen: move the offending directory out of the
apps path, then `occ maintenance:mode --off`.

**It turns the log level up to debug and puts it back at the end** — but only if it finishes. After a failed
run, check `occ log:manage` and set it back (`occ log:manage --level 2`), or the log will grow quickly.

## Architecture

[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) is the design document: goals, the data model, how Chronos
stays compatible with other CalDAV clients, the module map, and the roadmap from time tracker to project
management. Worth reading before a larger change — in particular §8, which is why every timestamp in this
app is an integer number of UNIX seconds.

[`docs/REFERENCES.md`](docs/REFERENCES.md) is the outside view: how OpenProject, Jira, GitHub Projects,
Linear, Vikunja and Deck draw a board, a timeline and a roadmap, what the Kanban Guide and the W3C require
of them, where Chronos is ahead and where it is behind, and what is worth building next.

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
