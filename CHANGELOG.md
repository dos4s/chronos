# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.9] - 2026-10-19

Two more gaps from `docs/REFERENCES.md`, one on the board and one on the roadmap — and in both cases the
data was already there and only the view was silent.

### Added

- **A card says what it is waiting for.** Every other board marks blocked work with a flag somebody sets by
  hand and forgets to clear; Chronos already stores the dependencies in the VTODO, so `Board/CardBlocks`
  names the unfinished predecessors of every card from the same read. *Blocked by 1 task* on the card, with
  the task named in the tooltip. What counts as blocked follows the relationship, the same reading the
  scheduler uses: a finish-to-start or finish-to-finish successor waits for its predecessor to **finish**, a
  start-to-start or start-to-finish one only for it to **start**. A finished card is never blocked, and a
  predecessor in another list is left alone — it is not on this board and its status is not in this read.
- **A roadmap line shows how far along the project is**, filled from its own start. It counts tasks rather
  than days, because a line two thirds through its dates with nothing finished is the thing a roadmap exists
  to show.
- **A project with work in it and no dates says so** — *No dates yet*, beside today — instead of drawing an
  empty lane that reads as a bug.
- `scripts/live-check.php` proves the board's answer changes: two fresh tasks and one dependency, the
  successor must name the predecessor, and finishing the predecessor must stop it waiting.

## [0.5.8] - 2026-10-19

The first of the gaps `docs/REFERENCES.md` found: a board that cannot say how long anything has been sitting
in a column. The Kanban Guide makes four measures mandatory — WIP, throughput, cycle time and **work item
age** — and Chronos had none of them.

### Fixed

- **A status change on a project without a workflow is recorded at all.** `StatusHistoryListener` wrote a
  row only when the *workflow* status moved, and returned early when it had not — which is every change on
  a project that has no workflow, which is most of them. So nothing was recorded, and time in a state cannot
  be reconstructed afterwards: every day without this was a day that can never be measured. A row is now
  written when either status moves, the VTODO's own `STATUS` is recorded beside the workflow's
  (`from_standard`/`to_standard`, migration `Version6001`), and the standard one is recorded whether or not
  the workflow module is switched on — a column is a status with or without a workflow (AD7).

### Added

- **How long a card has been where it is**, on the card. `Board/CardAges` reads the arrival out of status
  history — the latest row that moved the card into this column, matched by status key where there is a
  workflow (two columns can share `IN-PROCESS`) and by standard status where there is not — and the board
  read carries it as `enteredColumnAt`. The badge appears after a week, darkens at a fortnight and again at
  three weeks, and says nothing below that: a badge on every card is a badge that means nothing.
- A card whose arrival nothing recorded has **no age at all** rather than a guessed one, which is every card
  whose last move predates this release.
- `scripts/live-check.php` grew a step for the case that was broken: with the workflow taken off, a plain
  status move must still write a row naming the standard status on both sides, and the board must read the
  card's arrival back out of it.

### Added

- `docs/REFERENCES.md` — what the other tools (OpenProject, Jira, GitHub Projects, Linear, Vikunja, Deck)
  and the one real standard (The Kanban Guide, the W3C's treegrid pattern, Nextcloud's own design
  guidelines) do with a board, a timeline and a roadmap, measured against what Chronos has, with a ranked
  list of what is worth closing.

## [0.5.7] - 2026-10-19

The first release written while looking at the app. A headless browser logged into the instance as a
throwaway account, and most of what looked wrong turned out to be one mistake made in eight places: the
server's own stylesheet is more specific than ours (§15.10).

### Fixed

- **A task's name is text again, not a green box.** Nextcloud paints every plain button as a filled pill,
  through a selector that outranks a component's own class — so the Tasks view was a column of ten form
  fields, the line at the foot of a board column was a button, a roadmap row's label was a 130px pill with
  its count printed on top of its name, and a milestone was a cream blob. Every bespoke button now names
  the element in its rule, which is what it takes to win.
- **A milestone, today's line and an overdue date can be seen.** `--color-warning`, `--color-error` and
  `--color-success` are *background tints* in Nextcloud 33, and Chronos was using them as ink: today was a
  hint of pink across the chart, a conflicted bar's outline and a broken dependency were nearly invisible,
  overdue text was pale pink on white, and the dashboard's own delete button was white on pale pink. Marks
  now take the readable `-text` colour; the glow around a running dot keeps the tint.
