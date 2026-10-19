<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Workflow\Listener;

use OCA\Chronos\Core\Event\TaskSource;
use OCA\Chronos\Core\Event\TaskStatusChangedEvent;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Workflow\Db\StatusChange;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCA\Chronos\Workflow\Db\StatusMapper;
use OCA\Chronos\Workflow\Listener\StatusHistoryListener;
use OCA\Chronos\Workflow\Service\WorkflowStatusGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What gets recorded, and — the point of these cases — what used to not be.
 *
 * Until 0.5.8 a row was written only when the *workflow* status moved, so a
 * project without a workflow recorded nothing and could never be measured
 * (§12.9). Time in a state cannot be worked out afterwards, which makes a
 * regression here invisible and permanent; hence a test per case.
 */
class StatusHistoryListenerTest extends TestCase {

	private StatusChangeMapper&MockObject $history;
	private FeatureToggles&MockObject $toggles;
	private StatusHistoryListener $listener;

	/** @var list<StatusChange> */
	private array $written = [];

	protected function setUp(): void {
		$this->history = $this->createMock(StatusChangeMapper::class);
		$this->history->method('insert')->willReturnCallback(function (StatusChange $change): StatusChange {
			$this->written[] = $change;

			return $change;
		});

		$this->toggles = $this->createMock(FeatureToggles::class);
		$this->toggles->method('isAvailable')->willReturn(true);

		$statuses = $this->createMock(StatusMapper::class);
		$statuses->method('find')->willReturn(null);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->listener = new StatusHistoryListener(
			$this->history,
			$statuses,
			$this->createMock(WorkflowStatusGateway::class),
			$this->toggles,
			$time,
			$session,
		);
	}

	/**
	 * The case that was lost: no workflow, so both workflow ids are null and
	 * only the VTODO's own status moved.
	 */
	public function testAPlainStatusMoveIsRecorded(): void {
		$this->listener->handle($this->moved('NEEDS-ACTION', 'IN-PROCESS', null, null));

		self::assertCount(1, $this->written);
		$row = $this->written[0];
		self::assertSame('NEEDS-ACTION', $row->getFromStandard());
		self::assertSame('IN-PROCESS', $row->getToStandard());
		self::assertNull($row->getFromStatusId());
		self::assertNull($row->getToStatusId());
		self::assertSame(1_700_000_000, $row->getChangedAt());
		// Nobody is logged in, so the row is attributed to whose task it is.
		self::assertSame('alice', $row->getChangedBy());
	}

	public function testAWorkflowMoveStillRecordsBothStatuses(): void {
		$this->listener->handle($this->moved('NEEDS-ACTION', 'IN-PROCESS', 7, 8));

		self::assertCount(1, $this->written);
		self::assertSame(7, $this->written[0]->getFromStatusId());
		self::assertSame(8, $this->written[0]->getToStatusId());
		self::assertSame('IN-PROCESS', $this->written[0]->getToStandard());
	}

	/**
	 * Two columns of one workflow can share a standard status, so a move
	 * between them is a move even though `STATUS` did not change.
	 */
	public function testAMoveBetweenTwoColumnsOfTheSameStandardStatusIsRecorded(): void {
		$this->listener->handle($this->moved('IN-PROCESS', 'IN-PROCESS', 8, 9));

		self::assertCount(1, $this->written);
		self::assertSame(9, $this->written[0]->getToStatusId());
	}

	public function testNothingIsRecordedWhenNeitherStatusMoved(): void {
		$this->listener->handle($this->moved('IN-PROCESS', 'IN-PROCESS', 8, 8));

		self::assertSame([], $this->written);
	}

	/**
	 * A standard status is not the workflow module's data — a column is a
	 * status with or without one (AD7) — so switching the module off must not
	 * stop the recording that flow measurement depends on.
	 */
	public function testAPlainMoveIsRecordedEvenWithTheWorkflowModuleOff(): void {
		$this->toggles = $this->createMock(FeatureToggles::class);
		$this->toggles->method('isAvailable')->willReturn(false);
		$statuses = $this->createMock(StatusMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$listener = new StatusHistoryListener(
			$this->history,
			$statuses,
			$this->createMock(WorkflowStatusGateway::class),
			$this->toggles,
			$time,
			$session,
		);

		$listener->handle($this->moved('NEEDS-ACTION', 'COMPLETED', null, null));

		self::assertCount(1, $this->written);
		self::assertSame('COMPLETED', $this->written[0]->getToStandard());
	}

	private function moved(
		?string $fromStandard,
		?string $toStandard,
		?int $fromStatusId,
		?int $toStatusId,
	): TaskStatusChangedEvent {
		$task = new Task();
		$task->setUid('task-1');
		$task->setUserId('alice');
		$task->setVtodoStatus($toStandard);
		$task->setStatusId($toStatusId);

		return new TaskStatusChangedEvent($task, $fromStandard, TaskSource::Chronos, $fromStatusId);
	}
}
