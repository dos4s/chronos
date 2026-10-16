<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Workflow\Db\StatusChange;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCA\Chronos\Workflow\Db\StatusMapper;
use OCA\Chronos\Workflow\Db\TransitionMapper;
use OCA\Chronos\Workflow\Db\WorkflowMapper;
use OCA\Chronos\Workflow\Service\WorkflowService;
use OCA\Chronos\Workflow\WorkflowTemplates;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Workflows against the real database (§10.3): that a template lands as rows,
 * that transition rules replace cleanly, that deleting a workflow keeps the
 * history it was recorded under, and that attaching one gives the tasks
 * already in the project a status.
 *
 * Uses a synthetic user and a calendar id that cannot exist, so nothing here
 * touches real data.
 */
class WorkflowTest extends TestCase {

	private const USER = 'chronos-itest-workflow';
	private const CALENDAR_ID = -4243;

	private WorkflowService $workflows;
	private WorkflowMapper $workflowMapper;
	private StatusMapper $statuses;
	private TransitionMapper $transitions;
	private StatusChangeMapper $history;
	private TaskMapper $tasks;
	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();

		$this->workflows = Server::get(WorkflowService::class);
		$this->workflowMapper = Server::get(WorkflowMapper::class);
		$this->statuses = Server::get(StatusMapper::class);
		$this->transitions = Server::get(TransitionMapper::class);
		$this->history = Server::get(StatusChangeMapper::class);
		$this->tasks = Server::get(TaskMapper::class);
		$this->db = Server::get(IDBConnection::class);

		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testATemplateBecomesAWorkflowWithItsStatuses(): void {
		$view = $this->workflows->createFromTemplate(self::USER, WorkflowTemplates::SUPPORT, null);

		self::assertSame('Support', $view->workflow->getName());
		self::assertCount(8, $view->statuses);
		self::assertSame('new', $view->statuses[0]->getStatusKey());
		self::assertSame('rejected', $view->statuses[7]->getStatusKey());
		// Order is the order of the template, which is the order of a board.
		self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], array_map(
			static fn ($status): int => $status->getSort(),
			$view->statuses,
		));
		self::assertTrue($view->statusByKey('waiting_customer')?->getSlaPaused());
		self::assertSame('IN-PROCESS', $view->statusByKey('waiting_customer')?->getVtodoStatus());
		// A template comes with no rules, so every move is allowed.
		self::assertSame([], $view->transitions);
	}

	public function testAWorkflowCanBeNamedSomethingElse(): void {
		$view = $this->workflows->createFromTemplate(self::USER, WorkflowTemplates::PERSONAL, 'Chores');

		self::assertSame('Chores', $view->workflow->getName());
		self::assertCount(3, $view->statuses);
	}

	public function testTransitionRulesReplaceTheOnesThatWereThere(): void {
		$view = $this->workflows->createFromTemplate(self::USER, WorkflowTemplates::PERSONAL, null);
		$todo = $view->statusByKey('todo')?->getId() ?? 0;
		$doing = $view->statusByKey('doing')?->getId() ?? 0;
		$done = $view->statusByKey('done')?->getId() ?? 0;

		$withRules = $this->workflows->setTransitions(self::USER, $view->id(), [
			['from' => $todo, 'to' => $doing],
			['from' => $doing, 'to' => $done, 'requiresComment' => true],
			// A status to itself is not a transition and is dropped.
			['from' => $done, 'to' => $done],
		]);

		self::assertCount(2, $withRules->transitions);
		self::assertTrue($withRules->allows($todo, $doing));
		self::assertFalse($withRules->allows($todo, $done));
		self::assertTrue($withRules->transition($doing, $done)?->getRequiresComment() ?? false);

		$withoutRules = $this->workflows->setTransitions(self::USER, $view->id(), []);

		self::assertSame([], $withoutRules->transitions);
		self::assertTrue($withoutRules->allows($todo, $done));
	}

	public function testAStatusCanBeRenamedButKeepsItsKeyAndItsMapping(): void {
		$view = $this->workflows->createFromTemplate(self::USER, WorkflowTemplates::PERSONAL, null);
		$doing = $view->statusByKey('doing');
		self::assertNotNull($doing);

		$updated = $this->workflows->updateStatus(
			self::USER,
			$view->id(),
			$doing->getId(),
			'In the middle of it',
			'#0082C9',
			3,
			null,
			null,
			null,
		);

		$status = $updated->statusById($doing->getId());
		self::assertSame('In the middle of it', $status?->getName());
		self::assertSame('#0082c9', $status?->getColor());
		self::assertSame(3, $status?->getWipLimit());
		self::assertSame('doing', $status?->getStatusKey());
		self::assertSame('IN-PROCESS', $status?->getVtodoStatus());
		self::assertTrue($status?->getAutoTimer());
	}

	public function testDeletingAWorkflowKeepsTheHistoryItWasRecordedUnder(): void {
		$view = $this->workflows->createFromTemplate(self::USER, WorkflowTemplates::PERSONAL, null);
		$todo = $view->statusByKey('todo')?->getId() ?? 0;
		$doing = $view->statusByKey('doing')?->getId() ?? 0;

		$change = new StatusChange();
		$change->setTaskUid('chronos-itest-task');
		$change->setFromStatusId($todo);
		$change->setToStatusId($doing);
		$change->setFromKey('todo');
		$change->setToKey('doing');
		$change->setChangedBy(self::USER);
		$change->setSource('chronos');
		$change->setChangedAt(1_760_000_000);
		$this->history->insert($change);

		$this->workflows->delete(self::USER, $view->id());

		self::assertNull($this->workflowMapper->find(self::USER, $view->id()));
		self::assertSame([], $this->statuses->findForWorkflow($view->id()));
		self::assertSame([], $this->transitions->findForWorkflow($view->id()));

		$kept = $this->history->findForTask('chronos-itest-task');
		self::assertCount(1, $kept);
		// The ids are gone with the statuses; the keys are what still say
		// what happened (§10.3).
		self::assertNull($kept[0]->getFromStatusId());
		self::assertNull($kept[0]->getToStatusId());
		self::assertSame('todo', $kept[0]->getFromKey());
		self::assertSame('doing', $kept[0]->getToKey());
	}

	public function testAttachingAWorkflowGivesTheTasksAlreadyThereAStatus(): void {
		$view = $this->workflows->createFromTemplate(self::USER, WorkflowTemplates::PERSONAL, null);
		$todo = $view->statusByKey('todo')?->getId() ?? 0;
		$done = $view->statusByKey('done')?->getId() ?? 0;

		$open = $this->task('open-task', 'NEEDS-ACTION');
		$finished = $this->task('finished-task', 'COMPLETED');
		$already = $this->task('already-task', 'NEEDS-ACTION', $done);
		// Most clients write no STATUS at all until a task is started or
		// finished, which is most of a real list (D31).
		$silent = $this->task('no-status-task', null);

		$this->tasks->fillStatusWhereUnset(self::CALENDAR_ID, 'NEEDS-ACTION', $todo);
		$this->tasks->fillStatusWhereUnset(self::CALENDAR_ID, 'COMPLETED', $done);
		$this->tasks->fillStatusWhereUnset(self::CALENDAR_ID, null, $todo);

		self::assertSame($todo, $this->reload($open)?->getStatusId());
		self::assertSame($done, $this->reload($finished)?->getStatusId());
		self::assertSame($todo, $this->reload($silent)?->getStatusId());
		// A task that already had a status keeps it, whatever its standard
		// status says.
		self::assertSame($done, $this->reload($already)?->getStatusId());

		$this->tasks->clearStatuses(self::CALENDAR_ID);

		self::assertNull($this->reload($open)?->getStatusId());
		self::assertNull($this->reload($silent)?->getStatusId());
		self::assertNull($this->reload($already)?->getStatusId());
	}

	public function testHistoryComesBackNewestFirstAndAsATimeline(): void {
		foreach ([1_760_000_100, 1_760_000_300, 1_760_000_200] as $at) {
			$change = new StatusChange();
			$change->setTaskUid('chronos-itest-task');
			$change->setChangedBy(self::USER);
			$change->setSource('external');
			$change->setChangedAt($at);
			$this->history->insert($change);
		}

		$newestFirst = array_map(
			static fn (StatusChange $change): int => $change->getChangedAt(),
			$this->history->findForTask('chronos-itest-task'),
		);
		$timeline = array_map(
			static fn (StatusChange $change): int => $change->getChangedAt(),
			$this->history->findTimeline('chronos-itest-task'),
		);

		self::assertSame([1_760_000_300, 1_760_000_200, 1_760_000_100], $newestFirst);
		self::assertSame([1_760_000_100, 1_760_000_200, 1_760_000_300], $timeline);
	}

	private function task(string $uid, ?string $vtodoStatus, ?int $statusId = null): Task {
		$task = new Task();
		$task->setUserId(self::USER);
		$task->setCalendarId(self::CALENDAR_ID);
		$task->setCalendarUri('chronos-itest');
		$task->setObjectUri($uid . '.ics');
		$task->setUid($uid);
		$task->setEtag('"etag"');
		$task->setVtodoStatus($vtodoStatus);
		$task->setStatusId($statusId);
		$task->setUpdatedAt(1_760_000_000);

		return $this->tasks->insert($task);
	}

	private function reload(Task $task): ?Task {
		return $this->tasks->findByObject(self::CALENDAR_ID, $task->getObjectUri());
	}

	private function cleanUp(): void {
		foreach ($this->workflowMapper->findAll(self::USER) as $workflow) {
			$this->statuses->deleteForWorkflow($workflow->getId());
			$this->transitions->deleteForWorkflow($workflow->getId());
			$this->workflowMapper->delete($workflow);
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete('chronos_status_history')
			->where($delete->expr()->eq('task_uid', $delete->createNamedParameter('chronos-itest-task')));
		$delete->executeStatement();

		$delete = $this->db->getQueryBuilder();
		$delete->delete('chronos_tasks')
			->where($delete->expr()->eq(
				'calendar_id',
				$delete->createNamedParameter(self::CALENDAR_ID, IQueryBuilder::PARAM_INT),
			));
		$delete->executeStatement();
	}
}