- **A view's title is no longer printed under the navigation toggle**, which Nextcloud floats over the
  top-left corner of the content area.
- **A board fills the window.** Columns were a fixed width that could not shrink, so the four standard
  statuses overflowed a desktop and cut the last column in half; they now share the room above phone width,
  and all of them are the same height, so each column's add line sits on the same row.
- **A chart is as tall as the rows it has.** The timeline asked for at least the height of its own window,
  which made the window its own floor: a ten-row plan was drawn into a slab of empty lanes half a screen
  tall. Same for the roadmap, which also stops printing a *No area* heading when there are no areas.
- **A message is a toast again.** `@nextcloud/dialogs` 7 positions its toast container from a stylesheet
  the app never imported, so every success and every failure Chronos reported — *Checked in*, *Task added*,
  every error — was rendered unstyled in the top-left corner of the window with half of it off the screen.
- The session editor got the same height rule as the other two charts, so one day of sessions no longer
  sits above an empty band.

## [0.5.6] - 2026-10-19

Three rough edges in what 0.5.5 added: two found by reading it back, one by running its live check against
a real instance.

### Fixed

- **A card's menu no longer opens the card as well.** A card is the drag handle — it answers space, enter
  and a double-click — and the menu sits inside it, so reaching the menu from the keyboard opened the task
  dialog behind it. A timeline row had the same shape.
- **A card added while a search is on is no longer hidden by it.** The card was created and then filtered
  out of sight, which looks exactly like a write that failed. If what you just made does not match what you
  were searching for, the search clears. Same on the timeline.
- `scripts/live-check.php` left the task it added to a column sitting in the real list: its CalDAV cleanup
  is a list of names and the new step was only added to the index cleanup. It deletes it now, and then asks
  the calendar whether every task it made is really gone, so the next forgotten line fails a check instead
  of leaving a task behind.

## [0.5.5] - 2026-10-19

The board, the timeline and the roadmap were views of work done somewhere else. Now each of them writes
the thing it is about.

### Added

- **The board makes cards.** A `+` above each column and a dashed *Add a card* at its foot open one
  composer, and the card is created **in that column's status** — so it appears where the box was, rather
  than in the first column waiting to be dragged across. The box stays open for the next card, because a
  column gets filled in one sitting. Every card has a menu: edit its details, give it a subtask, delete it.
- **The timeline makes tasks.** *Add a task* in the toolbar, and in the empty state that used to send you
  to another view. A task added here has no dates — most tasks in a real list have none — so it arrives as
  a row waiting for a bar, and double-clicking its lane gives it a day. Every row has a menu, including
  *take its dates off*, which is the one thing a chart has no gesture for: a bar can be dragged anywhere
  except out of existence.
- **The roadmap makes milestones**, which is what a roadmap is for. Double-click a project's lane to put
  one at that date; it opens so you can name it, because the gesture knows the date and not the name. On a
  subproject's line it belongs to that subproject. Click a diamond to rename it, move it or delete it.
- **Anything can be renamed from anywhere.** The task details dialog — which the board, the timeline and
  the roadmap all open — had no name field in it, so a card could only be renamed by going to the Tasks
  view and clicking its summary. It has one now, and a *Delete*.

### Changed

- **Deleting asks first.** It says what it means, too: the task leaves the Tasks app and your phone with
  it, and unlike a move there is no undo to offer. The Tasks view's own *Delete* used to happen the instant
  it was clicked.
- **A board fills a wide window.** Its columns were a fixed 300 px, so a three-column workflow used a third
  of a desktop screen and left the rest empty. They now grow into the room, up to 420 px each, and still
  never shrink below the width that keeps a phone readable.
- The three hint lines under the charts are one sentence again, and point at `?` for the rest — which now
  lists the new menus and gestures, and has a *Roadmap* section. The roadmap has a `?` button of its own.
- The empty states stop telling you to go to another view to add something, and offer the thing they are
  talking about instead.

## [0.5.4] - 2026-10-18

Four things the app needed and nobody had asked for, from reading it with fresh eyes.

