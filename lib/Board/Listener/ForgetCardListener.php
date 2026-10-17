<?php

declare(strict_types=1);

namespace OCA\Chronos\Board\Listener;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Board\Db\BoardRankMapper;
use OCA\Chronos\Core\Event\TaskDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Forgets where a card sat once its task is gone (§15.5).
 *
 * A position for a task that no longer exists means nothing, and a UID is
 * never reused, so the row would sit there for good. This runs whether the
 * kanban module is on or not — a listener cannot be left unregistered, and
 * tidying up after a deleted task is not a feature anybody can switch off.
 *
 * The index row itself stays, marked deleted, because history and links point
 * at it (§10.1); only the board position goes.
 *
 * @template-implements IEventListener<Event>
 */
class ForgetCardListener implements IEventListener {

	public function __construct(
		private BoardRankMapper $ranks,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof TaskDeletedEvent) {
			return;
		}

		$task = $event->getTask();

		try {
			$this->ranks->deleteForTask($task->getCalendarId(), $task->getUid());
		} catch (Throwable $e) {
			// A place that could not be forgotten is a stale row, not a
			// failure of the delete that triggered this.
			$this->logger->error('Chronos: forgetting a card position failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
