<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Listener;

use OCA\Chronos\Core\Event\TaskAssignedEvent;
use OCA\Chronos\Core\Event\TaskStatusChangedEvent;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Workflow\Db\StatusChange;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCA\Chronos\Workflow\Db\StatusMapper;
use OCA\Chronos\Workflow\Service\WorkflowStatusGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Writes status history (§10.3), whoever made the change.
 *
 * This is why history is built in M2 rather than with SLA: time in a state
 * cannot be worked out afterwards, and a change made on a phone three days ago
 * is only ever seen once, when it reaches the server.
 *
 * @template-implements IEventListener<Event>
 */
class StatusHistoryListener implements IEventListener {

	public function __construct(
		private StatusChangeMapper $history,
		private StatusMapper $statuses,
		private WorkflowStatusGateway $gateway,
		private FeatureToggles $toggles,
		private ITimeFactory $timeFactory,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->toggles->isAvailable(FeatureToggles::MODULE_WORKFLOW)) {
			// A switched-off module records nothing (§13).
			return;
		}

		if ($event instanceof TaskStatusChangedEvent) {
			$this->recordStatus($event);
			return;
		}

		if ($event instanceof TaskAssignedEvent) {
			$this->recordAssignee($event);
		}
	}

	private function recordStatus(TaskStatusChangedEvent $event): void {
		$task = $event->getTask();
		$from = $event->getPreviousStatusId();
		$to = $task->getStatusId();

		if ($from === $to) {
			// The standard status moved but the workflow status did not, which
			// happens when a project has no workflow at all: there is no
			// workflow transition to record.
			return;
		}

		$change = $this->row($task, $event->getSource()->value);
		$change->setFromStatusId($from);
		$change->setToStatusId($to);
		$change->setFromKey($this->keyOf($from));
		$change->setToKey($this->keyOf($to));
		$change->setFromAssignee($task->getAssignee());
		$change->setToAssignee($task->getAssignee());
		$change->setComment($this->gateway->takeNote($task->getUid()));

		$this->history->insert($change);
	}

	private function recordAssignee(TaskAssignedEvent $event): void {
		$task = $event->getTask();
		$status = $task->getStatusId();

		$change = $this->row($task, $event->getSource()->value);
		// The status did not move, so both sides name the one it is in: a
		// timeline reading these rows sees an assignee change, not a status
		// change (§12.3).
		$change->setFromStatusId($status);
		$change->setToStatusId($status);
		$change->setFromKey($this->keyOf($status));
		$change->setToKey($this->keyOf($status));
		$change->setFromAssignee($event->getPreviousAssignee());
		$change->setToAssignee($task->getAssignee());

		$this->history->insert($change);
	}

	private function row(Task $task, string $source): StatusChange {
		$change = new StatusChange();
		$change->setTaskUid($task->getUid());
		$change->setSource($source);
		$change->setChangedAt($this->timeFactory->getTime());
		// Who is logged in, or — for a background job reconciling the index —
		// whose task it is.
		$change->setChangedBy($this->userSession->getUser()?->getUID() ?? $task->getUserId());

		return $change;
	}

	private function keyOf(?int $statusId): ?string {
		if ($statusId === null) {
			return null;
		}

		return $this->statuses->find($statusId)?->getStatusKey();
	}
}