### Added

- **One place to learn the keyboard.** Press `?` anywhere, or the `?` button in the board, the timeline or
  the session editor, for everything the app can be told to do — the view you are on listed first. The
  paragraphs of instructions under each chart are one line again.
- **It works on a narrow window.** The board, the timeline, the roadmap and the session editor had fixed
  widths that left a phone with a table and a sliver of chart; they now size themselves against the window,
  a board column fills most of a small screen with the next one peeking past it, the secondary line in each
  table gives way to the name below 700 px, and the page stops spending 28 px of a phone on side padding.
- **The running timer follows you.** While a session is running it sits at the top of the navigation — the
  time, the project, and buttons to pause or check out — and in the browser tab's title, which is where a
  timer left running overnight is actually noticed. Before this it was only on the Timer view.
- **Hide what is finished, and find what is not.** The task list and the timeline now show the unfinished
  work by default — two thirds of a real list is usually done — with a *Show completed* tick to see the
  rest, and all three task views have a search box. Searching keeps the parents of what it finds, faded, so
  a subtask still shows where it belongs; hiding finished work never hides the open work underneath it.
  The board has no *Show completed*, because a final column is exactly where finished cards live.

## [0.5.3] - 2026-10-17

### Changed

- **One place to choose a project.** The sidebar's project list is now the whole of it: click a project to
  work in it and look at it, ctrl- or ⌘-click to add another, and *All projects* to go back to everything.
  The task list, the board and the timeline follow it instead of each carrying a picker of their own, and
  they show the project's name with a switcher that appears only when more than one is in scope. The
  analytics, the session list, the session editor and the roadmap keep working over the whole selection.
  Before this, the sidebar filtered only the time views and did nothing at all for the other three, which
  each had a second copy of the project list.
- The sidebar lists **every project**, not only the ones with time logged against them — it had to, now
  that the board and the timeline follow it.
- The three *Show archived* checkboxes are one checkbox, under the sidebar's project list.
- The check-in panel keeps its own picker, because which project a session belongs to is a different
  question from which project is on screen — but it starts on the one you are working in.

## [0.5.2] - 2026-10-17

### Fixed

- **The timeline, the roadmap and the session editor now open where they should.** Each of them scrolls to
  today, or to the working day, as soon as it has something to draw — and each was doing that one tick too
  early, before the chart element existed, so the scroll went nowhere and they opened at midnight on the
  first day of their window.

### Changed

- `scripts/live-check.php` clears the index row of every task it made, including the subproject container
  that promoting it deletes on the way. Deleting a task through the app leaves its index row behind as
  deleted-but-recorded, which is correct for a real task and litter for a throwaway one: each run was
  leaving one row per list behind.

## [0.5.1] - 2026-10-17

Found by deploying 0.5.0 and running the live check against the real instance — within a minute of it
landing.

### Fixed

- **A dependency could not be removed once its task had been dragged.** A link was identified by its row
  id, and those rows are rewritten every time the task that declares them is written — so the id a link
  was created with went stale as soon as its bar moved, and clicking the line to delete it said the link
  no longer existed. A link is now named by the two tasks it joins, which is what it is (D42).

## [0.5.0] - 2026-10-17

M4: the timeline. Your tasks' dates become bars you can drag, the dependencies between them are real
iCalendar relationships every other client can read, and a roadmap shows every project at once. The
session editor is rebuilt on the same engine, and a subproject can finally become a list of its own.

### Added

- **The timeline.** A tree table and a chart: drag a bar to move it, its edges to change when a task
  starts or is due, and the dot on its right onto another task to make that task wait for this one. Three
  zooms — months, weeks, days — with the snapping each deserves, alt to drag freely, and the keyboard for
  all of it: focus a bar, press space, and the arrow keys move it while shift and the arrows stretch its
  end.
- **Dependencies that other clients understand.** A link is written into the task itself as RFC 9253
  `RELATED-TO`, with its lag or lead as `GAP`, on whichever of the two tasks declares it. Anything that
  reads your calendar can see it; nothing of Chronos's own is needed to keep it.
- **Warn or cascade, per project.** Move a task and either the dependencies it breaks turn red, or
  everything waiting on it follows in the same request — and in that case the bars show you where they
  will land *before* you let go.
