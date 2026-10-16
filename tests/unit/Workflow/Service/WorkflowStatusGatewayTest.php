<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Workflow\Service;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Workflow\Db\Status;
use OCA\Chronos\Workflow\Db\Transition;
use OCA\Chronos\Workflow\Db\Workflow;
use OCA\Chronos\Workflow\Service\WorkflowService;
use OCA\Chronos\Workflow\Service\WorkflowStatusGateway;
use OCA\Chronos\Workflow\WorkflowView;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The two rules of §12.1 and §7, tested where they are decided: a status maps
 * to exactly one standard `STATUS`, and the Chronos tables are the authority
 * on which workflow status a task is in.
 */
class WorkflowStatusGatewayTest extends TestCase {

	private const USER = 'alice';
	private const PROJECT = 'principals/users/alice/work';
	private const CALENDAR_ID = 7;

	private WorkflowService&MockObject $workflows;
	private CalDavGateway&MockObject $calendars;
	private WorkflowStatusGateway $gateway;

	protected function setUp(): void {
		parent::setUp();

		$this->workflows = $this->createMock(WorkflowService::class);
		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->gateway = new WorkflowStatusGateway($this->workflows, $this->calendars);
	}

	public function testTheStatusKeyInTheVtodoWins(): void {
		$this->withWorkflow($this->personal());

		self::assertSame(
			2,
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, 'doing', 'IN-PROCESS', null),
		);
	}

	public function testAClientThatStrippedTheKeyDoesNotLoseTheStatus(): void {
		$this->withWorkflow($this->fiveStatuses());

		// "Review" and "In progress" are both IN-PROCESS: with the property
		// gone, the index is what still knows which of the two it is.
		self::assertSame(
			4,
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, null, 'IN-PROCESS', 4),
		);
	}

	public function testAStandardStatusChangedElsewhereMovesTheWorkflowStatus(): void {
		$this->withWorkflow($this->fiveStatuses());

		// Ticked off in the Tasks app: the standard status no longer matches
		// the stored one, so the workflow status follows it.
		self::assertSame(
			5,
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, null, 'COMPLETED', 4),
		);
	}

	public function testAnUnknownKeyFallsBackToTheStandardStatus(): void {
		$this->withWorkflow($this->personal());

		self::assertSame(
			3,
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, 'from-another-workflow', 'COMPLETED', null),
		);
	}

	public function testATaskWithNoStatusAtAllStartsAtTheInitialStatus(): void {
		$this->withWorkflow($this->personal());

		self::assertSame(
			1,
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, null, null, null),
		);
	}

	public function testAProjectWithoutAWorkflowHasNoStatusToStore(): void {
		$this->calendars->method('getCalendar')->willReturn($this->calendar());
		$this->workflows->method('forProject')->willReturn(null);

		self::assertNull(
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, 'doing', 'IN-PROCESS', null),
		);
	}

	public function testTheProjectsWorkflowIsLookedUpOncePerCalendar(): void {
		$this->calendars->expects(self::once())->method('getCalendar')->willReturn($this->calendar());
		$this->workflows->expects(self::once())->method('forProject')->willReturn($this->personal());

		for ($i = 0; $i < 5; $i++) {
			$this->gateway->resolveForIndex(self::USER, self::CALENDAR_ID, 'doing', 'IN-PROCESS', null);
		}
	}

	public function testANewTaskIsWrittenInTheInitialStatus(): void {
		$this->workflows->method('forProject')->willReturn($this->personal());

		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, null, null, null, null);

		self::assertSame('todo', $write->statusKey);
		self::assertSame('NEEDS-ACTION', $write->vtodoStatus);
	}

	public function testAWriteWithoutAStatusKeepsTheOneTheTaskIsIn(): void {
		$this->workflows->method('forProject')->willReturn($this->personal());
		$task = $this->task(statusId: 2, vtodoStatus: 'IN-PROCESS');

		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, $task, null, 'IN-PROCESS', null);

		self::assertSame('doing', $write->statusKey);
	}

	public function testTickingATaskOffMovesItToAFinalStatus(): void {
		$this->workflows->method('forProject')->willReturn($this->personal());
		$task = $this->task(statusId: 2, vtodoStatus: 'IN-PROCESS');

		// The checkbox sends a standard status and no key; the workflow turns
		// that into its own final status.
		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, $task, null, 'COMPLETED', null);

		self::assertSame('done', $write->statusKey);
		self::assertSame('COMPLETED', $write->vtodoStatus);
	}

	public function testAStatusTheWorkflowDoesNotHaveIsRefused(): void {
		$this->workflows->method('forProject')->willReturn($this->personal());

		$this->expectException(ValidationFailedException::class);
		$this->gateway->prepareWrite(self::USER, self::PROJECT, null, 'waiting_customer', null, null);
	}

	public function testAskingForAWorkflowStatusWhereThereIsNoWorkflowSaysSo(): void {
		$this->workflows->method('forProject')->willReturn(null);

		$this->expectException(ValidationFailedException::class);
		$this->gateway->prepareWrite(self::USER, self::PROJECT, null, 'doing', null, null);
	}

	public function testAProjectWithoutAWorkflowStillWritesTheStandardStatus(): void {
		$this->workflows->method('forProject')->willReturn(null);

		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, null, null, 'COMPLETED', null);

		self::assertNull($write->statusKey);
		self::assertNull($write->vtodoStatus);
	}

	public function testAMoveTheWorkflowDoesNotAllowIsRefused(): void {
		$this->workflows->method('forProject')->willReturn($this->withRules());
		$task = $this->task(statusId: 1, vtodoStatus: 'NEEDS-ACTION');

		$this->expectException(ValidationFailedException::class);
		// todo → done, with only todo → doing → done allowed.
		$this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'done', null, null);
	}

	public function testAMoveTheWorkflowAllowsGoesThrough(): void {
		$this->workflows->method('forProject')->willReturn($this->withRules());
		$task = $this->task(statusId: 1, vtodoStatus: 'NEEDS-ACTION');

		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'doing', null, null);

		self::assertSame('doing', $write->statusKey);
	}

	public function testATransitionThatNeedsANoteRefusesOneWithout(): void {
		$this->workflows->method('forProject')->willReturn($this->withRules());
		$task = $this->task(statusId: 2, vtodoStatus: 'IN-PROCESS');

		$this->expectException(ValidationFailedException::class);
		$this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'done', null, null);
	}

	public function testATransitionThatNeedsANoteAcceptsOne(): void {
		$this->workflows->method('forProject')->willReturn($this->withRules());
		$task = $this->task(statusId: 2, vtodoStatus: 'IN-PROCESS');

		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'done', null, 'Shipped it');

		self::assertSame('done', $write->statusKey);
		self::assertSame('Shipped it', $this->gateway->takeNote('task-1'));
		// Taken once: the next change has no note of its own.
		self::assertNull($this->gateway->takeNote('task-1'));
	}

	public function testANoteFromACreateBelongsToTheTaskItCreated(): void {
		$this->workflows->method('forProject')->willReturn($this->personal());

		$this->gateway->prepareWrite(self::USER, self::PROJECT, null, null, null, 'Raised by the customer');

		self::assertSame('Raised by the customer', $this->gateway->takeNote('whatever-uid-it-got'));
	}

	public function testANoteIsNotTakenByAnotherTask(): void {
		$this->workflows->method('forProject')->willReturn($this->personal());
		$task = $this->task(statusId: 1, vtodoStatus: 'NEEDS-ACTION');

		$this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'doing', null, 'About task 1');

		self::assertNull($this->gateway->takeNote('another-task'));
		self::assertSame('About task 1', $this->gateway->takeNote('task-1'));
	}

	public function testAStatusThatNeedsAnAssigneeRefusesATaskWithoutOne(): void {
		$this->workflows->method('forProject')->willReturn($this->needsAssignee());
		$task = $this->task(statusId: 1, vtodoStatus: 'NEEDS-ACTION');

		$this->expectException(ValidationFailedException::class);
		$this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'assigned', null, null);
	}

	public function testAStatusThatNeedsAnAssigneeAcceptsATaskWithOne(): void {
		$this->workflows->method('forProject')->willReturn($this->needsAssignee());
		$task = $this->task(statusId: 1, vtodoStatus: 'NEEDS-ACTION', assignee: 'bob');

		$write = $this->gateway->prepareWrite(self::USER, self::PROJECT, $task, 'assigned', null, null);

		self::assertSame('assigned', $write->statusKey);
	}

	private function withWorkflow(WorkflowView $view): void {
		$this->calendars->method('getCalendar')->willReturn($this->calendar());
		$this->workflows->method('forProject')->willReturn($view);
	}

	private function calendar(): CalendarRef {
		return new CalendarRef(
			id: self::CALENDAR_ID,
			uri: 'work',
			ref: self::PROJECT,
			displayName: 'Work',
			color: null,
			supportsVTodo: true,
			isShared: false,
			isWritable: true,
		);
	}

	private function personal(): WorkflowView {
		return new WorkflowView($this->workflow(), [
			$this->makeStatus(1, 'todo', 'NEEDS-ACTION', initial: true),
			$this->makeStatus(2, 'doing', 'IN-PROCESS'),
			$this->makeStatus(3, 'done', 'COMPLETED', final: true),
		], []);
	}

	/** Two statuses share IN-PROCESS, which is where the index matters. */
	private function fiveStatuses(): WorkflowView {
		return new WorkflowView($this->workflow(), [
			$this->makeStatus(1, 'backlog', 'NEEDS-ACTION', initial: true),
			$this->makeStatus(2, 'ready', 'NEEDS-ACTION'),
			$this->makeStatus(3, 'in_progress', 'IN-PROCESS'),
			$this->makeStatus(4, 'review', 'IN-PROCESS'),
			$this->makeStatus(5, 'done', 'COMPLETED', final: true),
		], []);
	}

	private function withRules(): WorkflowView {
		$doneNeedsANote = new Transition();
		$doneNeedsANote->setWorkflowId(1);
		$doneNeedsANote->setFromStatusId(2);
		$doneNeedsANote->setToStatusId(3);
		$doneNeedsANote->setRequiresComment(true);

		$start = new Transition();
		$start->setWorkflowId(1);
		$start->setFromStatusId(1);
		$start->setToStatusId(2);

		return new WorkflowView($this->workflow(), $this->personal()->statuses, [$start, $doneNeedsANote]);
	}

	private function needsAssignee(): WorkflowView {
		$assigned = $this->makeStatus(2, 'assigned', 'NEEDS-ACTION');
		$assigned->setRequiresAssignee(true);

		return new WorkflowView($this->workflow(), [
			$this->makeStatus(1, 'new', 'NEEDS-ACTION', initial: true),
			$assigned,
		], []);
	}

	private function workflow(): Workflow {
		$workflow = new Workflow();
		$workflow->setId(1);
		$workflow->setUserId(self::USER);
		$workflow->setName('A workflow');

		return $workflow;
	}

	private function makeStatus(
		int $id,
		string $key,
		string $vtodo,
		bool $initial = false,
		bool $final = false,
	): Status {
		$status = new Status();
		$status->setId($id);
		$status->setWorkflowId(1);
		$status->setStatusKey($key);
		$status->setName(ucfirst($key));
		$status->setVtodoStatus($vtodo);
		$status->setSort($id);
		$status->setIsInitial($initial);
		$status->setIsFinal($final);

		return $status;
	}

	private function task(int $statusId, string $vtodoStatus, ?string $assignee = null): Task {
		$task = new Task();
		$task->setId(1);
		$task->setUserId(self::USER);
		$task->setUid('task-1');
		$task->setCalendarId(self::CALENDAR_ID);
		$task->setCalendarUri('work');
		$task->setStatusId($statusId);
		$task->setVtodoStatus($vtodoStatus);
		$task->setAssignee($assignee);

		return $task;
	}
}
