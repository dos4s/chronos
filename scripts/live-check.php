<?php

declare(strict_types=1);

/**
 * Live check of the workflow path, against a real instance and real CalDAV.
 *
 * The integration suite proves the schema and the index on the engine in use;
 * this proves the part that needs an actual calendar: that a workflow can be
 * created, attached to a list whose tasks then get a status, that a task walks
 * through its statuses with the right standard status, that the history
 * records who changed what and from where, and that a status the workflow does
 * not have is refused.
 *
 * Since M3 it also checks the board: that its columns are the workflow's
 * statuses, that a drop writes the status and the card's place, that
 * reordering a column writes no status at all, that an undo puts the card
 * back, that a drop the transition rules forbid is refused, and — since 0.4.2
 * — that a subtask is a card that says whose it is, that rolling the board up
 * folds it into its parent, and that a cascading drop takes the unfinished
 * part of a branch along without reopening the finished part. Then it undoes
 * all of it.
 *
 * It found D31 and D32 the first time it ran. Run it after deploying, as
 * www-data, from anywhere:
 *
 *     sudo -u www-data php scripts/live-check.php [user] [list-uri]
 *
 * It writes one throwaway task into the list given and deletes it again, and
 * leaves the project's settings as it found them.
 */

define('OC_CONSOLE', 1);

$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/nextcloud';
require_once $root . '/lib/base.php';

use OCA\Chronos\Board\Db\BoardRank;
use OCA\Chronos\Board\Db\BoardRankMapper;
use OCA\Chronos\Board\Service\BoardService;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Core\Task\TaskWrite;
use OCA\Chronos\Planning\PlanSettings;
use OCA\Chronos\Planning\Service\PlanService;
use OCA\Chronos\Planning\Service\RoadmapService;
use OCA\Chronos\Projects\Service\ProjectService;
use OCA\Chronos\Workflow\Db\StatusChange;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCA\Chronos\Workflow\Service\WorkflowService;
use OCA\Chronos\Workflow\WorkflowTemplates;
use OCP\Server;

OC_App::loadApp('chronos');

/** @var list<string> $argv */
$user = $argv[1] ?? 'admin';
$listUri = $argv[2] ?? 'personal';

$ok = 0;
$bad = 0;
function check(string $what, bool $passed, string $detail = ''): void {
	global $ok, $bad;
	$passed ? $ok++ : $bad++;
	echo ($passed ? '  ok   ' : '  FAIL ') . $what . ($detail === '' ? '' : ' — ' . $detail) . PHP_EOL;
}

$workflows = Server::get(WorkflowService::class);
$projects = Server::get(ProjectService::class);
$tasks = Server::get(TaskService::class);
$taskMapper = Server::get(TaskMapper::class);
$history = Server::get(StatusChangeMapper::class);
$board = Server::get(BoardService::class);
$rankMapper = Server::get(BoardRankMapper::class);
$planService = Server::get(PlanService::class);
$roadmapService = Server::get(RoadmapService::class);
$calendars = Server::get(\OCA\Chronos\Core\Dav\CalDavGateway::class);
$toggles = Server::get(FeatureToggles::class);

$project = null;
foreach ($projects->listProjects($user) as $candidate) {
	if ($candidate->calendar->uri === $listUri) {
		$project = $candidate;
		break;
	}
}

if ($project === null) {
	echo 'no such list for ' . $user . ': ' . $listUri . PHP_EOL;
	exit(1);
}

$ref = $project->ref();
$calendarId = $project->calendar->id;
echo 'list: ' . $project->calendar->name() . ' (' . $ref . ')' . PHP_EOL;

$workflow = null;
$taskUid = null;
$childUid = null;

// The drops below give the cards in the columns they touch a place, which is
// the first drop's job (§15.5) but would rearrange a board somebody has
// already arranged. Kept as it was found, and put back at the end.
$ranksBefore = array_map(
	static fn (BoardRank $rank): array => [
		'uid' => $rank->getTaskUid(),
		'column' => $rank->getColumnKey(),
		'rank' => $rank->getCardRank(),
	],
	array_values($rankMapper->findForCalendar($calendarId)),
);

