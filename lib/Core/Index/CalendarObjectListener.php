<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Dav\WriteRegistry;
use OCA\Chronos\Core\Event\TaskSource;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the index level with CalDAV, whoever wrote the task (§11.2).
 *
 * The contract is deliberately narrow (§11.4): parse, update the index,
 * dispatch. No network calls, no rollups, no notifications — those belong in
 * background jobs, so a sync from a phone never waits on Chronos.
 *
 * These events are public API since Nextcloud 32, which is the minimum, so
 * there is no adapter for older releases (AD10).
 *
 * @template-implements IEventListener<Event>
 */
class CalendarObjectListener implements IEventListener {

	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	public function __construct(
		private TaskIndexer $indexer,
		private WriteRegistry $writes,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			$this->route($event);
		} catch (Throwable $e) {
			// A failed index must not fail the sync that triggered it; the
			// reconcile job picks the task up again later (§11.4).
			$this->logger->error('Chronos: indexing a calendar object failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}

	private function route(Event $event): void {
		if ($event instanceof CalendarObjectMovedEvent) {
			$this->moved($event);
			return;
		}

		if (!$event instanceof AbstractCalendarObjectEvent) {
			return;
		}

		$calendarData = $event->getCalendarData();
		$userId = $this->ownerOf($calendarData);
		if ($userId === null) {
			return;
		}

		$objectData = $event->getObjectData();

		if ($event instanceof CalendarObjectDeletedEvent || $event instanceof CalendarObjectMovedToTrashEvent) {
			$this->indexer->markDeleted(
				$event->getCalendarId(),
				(string)($objectData['uri'] ?? ''),
				$this->sourceOf($event->getCalendarId(), $objectData),
			);
			return;
		}

		if (
			$event instanceof CalendarObjectCreatedEvent
			|| $event instanceof CalendarObjectUpdatedEvent
			|| $event instanceof CalendarObjectRestoredEvent
		) {
			$this->indexer->index(
				$userId,
				$event->getCalendarId(),
				(string)($calendarData['uri'] ?? ''),
				$objectData,
				$this->sourceOf($event->getCalendarId(), $objectData),
			);
		}
	}

	private function moved(CalendarObjectMovedEvent $event): void {
		$targetData = $event->getTargetCalendarData();
		$userId = $this->ownerOf($targetData);
		if ($userId === null) {
			return;
		}

		$objectData = $event->getObjectData();

		$this->indexer->move(
			$userId,
			$event->getSourceCalendarId(),
			$event->getTargetCalendarId(),
			(string)($targetData['uri'] ?? ''),
			$objectData,
			$this->sourceOf($event->getTargetCalendarId(), $objectData),
		);
	}

	/**
	 * A write Chronos just made is its own echo; anything else came from
	 * another client (§11.1).
	 *
	 * @param array<array-key, mixed> $objectData
	 */
	private function sourceOf(int $calendarId, array $objectData): TaskSource {
		$claimed = $this->writes->claim(
			$calendarId,
			(string)($objectData['uri'] ?? ''),
			(string)($objectData['etag'] ?? ''),
		);

		return $claimed ? TaskSource::Chronos : TaskSource::External;
	}

	/**
	 * The user who owns the calendar. Calendars that belong to the system
	 * rather than a person are not projects.
	 *
	 * @param array<array-key, mixed> $calendarData
	 */
	private function ownerOf(array $calendarData): ?string {
		$principal = (string)($calendarData['principaluri'] ?? '');
		if (!str_starts_with($principal, self::USER_PRINCIPAL_PREFIX)) {
			return null;
		}

		$userId = substr($principal, strlen(self::USER_PRINCIPAL_PREFIX));
		return $userId === '' ? null : $userId;
	}
}