- **A task with one date still gets a bar.** Most tasks in a real list have a due date and no start, so
  one date draws a one-day bar as a dashed suggestion; nothing is written until you move it, and then both
  ends are. A **double-click on a row with no bar** gives the task a day to live in.
- **Summary bars and milestones.** A subproject, or a parent with no dates of its own, spans everything
  beneath it and shows how much of it is done. A milestone is a diamond.
- **Today's line, and the days you do not work** shaded behind the bars. A task pushed by a dependency
  lands on a working day.
- **The roadmap.** Every project at once, grouped by area, each one a line from its earliest task to its
  latest with its milestones marked on it — hollow once met. Click a line to open its timeline.
- **Promote a subproject to a list.** It becomes a list of its own, with its tasks, their history, their
  dependencies and the time logged against them; the new list remembers which project it came out of.
- **The session editor is rebuilt.** A day is a row, a session is a block along it and its breaks are
  blocks inside it — all of them draggable. A break stays inside its session and clear of the others, and
  moving a session takes its breaks with it. The dialog and the break menu are still there for typing an
  exact time.

### Fixed

- **A timed date kept its time zone.** Editing a task in Chronos rewrote `DUE;TZID=Europe/Madrid` as UTC,
  and a *floating* time — one that means six o'clock wherever you are — came back pinned to an instant.
  The instant was never wrong, but what the task said about itself was. It now keeps the form it was
  written in (D39).
- **An all-day date read as the day before, west of Greenwich.** A task due the 30th showed as the 29th for
  anybody in a negative-offset zone, because an all-day date is midnight UTC and is not an instant. Dates
  now say which kind they are (D40).

### Changed

- `vue-cal` is no longer a dependency: the session editor draws itself, which also took 69 kB off that
  view's bundle.
- The live check drags bars on a real calendar, reads a dependency back out of the VTODO, and promotes a
  throwaway subproject into a list and away again.

## [0.4.2] - 2026-10-17

The board had been treating a subtask as though it were nobody's: a card like any other, with nothing on it
to say what it belonged to. Now it says so.

### Added

- **A subtask looks like a subtask.** One sitting under its parent is drawn as part of it, a step in from
  the edge; one you have dragged somewhere else carries its parent's name instead, so no card on the board
  is anonymous. A task that lives in a subproject says which sublist it is in — which is a different thing,
  and used to be counted as a subtask of it.
- **A card says how much of its own work is done.** *2 of 5 subtasks done*, counted over the whole branch
  under it, with *done* meaning a final status — so cancelled counts as finished, and your workflow's own
  final statuses count correctly.
- **A column nobody has arranged reads as a tree:** each card followed by its subtasks. Arranging a column
  by hand still wins — your arrangement is never silently undone — and a card that ends up away from its
  parent is the one that names it.
- **Roll subtasks up.** A tick beside *Show archived* folds every branch into the card at the top of it, so
  a project of fifty tasks and two hundred subtasks is a board you can read again. A rolled-up card shows
  how much of its branch is done and which columns the rest of it is sitting in.
- **A parent can take its subtasks with it.** Drop a parent into a column like *Done* while unfinished
  subtasks are elsewhere and the board asks whether they should come too; the same question is asked for
  any drop in the rolled-up view, where the subtasks are not on the screen to be dragged. It moves the
  whole branch in one request, leaves subtasks that are already finished alone, and undo puts all of it
  back.

### Changed

- The board asks the server which cards it should show (`GET /board?subtasks=rolled`) rather than hiding
  them in the browser, so the column counts and the WIP warnings always describe what is on the screen.
- A drop's answer now carries every card it changed, including the parents whose progress it changed, so a
  card's *2 of 5 done* is right without re-reading the board.
- `scripts/live-check.php` checks all of it against a real calendar: a throwaway subtask of its throwaway
  task, its parent's count, the roll-up, a cascading drop, and that a finished subtask is left alone.

## [0.4.1] - 2026-10-17

Found by deploying 0.4.0 and running the live check on a list with fifty cards in one column — after fixing
the check itself, which had been reporting a pass without testing anything.

### Fixed

