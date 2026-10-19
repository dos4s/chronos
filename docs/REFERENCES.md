# References: how the other tools draw a board, a timeline and a roadmap

Chronos's three planning views were built from first principles and then, in 0.5.7, looked at for the first
time. This file is the second half of that exercise: what the established tools and the one real standard
actually do, what Chronos already does, and where the gap is worth closing. It exists so that the next
change to these views argues with something rather than with taste.

**How to read the sources.** Most writing about kanban and Gantt is vendor marketing. Three of the sources
below are not: [The Kanban Guide](https://kanbanguides.org/english/) is the practice's own definition, the
[W3C's treegrid pattern](https://www.w3.org/WAI/ARIA/apg/patterns/treegrid/) is how a hierarchical grid is
supposed to behave for a keyboard and a screen reader, and
[Nextcloud's design guidelines](https://docs.nextcloud.com/server/latest/developer_manual/design/) are the
house rules Chronos lives under. Everything else — OpenProject, Jira, GitHub Projects, Linear, Vikunja,
Deck, DHTMLX — is cited as *what a shipped product does*, which is evidence about expectations, not about
truth.

---

## 1. The board

### What the practice says

The Kanban Guide defines a board as the visualisation of a **Definition of Workflow**, and says the minimum
it must make visible is: the work items, the points where they start and finish, the states between them,
**how work in progress is controlled**, the **explicit policies** for moving between states, and a **service
level expectation** — how long an item usually takes, from the team's own history. It then makes four flow
metrics mandatory, under whatever names a team prefers:

| Metric | What it is |
|---|---|
| **WIP** | items started and not yet finished |
| **Throughput** | items finished per unit of time |
| **Work item age** | elapsed time since an unfinished item started |
| **Cycle time** | elapsed time from start to finish |

"Actively manage items" is one of its three practices, and it spells out what that means: control how many
things are in progress, **stop items ageing unnecessarily**, and unblock what is stuck.

Card ageing is the common way to show age on the board itself rather than in a report.
[Kanban Zone](https://kanbanzone.com/2020/card-aging-fun-and-helpful-visual-signal-for-kanban/) colours a
card progressively at three configurable thresholds (7, 14 and 21 days by default) and counts only a move
to a *new column* as progress; [Kanbanchi](https://kanbanchi.com/card-aging) measures inactivity instead and
configures it per list; [ProKanban](https://prokanban.org/blog/visualising-work-item-aging-in-jira) does it
in Jira with colour rules at 3, 7 and 11 days. The thresholds are vendor defaults, not standards — but all
three agree the signal belongs on the card and should be configurable.

The [cumulative flow diagram](https://businessmap.io/kanban-resources/kanban-analytics/cumulative-flow-diagram)
is the standard reading of a board over time: one band per state, stacked; parallel bands mean stable flow,
a **widening band is a bottleneck**, a narrowing one is spare capacity, and a band that goes down means the
data is wrong.

### What a card carries elsewhere

[Deck](https://nextcloud.com/blog/nextcloud-deck-1-0/) — the board a Nextcloud user already knows — has
labels, due dates, assignees (several per card), attachments, comments, an activity stream, and filters by
tag, assignee and due date. [Vikunja](https://vikunja.io/features/) has labels, priorities, reminders,
relations across projects, subtasks and saved filters. Guidance collected from board vendors converges on a
card showing *title, assignee, a date, priority and tags* and warns against more than that.
[SaaS board patterns](https://www.saasui.design/blog/saas-kanban-board-ux-patterns) add **swimlanes** as the
board's second dimension — grouping rows by assignee, priority or project with per-lane headers and counts,
collapsible and hiding empty lanes, because density is the risk.

### Where Chronos stands

Chronos's board is strong on the two things most boards get wrong: a column **is** a status (AD7), so the
board cannot disagree with the Tasks app or a phone, and a subtask is a card that says where it sits in the
tree (§15.9). WIP limits exist and warn. Transition rules are visible *while dragging* (§15.3), which is
more than Deck or Trello can do at all.

What is missing, measured against the above:

- **None of the four flow metrics.** The board shows a count per column and nothing about time. This is the
  largest gap, and it is half-prepared rather than free: `oc_chronos_status_history` records every status
  change with who made it and when (M2) — but only *workflow* transitions, and only while the `workflow`
  module is available. `StatusHistoryListener` returns early when the workflow status did not move, which is
  exactly the case on a project with no workflow, and on this instance **no project has one**. So the
  precondition for every metric below is that a plain `STATUS` move — `NEEDS-ACTION` → `IN-PROCESS` →
  `COMPLETED` — is recorded too. That is one early return and two columns (or an agreed meaning for
  `from_key`/`to_key` when there is no workflow), and it must land before the measuring starts, because time
  in a state cannot be reconstructed afterwards — the same argument that put history in M2 in the first
  place.
- **No card ageing.** Nothing on a card says it has sat in *Doing* for three weeks.
- **No explicit policies.** A workflow's statuses have names and colours but no description, so the rule for
  entering a column lives in somebody's head. The Kanban Guide treats that as part of the board.
- **No blocked marker** — although Chronos is the one tool here that *knows*: a task whose predecessor is
  unfinished is blocked, and the timeline draws exactly that relationship. The board ignores it.
- **No swimlanes**, no collapsed columns, no hiding an empty one.
- **No labels at all.** `CATEGORIES` is in every VTODO Chronos reads and writes and is never parsed, so the
  tags a user sets in the Tasks app are invisible here.

---

## 2. The timeline

### What the shipped tools do

[OpenProject's Gantt chart](https://www.openproject.org/docs/user-guide/gantt-chart/) is the closest
comparable — self-hosted, work-package based:

- zoom in and out, plus an **auto zoom that fits the chart to the window**, and a zen mode that gives it the
  whole screen;
- today is a **red dotted line**; at day zoom **non-working days get a darker background**, and a bar's
  visual width grows over them while its duration does not;
- milestones are diamonds, phases are bars, and a folded project shows only its milestones;
- predecessor/follower relations are blue lines and a follower cannot start before its predecessor finishes;
  other relation types are deliberately **not drawn**;
- a parent gets **clamps** at its children's extent — black when the children fall inside its dates, red
  when they fall outside;
- rows are reordered by dragging a handle, with a line showing where the drop lands;
- relations are created from a right-click menu, not only by dragging.

[Jira's timeline](https://support.atlassian.com/jira-software-cloud/docs/manage-epics-on-the-roadmap/) nests
children under parents, **infers a parent's dates from its children**, draws dependency lines, puts sprint
and release markers on the chart, zooms weeks/months/quarters, and has a legend, a full-screen mode and a
side panel for editing without leaving the view. Cross-project planning is a separate, paid product.

On dependencies, the library market splits exactly where the standard does:
[frappe-gantt is finish-to-start only](https://dhtmlx.com/blog/?p=15811), while DHTMLX adds the other three,
auto-scheduling and a critical path. The four types and their lead/lag come from PMBOK:
[finish-to-start, start-to-start, finish-to-finish and start-to-finish](https://en.wikipedia.org/wiki/Dependency_(project_management)),
with **lag** delaying a successor and **lead** pulling it earlier.

### Where Chronos stands

Better than the reference on the thing that is hardest to retrofit: Chronos already implements **all four
temporal relationship types with RFC 9253's `GAP`** (lead and lag), schedules with them, and draws each one
from the right edge of the right bar (`utils/schedule.ts`, `GanttLinks.vue`). It has warn-or-cascade on a
move, which is auto-scheduling with a consent step, and it stores the whole thing in the VTODO so another
client keeps it.

The gaps are in the view, not the model:

- **No fit-to-window zoom.** Months, weeks and days are fixed scales, so a plan wider than the window is cut
  at the right edge with nothing to say so — the first thing the browser showed in 0.5.7. OpenProject's auto
  zoom is the answer and is a pure function of the data's span and the chart's width.
- **No way to choose a dependency's type or its lag.** Dragging the dot always makes a finish-to-start with
  a gap of zero; the other three types can only arrive from another client. The backend endpoint already
  takes `reltype` and `gapSeconds` and the renderer already draws them, so this is a dialog, not a feature.
- **No non-working days.** A week's stripes are drawn at every zoom; a weekend is never distinguished at day
  zoom, so a bar that spans a Saturday reads as two working days.
- **No parent clamps.** A subproject's row shows its own bar, not the extent of its children, so a child
  outside its parent's dates is a fact on the screen that nothing points at.
- **No legend.** Diamond, dashed outline, red outline and a ghost bar all mean something; the hint line
  under the chart explains gestures, not vocabulary.
- **No critical path.** It is computable from the graph Chronos already has, and it is what turns a chart of
  bars into an answer to "what actually moves the end date".
- **No baseline** — a saved snapshot to compare against. OpenProject and Jira add-ons both have one. For
  Chronos it would need a new table and a reason; see §5.

---

## 3. The roadmap

### What the shipped tools do

[GitHub Projects' roadmap layout](https://docs.github.com/en/issues/planning-and-tracking-with-projects/customizing-views-in-your-project/customizing-the-roadmap-layout)
is the most instructive, because it is a roadmap built out of fields rather than out of a project model:

- bars come from **a start field and a target field, or from an iteration field** — the user chooses which;
- dragging an item changes its dates or moves it to another iteration;
- zoom is **month, quarter and year**;
- **vertical markers** can be drawn for iterations, milestones or item dates;
- a **slice panel** splits the view by any field's values and filters to the one you click;
- items can be grouped, sorted by two fields, and **number fields summed into each group's header**.

[Linear](https://linear.app/docs/projects) is the other end: a project has a lead, a status, a progress
graph, milestones you can reorder and date, and — the idea worth stealing — **a date precision chosen by how
certain you are**: a project's start or target can be a year, a half, a quarter, a month or a day. Several
projects group into an initiative, and the timeline is a display option over them.

Against both of those,
[ProdPad's now/next/later](https://www.prodpad.com/glossary/now-next-later-roadmap/) is an argument that the
dated roadmap is the wrong artefact: putting a feature in a dated column turns every date into a
commitment, and the format instead sorts work into three horizons by confidence — *Now* is being worked on,
*Next* is being validated, *Later* is a bet. The honest reading is that both are right about different
rooms: a delivery plan with external deadlines needs dates, and a product direction does not.

### Where Chronos stands

The roadmap is the view that was invented here with the least to copy from, and it shows. What it does well
is that **nothing on it is typed in**: a project's span is derived from its tasks, so it cannot drift from
reality the way a hand-maintained roadmap does, and the milestones on it are ordinary VTODOs.

The gaps:

- **No grouping or slicing but one.** Areas are the only grouping, and they come from Chronos's own table.
  GitHub's slice panel is the generalisation: by area, by whether a project is archived, by who it belongs
  to for shared lists.
- **No markers.** A release, a quarter boundary, a deadline that is not a task — none can be drawn.
- **No progress.** A lane says "9 of 12 open" in text where every tool here draws it, and Chronos computes
  percentages for tasks already.
- **No notion of confidence.** A span is exact to the day because it is derived from exact dates; Linear's
  precision levels are how a roadmap says "some time in Q3" without lying.
- **No undated view.** A project with no dated tasks has no lane at all, so the one thing ProdPad's format
  is for — work you have not scheduled — is invisible. A *Later* column, or an "unscheduled" tray beside the
  chart, is the smallest version of that.
- **A subproject's lane is as loud as its project's.** Depth is one indent and nothing else.

---

## 4. The house rules we are already inside

[Nextcloud's design guidelines](https://docs.nextcloud.com/server/latest/developer_manual/design/) are not a
reference to consider; they are the rules this app ships under, and 0.5.7 proved the cost of not reading
them (§15.10). What they say, and where Chronos still deviates:

| Rule | Chronos |
|---|---|
| `--default-font-size` 15px, `--font-size-small` 13px, 20px headings, 24px titles | **126 hardcoded sizes** in twelve different values (12px is the most common, then 13, 11, 14, 10) and not one use of the variables |
| `--default-grid-baseline` 4px; spacing in whole multiples, never raw numbers | raw px throughout |
| `--border-radius-small` 4 (tags, cards), `--border-radius-element` 8 (buttons), `--border-radius-container` 12 (dialogs), `--border-radius-pill` | only the older `--border-radius` and `--border-radius-large` |
| `--default-clickable-area` 34px, `--clickable-area-small` 24px | never referenced; sizes are hand-picked |
| **One primary button per screen**, never for a negative action | the timeline and the roadmap mark the *active zoom level* with `variant="primary"`, so a chart has a primary button for every state it is not in. A radio group (`NcCheckboxRadioSwitch type="button"`) is the house component for exactly this |
| A list item is leading icon + name + action menu, single- or two-line | the task list matches; the board card is a bespoke thing, legitimately |
| Empty content: icon, heading, short description, optional button; never while loading | followed (`NcEmptyContent` everywhere, a loading icon while loading) |
| Action menus: common actions inline, destructive last and separated | followed |
| Prefer the **native date picker**; custom only when a persistent calendar earns it | worth re-checking in the detail dialog |

And the one standard nobody else here follows either: the W3C's
[treegrid pattern](https://www.w3.org/WAI/ARIA/apg/patterns/treegrid/) is what Chronos's task list and the
timeline's left-hand table are — rows with children, expandable, with cells. The pattern asks for
`role="treegrid"` with `row`/`gridcell`, `aria-expanded` on parents, arrow keys to move by row and cell,
left/right to collapse and expand, Home/End to jump, and `aria-selected` for a multi-select grid. Chronos's
list is `<ul>` with buttons; the board has a real keyboard drag (§15.7), but the tree does not have the
keyboard a tree is supposed to have.

---

## 5. What is worth doing, in order

Ranked by what each buys divided by what it costs, given that the data for the first three is already in the
database.

1. **Record a plain status move, then measure.** The four flow metrics — age on the card, cycle time and
   throughput per column, a cumulative flow diagram in Analytics — are the single biggest difference between
   "a board" and "a board you can manage work with", and nothing else in Nextcloud has them. The step before
   them is small and urgent: history today only covers workflow transitions, so a project without a workflow
   records nothing, and every day that passes is a day that cannot be measured later. Fix the listener
   first, ship the charts after.
2. **Fit-to-window zoom on both charts**, with the scale derived from the data's span. Removes the one
   defect a first look found that 0.5.7 did not fix.
3. **A dependency's type and lag, editable.** Backend, scheduler and renderer all support four types and a
   gap; only the UI is finish-to-start. A small dialog on a link.
4. **Blocked, on the card.** The dependency graph is already read, scheduled with and drawn by the
   timeline; the board's own read does not carry it. A card whose predecessor is unfinished should say so.
5. **Labels from `CATEGORIES`.** Parse what every other CalDAV client already writes, show them on the card
   and in the list, filter by them. Interoperability work, which is Chronos's whole argument (AD1).
6. **A design-token pass.** Replace 126 hardcoded sizes with the four variables, the radii with the current
   names, and the zoom groups with a radio group. Mechanical, low risk, and the difference between "a custom
   app" and "part of Nextcloud".
7. **Non-working days and a legend on the timeline.** Both cheap; the legend can live in the `?` dialog that
   already exists.
8. **Roadmap: progress bars, markers, and an unscheduled tray.** The tray is the Now/Next/Later idea in the
   smallest form that does not require a second data model.
9. **Critical path.** Real value, real work: it needs the graph walked per render and a story for what it
   means when half the tasks have no dates.
10. **Swimlanes, parent clamps, baselines.** Each is a feature with a table or a layout behind it. Worth
    writing down, not worth doing before M5 ships.

**Deliberately not adopted.** A *dated* product roadmap of the ProdPad kind (Chronos's roadmap is derived
from real task dates by design, and a parallel hand-maintained one would be the thing that drifts); Jira's
cross-project planning as a separate artefact (the roadmap already reads every list); and relation types
beyond the four temporal ones on the chart, which OpenProject also refuses to draw.

---

## Sources

- The Kanban Guide — <https://kanbanguides.org/english/>
- W3C WAI-ARIA Authoring Practices, treegrid pattern — <https://www.w3.org/WAI/ARIA/apg/patterns/treegrid/>
- Nextcloud design guidelines (foundations, components) — <https://docs.nextcloud.com/server/latest/developer_manual/design/>
- OpenProject, Gantt charts — <https://www.openproject.org/docs/user-guide/gantt-chart/>
- Atlassian, manage work on the timeline — <https://support.atlassian.com/jira-software-cloud/docs/manage-epics-on-the-roadmap/>
- GitHub Docs, customizing the roadmap layout — <https://docs.github.com/en/issues/planning-and-tracking-with-projects/customizing-views-in-your-project/customizing-the-roadmap-layout>
- Linear, projects — <https://linear.app/docs/projects>
- Vikunja, features — <https://vikunja.io/features/>
- Nextcloud Deck 1.0 announcement — <https://nextcloud.com/blog/nextcloud-deck-1-0/>
- DHTMLX, beyond frappe-gantt limitations — <https://dhtmlx.com/blog/?p=15811>
- Dependency (project management), PMBOK's four types with lead and lag — <https://en.wikipedia.org/wiki/Dependency_(project_management)>
- Businessmap, cumulative flow diagram — <https://businessmap.io/kanban-resources/kanban-analytics/cumulative-flow-diagram>
- Kanban Zone, card aging — <https://kanbanzone.com/2020/card-aging-fun-and-helpful-visual-signal-for-kanban/>
- Kanbanchi, card aging — <https://kanbanchi.com/card-aging>
- ProKanban, visualising work item aging in Jira — <https://prokanban.org/blog/visualising-work-item-aging-in-jira>
- SaaS kanban board UX patterns — <https://www.saasui.design/blog/saas-kanban-board-ux-patterns>
- ProdPad, now/next/later roadmap — <https://www.prodpad.com/glossary/now-next-later-roadmap/>
