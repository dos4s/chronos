<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning\Service;

use DateTimeZone;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskLink;
use OCA\Chronos\Core\Index\TaskLinkMapper;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Core\Task\TaskWrite;
use OCA\Chronos\Planning\Bar;
use OCA\Chronos\Planning\Dependency;
use OCA\Chronos\Planning\MoveOutcome;
use OCA\Chronos\Planning\PlanDates;
use OCA\Chronos\Planning\PlanRow;
use OCA\Chronos\Planning\PlanSettings;
use OCA\Chronos\Planning\PlanView;
use OCA\Chronos\Planning\Schedule;
use OCA\Chronos\Planning\WorkingDays;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\IDateTimeZone;

/**
 * The timeline: reading one, dragging a bar on it, and linking two (§12.5).
 *
 * The shape of this follows the board's (§15.6), because the problem is the
 * same one: the browser has already drawn the change when the request arrives,
 * so what matters is that the server works out the same answer and sends back
 * enough to agree again. Everything about *what* the answer is lives in
 * `Schedule` and `PlanDates`, which know nothing about CalDAV; this class is
 * the part that reads, validates and writes.
 *
 * Three things it refuses to do, each for a reason worth keeping:
 *
 * - **It does not move a summary row.** A summary bar is the span of what is
 *   under it, not a date anybody set, so there is nothing to write.
 * - **It does not make a loop.** A circular dependency cannot be satisfied by
 *   any dates, and the cascade would have to stop somewhere arbitrary.
 * - **It does not half-move a chain.** A cascade longer than one request may
 *   carry is refused whole, like a bulk move on the board.
 */
class PlanService {

	/** More than this in one cascade is a different operation (§15.6). */
	public const MAX_TASKS_PER_MOVE = 100;

	/** A year of lag or lead; more is somebody typing rather than planning. */
	private const MAX_GAP = 365 * 86400;

