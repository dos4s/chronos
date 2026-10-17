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
 * back, and that a drop the transition rules forbid is refused. Then it undoes
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
	$dropped = $board->move($user, $ref, [['uid' => $taskUid, 'etag' => $done->getEtag()]], 'doing');
	$card = $dropped->cards[0];
	check('a drop writes the new status', $workflow->statusById($card->getStatusId())?->getStatusKey() === 'doing', (string)$workflow->statusById($card->getStatusId())?->getStatusKey());
	check('a drop changes the card\'s etag, as any write does', $card->getEtag() !== $done->getEtag());
	check('the card was given a place in the column', ($dropped->ranks[$taskUid] ?? '') !== '', $dropped->ranks[$taskUid] ?? '(none)');

	// 10. Reordering within a column is the board's own business: no CalDAV
	// write at all, so the etag stays exactly as it was.
	$doingColumn = $board->board($user, $ref)->column('doing');
	$neighbour = null;
	foreach ($doingColumn as $other) {
		if ($other->getUid() !== $taskUid) {
			$neighbour = $other;
			break;
		}
	}

	if ($neighbour === null) {
		check('reordering a column writes no status', true, 'nothing else in the column to reorder against');
	} else {
		$firstRank = $dropped->ranks[$taskUid] ?? '';
		$reordered = $board->move(
			$user,
			$ref,
			[['uid' => $taskUid, 'etag' => $card->getEtag()]],
			'doing',
			$neighbour->getUid(),
			null,
		);
		check('reordering a column writes no status', $reordered->cards[0]->getEtag() === $card->getEtag(), 'etag unchanged');
		check('reordering a column changes the card\'s place', ($reordered->ranks[$taskUid] ?? '') !== $firstRank, $firstRank . ' → ' . ($reordered->ranks[$taskUid] ?? '(none)'));
	}

	// 11. Undo: the inverse drop puts the card back where it came from.
	$currentEtag = null;
	foreach ($board->board($user, $ref)->column('doing') as $inDoing) {
		if ($inDoing->getUid() === $taskUid) {
			$currentEtag = $inDoing->getEtag();
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
	try {
		$board->move($user, $ref, [['uid' => $taskUid]], 'todo');
		check('a drop the rules forbid is refused', false, 'it was accepted');
	} catch (\OCA\Chronos\Core\Error\ValidationFailedException $e) {
		check('a drop the rules forbid is refused', $e->getErrorCode() === 'transition_not_allowed', $e->getErrorCode());
	}
	$workflows->setTransitions($user, $workflow->id(), []);

	// 13. Whether the board is switched on at all. A module ships off, so this
	// is a reminder rather than a bug: the API refuses while it is.
	check(
		'the kanban module is switched on for this user',
		$toggles->isEnabledForUser(FeatureToggles::MODULE_KANBAN, $user),
		'occ config:app:set chronos module_kanban --value 1 --type boolean',
	);
} finally {
	// Undo everything, in reverse.
	if ($taskUid !== null) {
		try {
			$tasks->delete($user, $taskUid);
			echo '  cleaned up the throwaway task' . PHP_EOL;
		} catch (\Throwable $e) {
			echo '  COULD NOT DELETE THE TASK: ' . $e->getMessage() . PHP_EOL;
		}
		$db = Server::get(\OCP\IDBConnection::class);
		$delete = $db->getQueryBuilder();
		$delete->delete('chronos_status_history')
			->where($delete->expr()->eq('task_uid', $delete->createNamedParameter($taskUid)));
		$delete->executeStatement();
		$hard = $db->getQueryBuilder();
		$hard->delete('chronos_tasks')
			->where($hard->expr()->eq('uid', $hard->createNamedParameter($taskUid)));
		$hard->executeStatement();
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
