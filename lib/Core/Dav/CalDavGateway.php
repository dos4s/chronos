<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\PreconditionFailedException;
use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The only class in Chronos that touches `OCA\DAV\CalDAV\CalDavBackend` (§9).
 *
 * That class is internal to the DAV app rather than public OCP API, so a
 * Nextcloud release that changes it touches this one file. Everything else goes
 * through these methods.
 *
 * @psalm-suppress UndefinedClass CalDavBackend is internal to the DAV app and
 *   not visible to static analysis
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedArrayAccess
 * @psalm-suppress MixedMethodCall
 * @psalm-suppress MixedReturnStatement
 * @psalm-suppress MixedInferredReturnType
 */
class CalDavGateway {

	private const COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
	private const DISPLAY_NAME = '{DAV:}displayname';
	private const COLOR = '{http://apple.com/ns/ical/}calendar-color';
	private const OWNER_PRINCIPAL = '{http://owncloud.org/ns}owner-principal';
	private const READ_ONLY = '{http://owncloud.org/ns}read-only';

	public function __construct(
		private WriteRegistry $writes,
		private LoggerInterface $logger,
		private ?CalDavBackend $backend = null,
	) {
	}

	/**
	 * Whether the DAV app is there at all. Without it Chronos still tracks
	 * time; it just has no projects to attach it to.
	 */
	public function isAvailable(): bool {
		return $this->backend !== null;
	}

	/**
	 * The calendars that can hold tasks, which is what a project is (§6).
	 *
	 * @return list<CalendarRef>
	 */
	public function getTaskCalendars(string $userId): array {
		$calendars = [];
		foreach ($this->getCalendars($userId) as $calendar) {
			if ($calendar->supportsVTodo) {
				$calendars[] = $calendar;
			}
		}
		return $calendars;
	}

	/**
	 * @return list<CalendarRef>
	 */
	public function getCalendars(string $userId): array {
		if ($this->backend === null) {
			return [];
		}

		$principalUri = 'principals/users/' . $userId;

		try {
			$rows = $this->backend->getCalendarsForUser($principalUri);
		} catch (Throwable $e) {
			$this->logger->warning('Chronos: could not list calendars: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return [];
		}

		$calendars = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$calendars[] = $this->toRef($row, $principalUri);
		}

		usort($calendars, static fn (CalendarRef $a, CalendarRef $b): int => strcasecmp($a->name(), $b->name()));

		return $calendars;
	}

	/**
	 * The project a reference points at, if the user can see it.
	 *
	 * The reference is the format the time sessions already use,
	 * `principals/users/<uid>/<calendar-uri>`, so a session and a task agree on
	 * what project they belong to.
	 */
	public function findTaskCalendarByRef(string $userId, string $ref): ?CalendarRef {
		foreach ($this->getTaskCalendars($userId) as $calendar) {
			if ($calendar->ref === $ref) {
				return $calendar;
			}
		}

		return null;
	}

	public function getCalendar(int $calendarId): ?CalendarRef {
		if ($this->backend === null) {
			return null;
		}

		$row = $this->backend->getCalendarById($calendarId);
		if (!is_array($row)) {
			return null;
		}

		return $this->toRef($row, (string)($row['principaluri'] ?? ''));
	}

	/**
	 * Every object in a calendar, with its etag but without its body: enough
	 * for the reconcile job to spot drift without parsing anything (§11.4).
	 *
	 * @return list<array{uri: string, etag: string, component: string}>
	 */
	public function listObjects(int $calendarId): array {
		if ($this->backend === null) {
			return [];
		}

		$objects = [];
		foreach ($this->backend->getCalendarObjects($calendarId) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$objects[] = [
				'uri' => (string)($row['uri'] ?? ''),
				'etag' => (string)($row['etag'] ?? ''),
				'component' => strtolower((string)($row['component'] ?? '')),
			];
		}

		return $objects;
	}

	/**
	 * @return array{uri: string, etag: string, calendarData: string}|null
	 */
	public function getObject(int $calendarId, string $objectUri): ?array {
		if ($this->backend === null) {
			return null;
		}

		$row = $this->backend->getCalendarObject($calendarId, $objectUri);
		if (!is_array($row)) {
			return null;
		}

		return [
			'uri' => (string)($row['uri'] ?? $objectUri),
			'etag' => (string)($row['etag'] ?? ''),
			'calendarData' => (string)($row['calendardata'] ?? ''),
		];
	}