- **A card dropped below another could land halfway down the column.** A drop names the cards above and
  below it, and when only one of them was named the other side was read as "the end of the column" rather
  than being taken from the column itself. Dropping a card just below the first of fifty therefore put it
  somewhere in the middle — at a rank another card could already have, which is two cards claiming one
  position. The server now fills the missing side in from the column: the card above means directly below
  it, and naming neither means the end of the column (D35).

### Changed

- `scripts/live-check.php` drops its card into the fullest column other than the one it is already in. Both
  reorder checks had been taking an escape hatch — "nothing else in the column to reorder against" — and
  passing without testing anything.

## [0.4.0] - 2026-10-17

M3: the kanban board. Columns are your workflow's statuses, so moving a card changes the real status —
in the Tasks app and on your phone too. A project without a workflow gets a board of the four standard
iCalendar statuses, which is a complete way to work.

### Added

- **The board.** Drag a card between columns or up and down one; drag several at once by picking them with
  ctrl or shift. The card goes where you dropped it straight away and the request follows; if the server
  refuses the move, the board goes back to exactly what it was and says why.
- **Every drag works from the keyboard.** Focus a card and press space: the arrow keys move it between
  positions and columns, space drops it, escape puts it back. Each step is announced for a screen reader.
- **Undo.** The *Undo move* button beside the project picker — and `Ctrl+Z` — sends the card back to the
  column *and the position* it came from.
- **Transition rules have a face at last.** A column's settings say how many cards belong in it and which
  columns its cards may move to, and whether a move needs a note. While a card is being dragged, the
  columns it may not go to are dimmed — and the server refuses those moves as well.
- **WIP limits.** A column over its limit says so, in its header and above its cards. It is a warning, not
  a barrier: a limit is there to be seen, not to stop you finishing something.
- **The board behind the kanban board.** `GET /board` reads a project as columns of cards and
  `POST /board/move` is one drop: the cards dragged, the column they landed in and the two cards they
  landed between. A drop is a status change like any other — CalDAV is written, a move the workflow forbids
  is refused, and the history records who moved it — while reordering within a column writes nothing to
  CalDAV at all, because nothing about the task has changed.
- **Card order that survives everybody else.** `chronos_board_ranks` keeps each card's place as a
  lexicographic rank, so a drop writes one row rather than renumbering a column, and a task moved from a
  phone keeps its place. Ranks are never written into the VTODO: arranging a board is nobody else's
  business. A column whose ranks have grown long is rewritten, there and then if a drop needs it and by a
  daily job otherwise.
- **A board for a project with no workflow**, made of the four standard iCalendar statuses — so the view
  works before anybody has built a workflow, and on an instance with the workflow module switched off.
- The `kanban` module can be switched on, by an administrator for the instance and by each user for
  themselves. It requires nothing else.
- `scripts/live-check.php` now drops cards too: that a drop writes the status and the place and changes the
  etag, that a reorder changes the place and not the etag, that an undo puts the card back, and that a drop
  the transition rules forbid is refused. It puts the card positions it found back afterwards.

### Known limits

- A column draws every card it holds; several thousand in one column will be slow (D33).
- A card somebody else has edited since the page read it makes the drop fail and the board reload, rather
  than merging the two changes field by field (D34).

## [0.3.2] - 2026-10-16

Two more found on the live instance, this time by a script that writes a real task into a real list —
which is now in the repository as `scripts/live-check.php`, since it has earned its place.

### Fixed

- **Attaching a workflow skipped every task that had no status.** Most clients write no `STATUS` at all
  until a task is started or finished — 40 of 229 tasks on the instance this was found on — and those were
  left with no workflow status until something edited them. They now get the initial status, which is the
  same answer the index gives when it reads such a task (D31).
- **Every change Chronos made was recorded as having come from another app.** The listener is told which
  writes are Chronos's own, but it was told *after* the write, and Nextcloud dispatches the
  calendar-object event from inside it — so the register was always empty by the time it was read, and a
  delete was never announced at all. Status history credited "another app" with every status change you
  made here. It is now announced before the write (D32).

### Added

- `scripts/live-check.php`: the verification that needs a real calendar — a workflow created and attached,
  a task walked through its statuses, its history read back, then all of it undone. The README has the
  command.

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