try {
	// 1. A workflow from a template — the insert that 0.3.0 could not do.
	$workflow = $workflows->createFromTemplate($user, WorkflowTemplates::PERSONAL, 'Chronos live check');
	check('a template becomes a workflow', count($workflow->statuses) === 3, implode(', ', array_map(
		static fn ($s): string => $s->getStatusKey() . '=' . $s->getVtodoStatus(),
		$workflow->statuses,
	)));

	// 2. Attaching it gives the tasks already there a status.
	$before = $taskMapper->findForCalendars([$calendarId]);
	$workflows->assignToProject($user, $ref, $workflow->id());
	$after = $taskMapper->findForCalendars([$calendarId]);

	$withStatus = 0;
	$matching = 0;
	foreach ($after as $task) {
		if ($task->getStatusId() === null) {
			continue;
		}
		$withStatus++;
		$status = $workflow->statusById($task->getStatusId());
		if ($status !== null && $status->getVtodoStatus() === ($task->getVtodoStatus() ?? 'NEEDS-ACTION')) {
			$matching++;
		}
	}
	check(
		'the tasks already in the list got a status that matches their standard one',
		$withStatus === count($after) && $matching === $withStatus,
		$withStatus . ' of ' . count($after) . ' tasks, ' . $matching . ' matching',
	);

	// 3. A new task starts in the initial status, and says so in CalDAV.
	$created = $tasks->create($user, $ref, TaskWrite::fromParams(
		'Chronos live check — workflow',
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
	));
	$taskUid = $created->getUid();
	$initial = $workflow->statusById($created->getStatusId());
	check('a new task starts in the initial status', $initial?->getStatusKey() === 'todo', (string)$initial?->getStatusKey());

	// 4. A status change writes the key and the standard status, and is recorded.
	$moved = $tasks->update($user, $taskUid, TaskWrite::fromParams(
		'Chronos live check — workflow',
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		'doing',
		'Started it, from the live check',
	), $created->getEtag());
	$doing = $workflow->statusById($moved->getStatusId());
	check('a status change lands in the index', $doing?->getStatusKey() === 'doing', (string)$doing?->getStatusKey());
	check('the standard status follows the workflow status', $moved->getVtodoStatus() === 'IN-PROCESS', (string)$moved->getVtodoStatus());

	// 5. Ticking it off moves it to the final status (the checkbox path).
	$done = $tasks->update($user, $taskUid, TaskWrite::fromParams(
		'Chronos live check — workflow',
		'COMPLETED',
		null,
		null,
		null,
		null,
		null,
		100,
		null,
		null,
		null,
		null,
	), $moved->getEtag());
	$final = $workflow->statusById($done->getStatusId());
	check('completing a task moves it to the final status', $final?->getStatusKey() === 'done', (string)$final?->getStatusKey());

	// 6. The history of all of it.
	$rows = $history->findTimeline($taskUid);
	$steps = array_map(
		static fn (StatusChange $row): string => ($row->getFromKey() ?? '—') . '>' . ($row->getToKey() ?? '—'),
		$rows,
	);
	check('every status change was recorded', count($rows) >= 2, implode(', ', $steps));
	$withNote = array_values(array_filter(
		$rows,
		static fn (StatusChange $row): bool => $row->getComment() !== null,
	));
	check('the note was kept with the change it belongs to', count($withNote) === 1 && $withNote[0]->getToKey() === 'doing', (string)($withNote[0]?->getComment() ?? ''));
	check('the change says who made it and from where', ($rows[0]->getChangedBy() !== '') && $rows[0]->getSource() === 'chronos', $rows[0]->getChangedBy() . '/' . $rows[0]->getSource());

	// 7. A status this workflow does not have is refused.
	try {
		$tasks->update($user, $taskUid, TaskWrite::fromParams(
			'Chronos live check — workflow',
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			'waiting_customer',
			null,
		), $done->getEtag());
		check('a status from another workflow is refused', false, 'it was accepted');
	} catch (\OCA\Chronos\Core\Error\ValidationFailedException $e) {
		check('a status from another workflow is refused', $e->getErrorCode() === 'unknown_status', $e->getErrorCode());
	}

	// 8. The board: its columns are the statuses, and every card is on it once.
	$view = $board->board($user, $ref);
	$keys = array_map(static fn ($column): string => $column->key, $view->statuses->columns);
	check('the board\'s columns are the workflow\'s statuses', $keys === ['todo', 'doing', 'done'], implode(', ', $keys));

	$onBoard = [];
	foreach ($keys as $key) {
		foreach ($view->column($key) as $card) {
			$onBoard[] = $card->getUid();
		}
	}
	$cards = array_values(array_filter(
		$taskMapper->findForCalendars([$calendarId]),
		static fn (Task $task): bool => $task->getType() !== 'subproject',
	));
	check(
		'every task in the list is a card, exactly once',
		count($onBoard) === count($cards) && count(array_unique($onBoard)) === count($onBoard),
		count($onBoard) . ' cards for ' . count($cards) . ' tasks',
	);
	check(
		'the throwaway task is in the column of the status it is in',
		in_array($taskUid, array_map(static fn (Task $card): string => $card->getUid(), $view->column('done')), true),
		'done',
	);

	// 9. A drop into another column: the status is written, and the card's
	// place is recorded.
	//
	// The column chosen is the one with the most cards in it, other than the
	// one the card is already in, so that the reorder below has something to
	// be reordered against — and so that the drop is a real status change. It
	// also makes the board rank every card already there (§15.5).
	$beforeBoard = $board->board($user, $ref);
	$sittingIn = null;
	foreach ($keys as $key) {
		foreach ($beforeBoard->column($key) as $candidate) {
			if ($candidate->getUid() === $taskUid) {
				$sittingIn = $key;
				break 2;
			}
		}
	}

	// Not the column the card is already in: a drop into that one writes
	// nothing at all, which is correct and tests nothing.
	$busiest = null;
	$most = -1;
	foreach ($keys as $key) {
		if ($key === $sittingIn) {
			continue;
		}
		$others = count($beforeBoard->column($key));
		if ($others > $most) {
			$most = $others;
			$busiest = $key;
		}
	}
	$busiest ??= 'doing';

	$dropped = $board->move($user, $ref, [['uid' => $taskUid, 'etag' => $done->getEtag()]], $busiest);
	$card = $dropped->cards[0];
	check('a drop writes the new status', $workflow->statusById($card->getStatusId())?->getStatusKey() === $busiest, (string)$workflow->statusById($card->getStatusId())?->getStatusKey());
	check('a drop changes the card\'s etag, as any write does', $card->getEtag() !== $done->getEtag());
	check('the card was given a place in the column', ($dropped->ranks[$taskUid] ?? '') !== '', $busiest . ' at ' . ($dropped->ranks[$taskUid] ?? '(none)'));

	// 10. Reordering within a column is the board's own business: no CalDAV
	// write at all, so the etag stays exactly as it was.
	$column = $board->board($user, $ref)->column($busiest);
	$neighbour = null;
	foreach ($column as $other) {
		if ($other->getUid() !== $taskUid) {
			$neighbour = $other;
			break;
		}
	}

	if ($neighbour === null) {
		// Only in a list with one task in it; everything else would be a
		// check that quietly tests nothing.
		check('reordering a column writes no status', true, 'the list has no second card to reorder against');
		check('reordering a column changes the card\'s place', true, 'skipped for the same reason');
	} else {
		$firstRank = $dropped->ranks[$taskUid] ?? '';
		$reordered = $board->move(
			$user,
			$ref,
			[['uid' => $taskUid, 'etag' => $card->getEtag()]],
			$busiest,
			$neighbour->getUid(),
			null,
		);
		check('reordering a column writes no status', $reordered->cards[0]->getEtag() === $card->getEtag(), 'etag unchanged, ' . $most . ' other cards in ' . $busiest);
		check('reordering a column changes the card\'s place', ($reordered->ranks[$taskUid] ?? '') !== $firstRank, $firstRank . ' → ' . ($reordered->ranks[$taskUid] ?? '(none)'));
		check('the cards already in the column were given places too', count($board->board($user, $ref)->column($busiest)) === $most + 1, ($most + 1) . ' cards in order');
	}

	// 11. Undo: the inverse drop puts the card back where it came from.
	$currentEtag = null;
	foreach ($board->board($user, $ref)->column($busiest) as $moved) {
		if ($moved->getUid() === $taskUid) {
			$currentEtag = $moved->getEtag();
			break;
		}
	}
	$back = $board->move($user, $ref, [['uid' => $taskUid, 'etag' => $currentEtag]], 'done');
	check('an undo puts the card back in the column it came from', $workflow->statusById($back->cards[0]->getStatusId())?->getStatusKey() === 'done', (string)$workflow->statusById($back->cards[0]->getStatusId())?->getStatusKey());

	// 12. A drop the transition rules forbid is refused by the server, not
	// only dimmed in the board (F5).
	$todo = $workflow->statusByKey('todo')?->getId() ?? 0;
	$doingId = $workflow->statusByKey('doing')?->getId() ?? 0;
	$workflows->setTransitions($user, $workflow->id(), [['from' => $todo, 'to' => $doingId]]);
	// The card is back in the final column after the undo, and the only rule
	// there is allows todo → doing, so any move it could make is forbidden.
	try {
		$board->move($user, $ref, [['uid' => $taskUid]], 'todo');
		check('a drop the rules forbid is refused', false, 'it was accepted');
	} catch (\OCA\Chronos\Core\Error\ValidationFailedException $e) {
		check('a drop the rules forbid is refused', $e->getErrorCode() === 'transition_not_allowed', $e->getErrorCode());
	}
	$workflows->setTransitions($user, $workflow->id(), []);

	// 13. Subtasks (§15.9). A subtask has a status of its own, so it is a card
	// of its own — and the card has to say whose it is, because a board that
	// cannot say that is the board 0.4.1 had.
	$child = $tasks->create($user, $ref, TaskWrite::fromParams(
		'Chronos live check — subtask',
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		$taskUid,
		null,
		null,
		null,
	));
	$childUid = $child->getUid();

	$withChild = $board->board($user, $ref);
	$childFacts = $withChild->tree->factsFor($childUid);
	check(
		'a subtask is a card of its own, and says whose it is',
		($childFacts['parent']['uid'] ?? null) === $taskUid,
		(string)($childFacts['parent']['summary'] ?? '(none)'),
	);
	check(
		'the parent says how much of its branch is done',
		($withChild->tree->factsFor($taskUid)['subtasks'] ?? null) === ['total' => 1, 'done' => 0, 'open' => ['todo' => 1]],
		json_encode($withChild->tree->factsFor($taskUid)['subtasks']),
	);

	$rolledUp = $board->board($user, $ref, rolled: true);
	$rolledUids = [];
	foreach ($keys as $key) {
		foreach ($rolledUp->column($key) as $rolledCard) {
			$rolledUids[] = $rolledCard->getUid();
		}
	}
	check(
		'rolling the board up folds the subtask into its parent',
		!in_array($childUid, $rolledUids, true) && in_array($taskUid, $rolledUids, true),
		count($rolledUids) . ' cards rolled up, ' . count($onBoard) . ' + 1 unfolded',
	);

	// The subtask into another column, so a cascade has somewhere to take it
	// from.
	$childInDoing = $board->move($user, $ref, [['uid' => $childUid, 'etag' => $child->getEtag()]], 'doing');
	$parentEtag = null;
	foreach ($board->board($user, $ref)->column('done') as $parentCard) {
		if ($parentCard->getUid() === $taskUid) {
			$parentEtag = $parentCard->getEtag();
		}
	}

	$cascaded = $board->move(
		$user,
		$ref,
		[['uid' => $taskUid, 'etag' => $parentEtag]],
		'todo',
		null,
		null,
		null,
		false,
		true,
	);
	$cascadedUids = array_map(static fn (Task $moved): string => $moved->getUid(), $cascaded->cards);
	check(
		'a cascading drop takes the unfinished subtask with it',
		in_array($childUid, $cascadedUids, true),
		implode(', ', $cascadedUids),
	);
	check(
		'the subtask lands behind its parent',
		strcmp($cascaded->ranks[$taskUid] ?? '', $cascaded->ranks[$childUid] ?? '') < 0,
		($cascaded->ranks[$taskUid] ?? '(none)') . ' → ' . ($cascaded->ranks[$childUid] ?? '(none)'),
	);
	check(
		'the subtask says which column it came from, so an undo can put it back',
		($cascaded->from[$childUid] ?? null) === 'doing',
		(string)($cascaded->from[$childUid] ?? '(none)'),
	);

	// A finished subtask stays finished: dragging a parent must never reopen
	// work that is done.
	$childNow = null;
	foreach ($board->board($user, $ref)->column('todo') as $inTodo) {
		if ($inTodo->getUid() === $childUid) {
			$childNow = $inTodo;
		}
	}
	$finished = $board->move($user, $ref, [['uid' => $childUid, 'etag' => $childNow?->getEtag()]], 'done');
	$parentNow = null;
	foreach ($board->board($user, $ref)->column('todo') as $inTodo) {
		if ($inTodo->getUid() === $taskUid) {
			$parentNow = $inTodo;
		}
	}
	$again = $board->move(
		$user,
		$ref,
		[['uid' => $taskUid, 'etag' => $parentNow?->getEtag()]],
		'doing',
		null,
		null,
		null,
		false,
		true,
	);
	$stillDone = null;
	foreach ($board->board($user, $ref)->column('done') as $inDone) {
		if ($inDone->getUid() === $childUid) {
			$stillDone = $inDone;
		}
	}
	check(
		'a cascade leaves a finished subtask finished',
		$stillDone !== null && $stillDone->getEtag() === $finished->cards[0]->getEtag(),
		$stillDone === null ? 'it was moved out of done' : 'etag unchanged in done',
	);
	check(
		'a cascade with nothing to take moves only the card itself',
		count($again->cards) === 1 && $again->cards[0]->getUid() === $taskUid,
		implode(', ', array_map(static fn (Task $moved): string => $moved->getUid(), $again->cards)),
	);

	// 14. The timeline (§12.5). The throwaway task gets dates by being
	// dragged, which is the only way an inferred bar becomes a real one.
	$planService->configure($user, $ref, PlanSettings::MODE_WARN, [1, 2, 3, 4, 5]);
	$everything = $taskMapper->findForCalendars([$calendarId]);
	$firstPlan = $planService->plan($user, $ref);
	check(
		'the plan has a row for every task in the list',
		count($firstPlan->rows) === count($everything),
		count($firstPlan->rows) . ' rows for ' . count($everything) . ' tasks',
	);

	$monday = (new \DateTimeImmutable('next monday', new \DateTimeZone('UTC')))->setTime(0, 0);
	$start = $monday->getTimestamp();
	$due = $monday->modify('+2 day')->getTimestamp();

	$current = null;
	foreach ($taskMapper->findForCalendars([$calendarId]) as $candidate) {
		if ($candidate->getUid() === $taskUid) {
			$current = $candidate;
		}
	}

	$dragged = $planService->move($user, $ref, $taskUid, $start, true, $due, true, $current?->getEtag());
	$afterDrag = null;
	foreach ($dragged->rows as $row) {
		if ($row->task->getUid() === $taskUid) {
			$afterDrag = $row;
		}
	}
	check(
		'a drag writes both dates',
		$afterDrag !== null && $afterDrag->task->getStartAt() === $start && $afterDrag->task->getDueAt() === $due,
		date('Y-m-d', $afterDrag?->task->getStartAt() ?? 0) . ' → ' . date('Y-m-d', $afterDrag?->task->getDueAt() ?? 0),
	);
	check(
		'the bar covers the whole of the day it is due',
		$afterDrag?->bar !== null && $afterDrag->bar->endAt === $due + 86400,
		'ends at ' . date('Y-m-d', $afterDrag?->bar?->endAt ?? 0),
	);
	check(
		'an all-day date is written as a date, not an instant',
		($afterDrag?->task->getDueIsDate() ?? false) === true,
		'DUE;VALUE=DATE',
	);

	// The subtask from the board checks is the dependent here: a real second
	// task in the same list, which is what a dependency needs. It has to have
	// dates of its own before a cascade can move it — a task with none has no
	// bar, and the schedule has nothing to push.
	//
	// The order matters, and says something about the model: the parent is
	// dragged first, because while it has no dates of its own and its child
	// has some it is a *summary* row, and a summary is derived rather than
	// dragged (§12.5).
	$childNow = null;
	foreach ($taskMapper->findForCalendars([$calendarId]) as $candidate) {
		if ($candidate->getUid() === $childUid) {
			$childNow = $candidate;
		}
	}
	$childStart = $monday->modify('+5 day')->getTimestamp();
	$childPlan = $planService->move(
		$user,
		$ref,
		$childUid,
		$childStart,
		true,
		$childStart + 86400,
		true,
		$childNow?->getEtag(),
	);
	check(
		'a second bar lands where it was put, clear of the first',
		count($childPlan->rows) >= 1 && $childPlan->conflicts === [],
		date('Y-m-d', $childStart),
	);

	$linked = $planService->link($user, $ref, $taskUid, $childUid, 'FINISHTOSTART', 86400);
	check(
		'a dependency is stored once, predecessor to successor',
		count($linked->links) === 1
			&& $linked->links[0]->predecessorUid === $taskUid
			&& $linked->links[0]->successorUid === $childUid,
		count($linked->links) . ' links',
	);
	check('the gap survives being written and read back', ($linked->links[0]->gapSeconds ?? 0) === 86400, 'GAP=P1D');

	// M4's acceptance criterion: what Chronos wrote is in the VTODO, where
	// every other client can read it.
	$predecessorUri = null;
	foreach ($taskMapper->findForCalendars([$calendarId]) as $candidate) {
		if ($candidate->getUid() === $taskUid) {
			$predecessorUri = $candidate->getObjectUri();
		}
	}
	$object = $predecessorUri === null ? null : $calendars->getObject($calendarId, $predecessorUri);
	$ics = (string)($object['calendarData'] ?? '');
	check(
		'the dependency is readable in the VTODO itself',
		str_contains($ics, 'RELTYPE=FINISHTOSTART') && str_contains($ics, $childUid),
		'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=P1D',
	);
	check('the gap is written as an iCalendar duration', str_contains($ics, 'GAP=P1D'), 'GAP=P1D');

	// A loop cannot be satisfied by any dates, so it is refused.
	try {
		$planService->link($user, $ref, $childUid, $taskUid, 'FINISHTOSTART');
		check('a dependency that would close a loop is refused', false, 'it was accepted');
	} catch (\OCA\Chronos\Core\Error\ValidationFailedException $e) {
		check('a dependency that would close a loop is refused', $e->getErrorCode() === 'link_cycle', $e->getErrorCode());
	}

	// In warn mode the dependent stays put and the broken link is reported.
	$planService->configure($user, $ref, PlanSettings::MODE_WARN, [1, 2, 3, 4, 5]);
	$beforeWarn = $planService->plan($user, $ref);
	$dependentBefore = null;
	foreach ($beforeWarn->rows as $row) {
		if ($row->task->getUid() === $childUid) {
			$dependentBefore = $row;
		}
	}
	$predecessorNow = null;
	foreach ($beforeWarn->rows as $row) {
		if ($row->task->getUid() === $taskUid) {
			$predecessorNow = $row;
		}
	}
	$later = $monday->modify('+14 day')->getTimestamp();
	$warned = $planService->move(
		$user,
		$ref,
		$taskUid,
		$later,
		true,
		$later + 86400,
		true,
		$predecessorNow?->task->getEtag(),
	);
	// One row comes back — the task that moved — and one conflict: the
	// dependent is now too early, which is exactly what the warning is for.
	check(
		'in warn mode only the task that was dragged moves',
		count($warned->rows) === 1 && count($warned->conflicts) === 1,
		count($warned->rows) . ' rows, ' . count($warned->conflicts) . ' conflicts',
	);

	// In cascade mode the dependent follows, in the same request.
	$planService->configure($user, $ref, PlanSettings::MODE_CASCADE, [1, 2, 3, 4, 5]);
	$plannedNow = $planService->plan($user, $ref);
	$predecessorAgain = null;
	foreach ($plannedNow->rows as $row) {
		if ($row->task->getUid() === $taskUid) {
			$predecessorAgain = $row;
		}
	}
	$further = $monday->modify('+28 day')->getTimestamp();
	$cascadedPlan = $planService->move(
		$user,
		$ref,
		$taskUid,
		$further,
		true,
		$further + 86400,
		true,
		$predecessorAgain?->task->getEtag(),
	);
	$movedUids = array_map(static fn ($row): string => $row->task->getUid(), $cascadedPlan->rows);
	check(
		'in cascade mode what depends on it follows',
		in_array($childUid, $movedUids, true) && $cascadedPlan->conflicts === [],
		implode(', ', $movedUids),
	);

	$dependentAfter = null;
	foreach ($cascadedPlan->rows as $row) {
		if ($row->task->getUid() === $childUid) {
			$dependentAfter = $row;
		}
	}
	check(
		'the dependent lands after the gap, on a working day',
		$dependentAfter?->bar !== null
			&& $dependentAfter->bar->startAt >= $further + 2 * 86400
			&& (int)date('N', $dependentAfter->bar->startAt) <= 5,
		date('D Y-m-d', $dependentAfter?->bar?->startAt ?? 0),
	);

	$withoutLink = $planService->unlink($user, $ref, $linked->links[0]->id);
	check('removing a dependency rewrites the task without it', $withoutLink->links === [], count($withoutLink->links) . ' links left');
	$object = $predecessorUri === null ? null : $calendars->getObject($calendarId, $predecessorUri);
	check(
		'and takes it out of the VTODO as well',
		!str_contains((string)($object['calendarData'] ?? ''), 'FINISHTOSTART'),
		'no RELATED-TO left',
	);

	// 15. The roadmap (§12.6): every project at once, this one among them.
	$road = $roadmapService->roadmap($user, true);
	$refs = array_map(static fn ($row): string => $row->ref, $road->rows);
	check('the roadmap has a line for this project', in_array($ref, $refs, true), count($road->rows) . ' lines');
	$mine = null;
	foreach ($road->rows as $row) {
		if ($row->ref === $ref && $row->depth === 0) {
			$mine = $row;
		}
	}
	check(
		'the line spans the dates the tasks have',
		$mine?->span !== null && $mine->tasks > 0,
		$mine === null ? '(no line)' : $mine->tasks . ' tasks, ' . ($mine->span === null ? 'no span' : date('Y-m-d', $mine->span->startAt)),
	);

	// 16. Whether the board is switched on at all. A module ships off, so this
	// is a reminder rather than a bug: the API refuses while it is.
	check(
		'the kanban module is switched on for this user',
		$toggles->isEnabledForUser(FeatureToggles::MODULE_KANBAN, $user),
		'occ config:app:set chronos module_kanban --value 1 --type boolean',
	);
} finally {
	// Undo everything, in reverse. The subtask goes first: a parent with a
	// child left behind is exactly the mess this release is about.
	foreach (array_filter([$childUid, $taskUid]) as $throwaway) {
		try {
			$tasks->delete($user, $throwaway);
			echo '  cleaned up the throwaway task ' . $throwaway . PHP_EOL;
		} catch (\Throwable $e) {
			echo '  COULD NOT DELETE THE TASK: ' . $e->getMessage() . PHP_EOL;
		}
		$db = Server::get(\OCP\IDBConnection::class);
		$delete = $db->getQueryBuilder();
		$delete->delete('chronos_status_history')
			->where($delete->expr()->eq('task_uid', $delete->createNamedParameter($throwaway)));
		$delete->executeStatement();
		$hard = $db->getQueryBuilder();
		$hard->delete('chronos_tasks')
			->where($hard->expr()->eq('uid', $hard->createNamedParameter($throwaway)));
		$hard->executeStatement();
	}

	// The timeline settings this check wrote, back to none at all.
	try {
		$projects->writeSettings($user, $ref, null);
		echo '  timeline settings cleared' . PHP_EOL;
	} catch (\Throwable $e) {
		echo '  COULD NOT CLEAR THE TIMELINE SETTINGS: ' . $e->getMessage() . PHP_EOL;
	}

	// The card positions, back to exactly the rows that were there before:
	// this check arranges a column in passing, and somebody else's board is
	// not its to rearrange.
	$rankMapper->deleteForCalendar($calendarId);
	foreach ($ranksBefore as $row) {
		$rankMapper->place($calendarId, $row['uid'], $row['column'], $row['rank']);
	}
	echo '  card positions back to the ' . count($ranksBefore) . ' there were before' . PHP_EOL;

	if ($workflow !== null) {
		$workflows->assignToProject($user, $ref, null);
		$workflows->delete($user, $workflow->id());
		echo '  detached and deleted the workflow' . PHP_EOL;
	}

	// The project row exists only because the workflow needed something to
	// point at; put it back to how it was.
	$projects->configure($user, $ref, null, null, false, 0);
	echo '  project settings back to default' . PHP_EOL;
}

echo PHP_EOL . ($bad === 0 ? 'ALL ' . $ok . ' CHECKS PASSED' : $bad . ' OF ' . ($ok + $bad) . ' CHECKS FAILED') . PHP_EOL;
exit($bad === 0 ? 0 : 1);
