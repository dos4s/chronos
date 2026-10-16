<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Event\TaskSource;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Brings the index level with CalDAV for a whole user, by comparing etags
 * (§11.4, §16).
 *
 * The same routine serves both jobs: the first import, where the index is
 * empty and everything is new, and the periodic reconcile, where almost
 * nothing is and the etags say so without a single VTODO being parsed.
 */
class IndexSynchroniser {

	public function __construct(
		private CalDavGateway $gateway,
		private TaskIndexer $indexer,
		private TaskMapper $tasks,
		private TaskLinkMapper $links,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{calendars: int, indexed: int, unchanged: int, removed: int}
	 */
	public function syncUser(string $userId, bool $force = false): array {
		$totals = ['calendars' => 0, 'indexed' => 0, 'unchanged' => 0, 'removed' => 0];

		foreach ($this->gateway->getTaskCalendars($userId) as $calendar) {
			$result = $this->syncCalendar($userId, $calendar, $force);
			$totals['calendars']++;
			$totals['indexed'] += $result['indexed'];
			$totals['unchanged'] += $result['unchanged'];
			$totals['removed'] += $result['removed'];
		}

		return $totals;
	}

	/**
	 * @return array{indexed: int, unchanged: int, removed: int}
	 */
	public function syncCalendar(string $userId, CalendarRef $calendar, bool $force = false): array {
		$known = $this->tasks->etagsForCalendar($calendar->id);
		$seen = [];
		$indexed = 0;
		$unchanged = 0;

		foreach ($this->gateway->listObjects($calendar->id) as $object) {
			if ($object['component'] !== 'vtodo') {
				continue;
			}

			$seen[$object['uri']] = true;

			if (!$force && ($known[$object['uri']] ?? null) === $object['etag']) {
				// The etag matches, so the VTODO has not changed and does not
				// need reading.
				$unchanged++;
				continue;
			}

			$full = $this->gateway->getObject($calendar->id, $object['uri']);
			if ($full === null) {
				continue;
			}

			$task = $this->indexer->index(
				$userId,
				$calendar->id,
				$calendar->uri,
				[
					'uri' => $full['uri'],
					'etag' => $full['etag'],
					'calendardata' => $full['calendarData'],
					'component' => 'vtodo',
				],
				TaskSource::External,
			);

			if ($task !== null) {
				$indexed++;
			}
		}

		$removed = $this->forgetVanished($calendar->id, array_keys($known), $seen);

		if ($indexed > 0 || $removed > 0) {
			$this->logger->debug(sprintf(
				'Chronos: synchronised %s — %d indexed, %d unchanged, %d removed',
				$calendar->uri,
				$indexed,
				$unchanged,
				$removed,
			), ['app' => Application::APP_ID]);
		}

		return ['indexed' => $indexed, 'unchanged' => $unchanged, 'removed' => $removed];
	}

	/**
	 * Index rows whose object is no longer in the calendar. A delete usually
	 * arrives as an event; this catches the ones that did not, which is the
	 * drift the reconcile job exists for.
	 *
	 * @param list<string> $knownUris
	 * @param array<string, true> $seen
	 */
	private function forgetVanished(int $calendarId, array $knownUris, array $seen): int {
		$removed = 0;

		foreach ($knownUris as $uri) {
			if (isset($seen[$uri])) {
				continue;
			}

			$task = $this->tasks->findByObject($calendarId, $uri);
			if ($task === null) {
				continue;
			}

			$this->links->deleteForSource($calendarId, $task->getUid());
			$this->tasks->softDelete($task, $this->timeFactory->getTime());
			$removed++;
		}

		return $removed;
	}
}