	public function __construct(
		private CalDavGateway $calendars,
		private TaskMapper $tasks,
		private TaskLinkMapper $links,
		private TaskService $taskService,
		private ProjectService $projects,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	/**
	 * One project's timeline.
	 *
	 * @throws NotFoundException
	 */
	public function plan(string $userId, string $projectRef): PlanView {
		$calendar = $this->requireCalendar($userId, $projectRef);
		$settings = $this->settingsFor($userId, $projectRef);
		$dates = $this->dates();

		$rows = $this->rows($this->tasks->findForCalendars([$calendar->id]), $dates);
		$links = $this->dependencies($calendar->id);
		$schedule = $this->schedule($rows, $links, $dates, $settings);

		return new PlanView($calendar, $rows, $links, $schedule->conflicts(), $settings);
	}

	/**
	 * A bar dragged or resized: the dates it was dropped on, and whatever the
	 * project's mode says should happen to the dependents (§15.4).
	 *
	 * Both ends are sent, even for a resize and even for a bar the plan had
	 * only guessed at, because a bar is two dates and this is where the guess
	 * becomes a date somebody set.
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	public function move(
		string $userId,
		string $projectRef,
		string $uid,
		int $startAt,
		bool $startIsDate,
		int $dueAt,
		bool $dueIsDate,
		?string $etag = null,
		?string $mode = null,
	): MoveOutcome {
		$calendar = $this->requireCalendar($userId, $projectRef);
		if (!$calendar->isWritable) {
			throw new ForbiddenException('project_read_only');
		}

		$settings = $this->settingsFor($userId, $projectRef);
		$cascade = $this->modeOf($mode, $settings) === PlanSettings::MODE_CASCADE;
		$dates = $this->dates();

		$tasks = $this->tasks->findForCalendars([$calendar->id]);
		$rows = $this->rows($tasks, $dates);
		$row = $this->requireRow($rows, $uid);
		if ($row->isSummary) {
			throw new ValidationFailedException('summary_not_movable');
		}

		$end = $dates->exclusiveEnd($dueAt, $dueIsDate);
		if ($end <= $startAt) {
			throw new ValidationFailedException('invalid_dates');
		}

		$links = $this->dependencies($calendar->id);
		$result = $this->schedule($rows, $links, $dates, $settings)->reschedule(
			new Bar($uid, $startAt, $end, $startIsDate, $dueIsDate),
			$cascade,
			self::MAX_TASKS_PER_MOVE,
		);

		if ($result->overflowed) {
			throw new ValidationFailedException('too_many_tasks');
		}

		$byUid = $this->byUid($tasks);
		$written = [];
		foreach ($result->bars as $movedUid => $bar) {
			$task = $byUid[$movedUid] ?? null;
			if ($task === null) {
				continue;
			}

			$this->taskService->update(
				$userId,
				$movedUid,
				TaskWrite::datesOf(
					$task,
					$bar->startAt,
					$bar->startIsDate,
					$dates->inclusiveDue($bar->endAt, $bar->dueIsDate),
					$bar->dueIsDate,
				),
				$movedUid === $uid ? $etag : null,
			);
			$written[] = $movedUid;
		}

		return new MoveOutcome(
			$this->rowsAfter($calendar->id, $dates, $written),
			$result->conflicts,
			$cascade && count($written) > 1,
		);
	}

	/**
	 * A new dependency, written on the task that declares it (§7).
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	public function link(
		string $userId,
		string $projectRef,
		string $predecessorUid,
		string $successorUid,
		string $reltype,
		int $gapSeconds = 0,
	): PlanView {
		$calendar = $this->requireCalendar($userId, $projectRef);
		if (!$calendar->isWritable) {
			throw new ForbiddenException('project_read_only');
		}

		$reltype = strtoupper(trim($reltype));
		$temporal = in_array($reltype, ParsedLink::TEMPORAL_RELTYPES, true);
		if (!$temporal && $reltype !== ParsedLink::RELTYPE_DEPENDS_ON) {
			throw new ValidationFailedException('unknown_reltype');
		}
		if ($predecessorUid === $successorUid) {
			throw new ValidationFailedException('link_to_itself');
		}
		if (abs($gapSeconds) > self::MAX_GAP) {
			throw new ValidationFailedException('gap_too_large');
		}

		$byUid = $this->byUid($this->tasks->findForCalendars([$calendar->id]));
		foreach ([$predecessorUid, $successorUid] as $needed) {
			if (!isset($byUid[$needed])) {
				throw new NotFoundException('task_not_found');
			}
		}

		$existing = $this->dependencies($calendar->id);
		foreach ($existing as $link) {
			if ($link->predecessorUid === $predecessorUid && $link->successorUid === $successorUid) {
				throw new ValidationFailedException('link_exists');
			}
		}

		// A loop cannot be satisfied by any dates, so it is refused here
		// rather than reported for ever afterwards.
		if ($temporal && $this->reaches($existing, $successorUid, $predecessorUid)) {
			throw new ValidationFailedException('link_cycle');
		}

		// The property goes on the task that declares the relationship: the
		// predecessor for a temporal link, the dependent task for DEPENDS-ON
		// (§7). That is also the task whose write replaces its own links.
		$sourceUid = $temporal ? $predecessorUid : $successorUid;
		$links = $this->linksOfSource($calendar->id, $sourceUid);
		$links[] = new ParsedLink($predecessorUid, $successorUid, $reltype, $gapSeconds);

		$this->writeLinks($userId, $byUid[$sourceUid], $links);

		return $this->plan($userId, $projectRef);
	}

	/**
	 * Takes a dependency away again, named by the two tasks it joins.
	 *
	 * **Not by its row id**, although it has one. A link row is rewritten
	 * whenever the task that declares it is written — the index replaces that
	 * task's rows rather than editing them — so an id read when the link was
	 * made is stale the moment the bar is dragged. The two UIDs are what the
	 * link *is*, and there is only ever one between a given pair (D42).
	 *
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	public function unlink(
		string $userId,
		string $projectRef,
		string $predecessorUid,
		string $successorUid,
	): PlanView {
		$calendar = $this->requireCalendar($userId, $projectRef);
		if (!$calendar->isWritable) {
			throw new ForbiddenException('project_read_only');
		}

		$row = null;
		foreach ($this->links->findForCalendar($calendar->id) as $candidate) {
			if ($candidate->getPredecessorUid() === $predecessorUid
				&& $candidate->getSuccessorUid() === $successorUid) {
				$row = $candidate;
				break;
			}
		}

		if ($row === null) {
			throw new NotFoundException('link_not_found');
		}

		$byUid = $this->byUid($this->tasks->findForCalendars([$calendar->id]));
		$source = $byUid[$row->getSourceUid()] ?? null;
		if ($source === null) {
			throw new NotFoundException('task_not_found');
		}

		$kept = [];
		foreach ($this->linksOfSource($calendar->id, $row->getSourceUid()) as $link) {
			if ($link->predecessorUid === $row->getPredecessorUid()
				&& $link->successorUid === $row->getSuccessorUid()
				&& $link->reltype === strtoupper($row->getReltype())) {
				continue;
			}
			$kept[] = $link;
		}

		$this->writeLinks($userId, $source, $kept);

		return $this->plan($userId, $projectRef);
	}

	/**
	 * What happens to the dependents when a task moves, and which days the
	 * project works (§10.2).
	 *
	 * @param list<int> $workingDays
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function configure(
		string $userId,
		string $projectRef,
		string $mode,
		array $workingDays,
	): PlanSettings {
		$this->requireCalendar($userId, $projectRef);

		if (!in_array($mode, PlanSettings::MODES, true)) {
			throw new ValidationFailedException('unknown_mode');
		}

		$settings = new PlanSettings($mode, (new WorkingDays($workingDays, $this->zone()))->toArray());
		$this->projects->writeSettings(
			$userId,
			$projectRef,
			$settings->toJson($this->projects->settings($userId, $projectRef)),
		);

		return $settings;
	}

	/**
	 * The rows of a plan: the task tree, flattened, each with its depth and
	 * the bar it draws (§12.5).
	 *
	 * Unlike the board, a subproject **is** a row here: a timeline's job is to
	 * show how the work sits together, and a container with a span is exactly
	 * what a summary bar is for (§6).
	 *
	 * @param list<Task> $tasks
	 * @return list<PlanRow>
	 */
	private function rows(array $tasks, PlanDates $dates): array {
		$byUid = $this->byUid($tasks);

		$children = [];
		foreach ($tasks as $task) {
			$parentUid = $task->getParentUid();
			if ($parentUid !== null && $parentUid !== $task->getUid() && isset($byUid[$parentUid])) {
				$children[$parentUid][] = $task->getUid();
			}
		}

		$rows = [];
		$seen = [];

		/*
		 * Each root with its branch under it, ordered by when the branch
		 * starts. The index reads tasks by due date, which puts every undated
		 * task first — so a plan opened at the top showed three rows of "No
		 * dates" before anything that is actually scheduled (§15.13). A branch
		 * with no dates at all sorts last, in the order it came.
		 */
		$branches = [];
		foreach ($tasks as $task) {
			$parentUid = $task->getParentUid();
			// A task whose parent is in this list is reached through it; one
			// whose parent is somewhere else is a root here, the same choice
			// the task tree makes rather than hiding it.
			if ($parentUid !== null && isset($byUid[$parentUid]) && $parentUid !== $task->getUid()) {
				continue;
			}
			$branch = $this->branch($task, 0, $dates, $children, $byUid, $seen);
			$branches[] = ['rows' => $branch['rows'], 'start' => $this->branchStart($branch['rows'])];
		}

		usort($branches, static function (array $a, array $b): int {
			if ($a['start'] === $b['start']) {
				return 0;
			}
			if ($a['start'] === null) {
				return 1;
			}
			if ($b['start'] === null) {
				return -1;
			}

			return $a['start'] <=> $b['start'];
		});

		foreach ($branches as $branch) {
			$rows = array_merge($rows, $branch['rows']);
		}

		// A loop in the tree reaches nothing from a root; those tasks are
		// still rows, at the top level, rather than missing from the plan.
		foreach ($tasks as $task) {
			if (!isset($seen[$task->getUid()])) {
				$rows = array_merge($rows, $this->branch($task, 0, $dates, $children, $byUid, $seen)['rows']);
			}
		}

		return $rows;
	}

	/**
	 * When a branch starts: the earliest bar anywhere in it, or null when
	 * nothing in it has a date. A milestone counts, since a date is a date.
	 *
	 * @param list<PlanRow> $rows
	 */
	private function branchStart(array $rows): ?int {
		$start = null;
		foreach ($rows as $row) {
			if ($row->bar === null) {
				continue;
			}
			if ($start === null || $row->bar->startAt < $start) {
				$start = $row->bar->startAt;
			}
		}

		return $start;
	}

	/**
	 * One task's row followed by its children's, and what the branch spans —
	 * which is what makes an ancestor's summary bar cover the whole of it
	 * rather than just its direct children.
	 *
	 * The children are walked before the row is built, because a parent is
	 * drawn above its children while its summary bar is not known until they
	 * have been.
	 *
	 * @param array<string, list<string>> $children
	 * @param array<string, Task> $byUid
	 * @param array<string, bool> $seen
	 * @return array{rows: list<PlanRow>, bars: list<Bar>, done: int, total: int}
	 */
	private function branch(
		Task $task,
		int $depth,
		PlanDates $dates,
		array $children,
		array $byUid,
		array &$seen,
	): array {
		$uid = $task->getUid();
		$seen[$uid] = true;

		$below = [];
		$bars = [];
		$done = 0;
		$total = 0;

		foreach ($children[$uid] ?? [] as $childUid) {
			if (isset($seen[$childUid])) {
				continue;
			}
			$child = $byUid[$childUid] ?? null;
			if ($child === null) {
				continue;
			}

			$branch = $this->branch($child, $depth + 1, $dates, $children, $byUid, $seen);
			$below = array_merge($below, $branch['rows']);
			$bars = array_merge($bars, $branch['bars']);
			$done += $branch['done'];
			$total += $branch['total'];
		}

		$own = $dates->bar(
			$uid,
			$task->getStartAt(),
			$task->getStartIsDate(),
			$task->getDueAt(),
			$task->getDueIsDate(),
			$task->getType(),
		);

		$isSummary = $own === null && $bars !== [];
		$bar = $own ?? ($isSummary ? $dates->summary($uid, $bars) : null);

		$row = new PlanRow(
			$task,
			$depth,
			$bar,
			$isSummary,
			// A percentage somebody set is theirs; otherwise a parent shows how
			// much of what is under it is finished (§12.5).
			$task->getPercent() ?? ($total > 0 ? (int)round($done * 100 / $total) : null),
		);

		return [
			'rows' => array_merge([$row], $below),
			'bars' => $bar === null ? $bars : array_merge([$bar], $bars),
			'done' => $done + ($this->isFinished($task) ? 1 : 0),
			'total' => $total + 1,
		];
	}

	/**
	 * The rows a move should send back: the ones it wrote, and the ancestors
	 * whose summary bar or progress the move changed (§15.6).
	 *
	 * Read again afterwards, because a summary bar is worked out from the
	 * children and has to be the one the server now holds.
	 *
	 * @param list<string> $written
	 * @return list<PlanRow>
	 */
	private function rowsAfter(int $calendarId, PlanDates $dates, array $written): array {
		$tasks = $this->tasks->findForCalendars([$calendarId]);
		$rows = $this->rows($tasks, $dates);
		$byUid = $this->byUid($tasks);

		$wanted = [];
		foreach ($written as $uid) {
			$wanted[$uid] = true;
			$current = $byUid[$uid] ?? null;
			$guard = 0;
			while ($current !== null && $guard++ < 64) {
				$parentUid = $current->getParentUid();
				if ($parentUid === null || isset($wanted[$parentUid])) {
					break;
				}
				$wanted[$parentUid] = true;
				$current = $byUid[$parentUid] ?? null;
			}
		}

		return array_values(array_filter(
			$rows,
			static fn (PlanRow $row): bool => isset($wanted[$row->task->getUid()]),
		));
	}

	/**
	 * @param list<PlanRow> $rows
	 * @param list<Dependency> $links
	 */
	private function schedule(array $rows, array $links, PlanDates $dates, PlanSettings $settings): Schedule {
		$bars = [];
		foreach ($rows as $row) {
			// A summary is derived, so the schedule neither moves it nor
			// measures anything against it.
			if ($row->bar !== null && !$row->isSummary) {
				$bars[$row->task->getUid()] = $row->bar;
			}
		}

		return new Schedule($bars, $links, $dates, new WorkingDays($settings->workingDays, $this->zone()));
	}

	/**
	 * @return list<Dependency>
	 */
	private function dependencies(int $calendarId): array {
		return array_map(
			static fn (TaskLink $row): Dependency => Dependency::fromRow($row),
			$this->links->findForCalendar($calendarId),
		);
	}

	/**
	 * The links one task declared, as the writer takes them.
	 *
	 * @return list<ParsedLink>
	 */
	private function linksOfSource(int $calendarId, string $sourceUid): array {
		return array_map(
			static fn (TaskLink $row): ParsedLink => new ParsedLink(
				$row->getPredecessorUid(),
				$row->getSuccessorUid(),
				strtoupper($row->getReltype()),
				$row->getGapSeconds(),
			),
			$this->links->findForSource($calendarId, $sourceUid),
		);
	}

	/**
	 * @param list<ParsedLink> $links
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException
	 */
	private function writeLinks(string $userId, Task $source, array $links): void {
		$this->taskService->update($userId, $source->getUid(), TaskWrite::linksOf($source, $links));
	}

	/**
	 * Whether the temporal links lead from one task to another, which is what
	 * makes a new link in the other direction a loop.
	 *
	 * @param list<Dependency> $links
	 */
	private function reaches(array $links, string $from, string $to): bool {
		$queue = [$from];
		$seen = [$from => true];

		while ($queue !== []) {
			$current = array_shift($queue);
			if ($current === $to) {
				return true;
			}

			foreach ($links as $link) {
				if (!$link->isTemporal() || $link->predecessorUid !== $current) {
					continue;
				}
				if (!isset($seen[$link->successorUid])) {
					$seen[$link->successorUid] = true;
					$queue[] = $link->successorUid;
				}
			}
		}

		return false;
	}

	private function settingsFor(string $userId, string $projectRef): PlanSettings {
		return PlanSettings::fromJson($this->projects->settings($userId, $projectRef));
	}

	private function modeOf(?string $mode, PlanSettings $settings): string {
		return $mode !== null && in_array($mode, PlanSettings::MODES, true) ? $mode : $settings->mode;
	}

	/** Cancelled work is as finished as completed work, for a progress bar. */
	private function isFinished(Task $task): bool {
		return in_array($task->getVtodoStatus(), ['COMPLETED', 'CANCELLED'], true);
	}

	/**
	 * @param list<Task> $tasks
	 * @return array<string, Task>
	 */
	private function byUid(array $tasks): array {
		$byUid = [];
		foreach ($tasks as $task) {
			$byUid[$task->getUid()] = $task;
		}

		return $byUid;
	}

	/**
	 * @param list<PlanRow> $rows
	 * @throws NotFoundException
	 */
	private function requireRow(array $rows, string $uid): PlanRow {
		foreach ($rows as $row) {
			if ($row->task->getUid() === $uid) {
				return $row;
			}
		}

		throw new NotFoundException('task_not_found');
	}

	private function dates(): PlanDates {
		return new PlanDates($this->zone());
	}

	private function zone(): DateTimeZone {
		return $this->dateTimeZone->getTimeZone();
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireCalendar(string $userId, string $projectRef): CalendarRef {
		$calendar = $this->calendars->findTaskCalendarByRef($userId, $projectRef);
		if ($calendar === null) {
			throw new NotFoundException('project_not_found');
		}

		return $calendar;
	}
}
