<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

use DateTimeZone;
use OCA\Chronos\Core\Support\TaskDates;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Parameter;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Reads a VTODO into the shape the index stores (§7).
 *
 * Standard properties first: only what iCalendar has no property for is read
 * from `X-CHRONOS-*`, and even then the Chronos tables stay authoritative, so a
 * client that strips those properties loses nothing.
 */
class VTodoParser {

	private const X_TYPE = 'X-CHRONOS-TYPE';
	private const X_STATUS = 'X-CHRONOS-STATUS';
	private const PRINCIPAL_PREFIX = 'principals/users/';

	/**
	 * @return ParsedTask|null null when the object holds no VTODO, or is unreadable
	 */
	public function parse(string $calendarData, DateTimeZone $userTimezone): ?ParsedTask {
		$todo = $this->readTodo($calendarData);
		if ($todo === null) {
			return null;
		}

		$uid = $this->text($todo->UID);
		if ($uid === null) {
			// Without a UID there is nothing to key the index on.
			return null;
		}

		$start = TaskDates::fromProperty($this->property($todo, 'DTSTART'), $userTimezone);
		$due = TaskDates::fromProperty($this->property($todo, 'DUE'), $userTimezone);
		$completed = TaskDates::fromProperty($this->property($todo, 'COMPLETED'), $userTimezone);

		$parentUid = null;
		$links = [];
		foreach ($this->relations($todo, $uid) as $relation) {
			if ($relation instanceof ParsedLink) {
				$links[] = $relation;
				continue;
			}
			$parentUid ??= $relation;
		}

		return new ParsedTask(
			uid: $uid,
			type: $this->type($todo),
			parentUid: $parentUid,
			summary: $this->text($todo->SUMMARY),
			vtodoStatus: $this->status($todo),
			statusKey: $this->statusKey($todo),
			priority: $this->priority($todo),
			startAt: $start['at'] ?? null,
			startIsDate: $start['isDate'] ?? false,
			dueAt: $due['at'] ?? null,
			dueIsDate: $due['isDate'] ?? false,
			completedAt: $completed['at'] ?? null,
			percent: $this->percent($todo),
			assignee: $this->assignee($todo),
			links: $links,
		);
	}

	/**
	 * The VTODO to index: the master component, not a recurrence override.
	 */
	private function readTodo(string $calendarData): ?VTodo {
		try {
			$document = Reader::read($calendarData, Reader::OPTION_FORGIVING);
		} catch (ParseException|Throwable) {
			return null;
		}

		if (!$document instanceof VCalendar) {
			return null;
		}

		$fallback = null;
		foreach ($document->getComponents() as $component) {
			if (!$component instanceof VTodo) {
				continue;
			}
			if (!isset($component->{'RECURRENCE-ID'})) {
				return $component;
			}
			$fallback ??= $component;
		}

		return $fallback;
	}

	/**
	 * Walks `RELATED-TO`, returning a parent UID as a string and every other
	 * relationship as a ParsedLink.
	 *
	 * @return list<ParsedLink|string>
	 */
	private function relations(VTodo $todo, string $uid): array {
		$relations = [];

		foreach ($todo->select('RELATED-TO') as $property) {
			if (!$property instanceof Property) {
				continue;
			}

			$target = $this->text($property);
			if ($target === null) {
				continue;
			}

			$reltype = strtoupper($this->parameter($property, 'RELTYPE') ?? ParsedLink::RELTYPE_PARENT);

			if ($reltype === ParsedLink::RELTYPE_PARENT) {
				$relations[] = $target;
				continue;
			}

			$gap = IcalDuration::parse($this->parameter($property, 'GAP'));

			if ($reltype === ParsedLink::RELTYPE_DEPENDS_ON) {
				// Written on the dependent task, pointing at what it waits for.
				$relations[] = new ParsedLink($target, $uid, $reltype, $gap);
				continue;
			}

			if (in_array($reltype, ParsedLink::TEMPORAL_RELTYPES, true)) {
				// Written on the predecessor, pointing at the successor.
				$relations[] = new ParsedLink($uid, $target, $reltype, $gap);
			}
		}

		return $relations;
	}

	private function type(VTodo $todo): string {
		$raw = strtolower($this->text($todo->{self::X_TYPE}) ?? '');

		return match ($raw) {
			ParsedTask::TYPE_SUBPROJECT => ParsedTask::TYPE_SUBPROJECT,
			ParsedTask::TYPE_MILESTONE => ParsedTask::TYPE_MILESTONE,
			default => ParsedTask::TYPE_TASK,
		};
	}

	private function status(VTodo $todo): ?string {
		$status = strtoupper($this->text($todo->STATUS) ?? '');
		return in_array($status, ParsedTask::VTODO_STATUSES, true) ? $status : null;
	}

	/**
	 * The workflow status a client wrote back, if it kept the property at all.
	 * A client that strips it loses nothing: the index still holds the status,
	 * and Chronos writes the property again on its next write (§7).
	 */
	private function statusKey(VTodo $todo): ?string {
		$key = $this->text($todo->{self::X_STATUS});
		if ($key === null) {
			return null;
		}

		$key = strtolower($key);
		return preg_match('/^[a-z0-9_-]{1,64}$/', $key) === 1 ? $key : null;
	}

	private function priority(VTodo $todo): int {
		$priority = $this->text($todo->PRIORITY);
		if ($priority === null || !is_numeric($priority)) {
			return 0;
		}
		return max(0, min(9, (int)$priority));
	}

	private function percent(VTodo $todo): ?int {
		$percent = $this->text($todo->{'PERCENT-COMPLETE'});
		if ($percent === null || !is_numeric($percent)) {
			return null;
		}
		return max(0, min(100, (int)$percent));
	}

	/**
	 * Only principal-based attendees map to a Nextcloud user. An attendee given
	 * as an email address is left alone: the index holds user ids, and guessing
	 * one from an address would be wrong as often as right.
	 */
	private function assignee(VTodo $todo): ?string {
		foreach ($todo->select('ATTENDEE') as $property) {
			if (!$property instanceof Property) {
				continue;
			}

			$value = $this->text($property);
			if ($value === null) {
				continue;
			}
			$value = preg_replace('/^principal:/i', '', $value) ?? $value;
			if (str_starts_with($value, self::PRINCIPAL_PREFIX)) {
				$uid = substr($value, strlen(self::PRINCIPAL_PREFIX));
				if ($uid !== '') {
					return $uid;
				}
			}
		}

		return null;
	}

	private function property(VTodo $todo, string $name): ?Property {
		/** @var mixed $property */
		$property = $todo->{$name};
		return $property instanceof Property ? $property : null;
	}

	private function parameter(Property $property, string $name): ?string {
		if (!$property->offsetExists($name)) {
			return null;
		}

		return $this->text($property[$name]);
	}

	/**
	 * The value of a property, or of anything that can sensibly be read as
	 * text. A component or a list of parts is not one.
	 */
	private function text(mixed $property): ?string {
		if ($property instanceof Parameter || $property instanceof Property) {
			/** @var mixed $property */
			$property = $property->getValue();
		}
		if (!is_scalar($property)) {
			return null;
		}

		$value = trim((string)$property);
		return $value === '' ? null : $value;
	}
}