	/**
	 * Writes a new task and returns its etag.
	 */
	public function createObject(int $calendarId, string $objectUri, string $calendarData): string {
		if ($this->backend === null) {
			throw new NotFoundException('calendar_backend_unavailable');
		}

		return $this->write($calendarId, $objectUri, fn (): string
			=> (string)$this->backend?->createCalendarObject($calendarId, $objectUri, $calendarData));
	}

	/**
	 * Writes a task, refusing if it has changed since the caller read it.
	 *
	 * @param string|null $ifMatch the etag the caller based its change on
	 * @throws PreconditionFailedException when the stored etag is a different one
	 * @throws NotFoundException when the object is gone
	 */
	public function updateObject(
		int $calendarId,
		string $objectUri,
		string $calendarData,
		?string $ifMatch = null,
	): string {
		if ($this->backend === null) {
			throw new NotFoundException('calendar_backend_unavailable');
		}

		$this->assertUnchanged($calendarId, $objectUri, $ifMatch);

		return $this->write($calendarId, $objectUri, fn (): string
			=> (string)$this->backend?->updateCalendarObject($calendarId, $objectUri, $calendarData));
	}

	/**
	 * @throws PreconditionFailedException|NotFoundException
	 */
	public function deleteObject(int $calendarId, string $objectUri, ?string $ifMatch = null): void {
		if ($this->backend === null) {
			throw new NotFoundException('calendar_backend_unavailable');
		}

		$this->assertUnchanged($calendarId, $objectUri, $ifMatch);

		// A delete is a write too: without this, Chronos deleting a task would
		// be recorded as someone else having deleted it (D32).
		$this->write($calendarId, $objectUri, function () use ($calendarId, $objectUri): string {
			$this->backend?->deleteCalendarObject($calendarId, $objectUri);

			return '';
		});
	}

	/**
	 * @throws PreconditionFailedException|NotFoundException
	 */
	private function assertUnchanged(int $calendarId, string $objectUri, ?string $ifMatch): void {
		if ($ifMatch === null) {
			return;
		}

		$current = $this->getObject($calendarId, $objectUri);
		if ($current === null) {
			throw new NotFoundException('task_not_found');
		}
		if ($current['etag'] !== $ifMatch) {
			throw new PreconditionFailedException();
		}
	}

	/**
	 * Runs a write with the listener told, beforehand, that it is Chronos's
	 * own (§11.1).
	 *
	 * The backend dispatches the calendar-object event from inside the call,
	 * so the registry has to know before it starts — and has to be cleared
	 * again if the write throws, since no event then arrives to consume it.
	 *
	 * @param callable(): string $write
	 */
	private function write(int $calendarId, string $objectUri, callable $write): string {
		$this->writes->expect($calendarId, $objectUri);

		try {
			return $write();
		} catch (Throwable $e) {
			$this->writes->forget($calendarId, $objectUri);
			throw $e;
		}
	}

	/**
	 * @param array<array-key, mixed> $row
	 */
	private function toRef(array $row, string $principalUri): CalendarRef {
		$uri = (string)($row['uri'] ?? '');
		$owner = (string)($row[self::OWNER_PRINCIPAL] ?? $row['principaluri'] ?? $principalUri);
		$displayName = isset($row[self::DISPLAY_NAME]) ? (string)$row[self::DISPLAY_NAME] : null;
		$color = isset($row[self::COLOR]) ? (string)$row[self::COLOR] : null;

		return new CalendarRef(
			id: (int)($row['id'] ?? 0),
			uri: $uri,
			ref: $principalUri . '/' . $uri,
			displayName: $displayName,
			color: $color,
			supportsVTodo: $this->supportsVTodo($row),
			isShared: $owner !== $principalUri,
			isWritable: (int)($row[self::READ_ONLY] ?? 0) !== 1,
		);
	}

	/**
	 * A calendar counts as a project only when it says it takes VTODOs.
	 *
	 * An unknown component set is treated as "no" (D12): an event-only
	 * calendar appearing as a project is worse than a task list missing from
	 * the list, which the user can fix in the Tasks app.
	 *
	 * @param array<array-key, mixed> $row
	 */
	private function supportsVTodo(array $row): bool {
		$components = $row[self::COMPONENT_SET] ?? null;
		if ($components === null) {
			return false;
		}

		if (is_object($components) && method_exists($components, 'getValue')) {
			$values = $components->getValue();
			return is_array($values) && in_array('VTODO', $values, true);
		}

		return is_array($components) && in_array('VTODO', $components, true);
	}
}
