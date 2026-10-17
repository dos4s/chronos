<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

use DateTimeZone;
use OCA\Chronos\Core\Support\TaskDates;
use OCA\Chronos\Core\Task\TaskWrite;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Writes the fields Chronos manages into a VTODO, and nothing else (§7).
 *
 * An edit reads the task as it is, changes those fields and writes it back, so
 * a property Chronos has never heard of — a client's own extension, an alarm,
 * a recurrence rule — survives being edited here.
 *
 * @psalm-suppress MixedMethodCall vobject's magic property access is untyped
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 * @psalm-suppress MixedPropertyFetch
 */
class VTodoWriter {

	private const PRODID = '-//Chronos//Nextcloud//EN';
	private const X_TYPE = 'X-CHRONOS-TYPE';
	private const X_STATUS = 'X-CHRONOS-STATUS';

	/**
	 * A new task, as an iCalendar document.
	 */
	public function create(string $uid, TaskWrite $write, int $now): string {
		$document = new VCalendar();
		$document->PRODID = self::PRODID;
		$document->VERSION = '2.0';

		/** @var VTodo $todo */
		$todo = $document->add('VTODO');
		$todo->UID = $uid;
		$todo->add('CREATED', TaskDates::toDateTimeValue($now));
		$todo->add('DTSTAMP', TaskDates::toDateTimeValue($now));
		$todo->add('SEQUENCE', 0);

		// A new task has no date to preserve the form of, so the zone a
		// floating one would be written in never comes up.
		$this->applyTo($todo, $write, $now, new DateTimeZone('UTC'));

		return $document->serialize();
	}

	/**
	 * The same task with Chronos's fields changed.
	 *
	 * @param DateTimeZone $floating the zone a floating date-time in this task
	 *                               means — the user's own, as the parser read
	 *                               it, so a floating time stays the same wall
	 *                               clock after a write (§8.4)
	 * @return string|null null when the data cannot be read as a task
	 */
	public function apply(
		string $calendarData,
		TaskWrite $write,
		int $now,
		DateTimeZone $floating,
	): ?string {
		try {
			$document = Reader::read($calendarData, Reader::OPTION_FORGIVING);
		} catch (Throwable) {
			return null;
		}

		if (!$document instanceof VCalendar) {
			return null;
		}

		$todo = null;
		foreach ($document->getComponents() as $component) {
			if ($component instanceof VTodo && !isset($component->{'RECURRENCE-ID'})) {
				$todo = $component;
				break;
			}
		}

		if ($todo === null) {
			return null;
		}

		$this->applyTo($todo, $write, $now, $floating);
		$this->bumpSequence($todo);

		return $document->serialize();
	}

	/**
	 * @param DateTimeZone $floating the zone a floating date-time means, which
	 *                               is the user's own, as the parser read it
	 */
	private function applyTo(VTodo $todo, TaskWrite $write, int $now, DateTimeZone $floating): void {
		$todo->SUMMARY = $write->summary;
		$todo->STATUS = $write->status ?? 'NEEDS-ACTION';
		$todo->{'LAST-MODIFIED'} = TaskDates::toDateTimeValue($now);
		$todo->DTSTAMP = TaskDates::toDateTimeValue($now);

		$this->setOrRemove($todo, 'PRIORITY', $write->priority === 0 ? null : (string)$write->priority);
		$this->setOrRemove($todo, 'PERCENT-COMPLETE', $write->percent === null ? null : (string)$write->percent);

		$this->setDate($todo, 'DTSTART', $write->startAt, $write->startIsDate, $floating);
		$this->setDate($todo, 'DUE', $write->dueAt, $write->dueIsDate, $floating);

		// COMPLETED belongs to a completed task and to no other.
		if ($write->status === 'COMPLETED') {
			$todo->COMPLETED = TaskDates::toDateTimeValue($now);
		} else {
			$this->setOrRemove($todo, 'COMPLETED', null);
		}

		$this->setParent($todo, $write->parentUid);
		$this->setLinks($todo, $write->links);
		$this->setOrRemove($todo, self::X_TYPE, $write->type === 'task' ? null : strtoupper($write->type));
		// The workflow status, for the clients that keep it; the Chronos tables
		// remain the authority either way (§7).
		$this->setOrRemove($todo, self::X_STATUS, $write->statusKey);
	}

	/**
	 * Writes a date, keeping the kind of date the caller asked for: an all-day
	 * date stays all-day, a timed one stays timed (§8.4).
	 *
	 * A timed date also keeps the **zone it was written in**. Chronos used to
	 * rewrite every timed date as UTC, which §7 and §8.4 both say it does not:
	 * the instant survived, but `DUE;TZID=Europe/Madrid` came back as `DUE…Z`,
	 * and a *floating* time — one that means six o'clock wherever the reader
	 * is — came back pinned to an instant, which is a different thing to say
	 * (D39). So the property that is there is read before it is replaced: a
	 * `TZID` is written again as that `TZID`, a floating time stays floating,
	 * and anything else is UTC as before.
	 */
	private function setDate(
		VTodo $todo,
		string $name,
		?int $at,
		bool $isDate,
		DateTimeZone $floating,
	): void {
		$form = $this->dateForm($todo, $name, $floating);
		$todo->remove($name);
		if ($at === null) {
			return;
		}

		if ($isDate) {
			$property = $todo->add($name, TaskDates::toDateValue($at));
			if ($property instanceof Property) {
				$property['VALUE'] = TaskDates::VALUE_DATE;
			}
			return;
		}

		if ($form === null) {
			$todo->add($name, TaskDates::toDateTimeValue($at));
			return;
		}

		[$tzid, $zone] = $form;
		$property = $todo->add($name, TaskDates::toDateTimeValueIn($at, $zone));
		if ($tzid !== null && $property instanceof Property) {
			$property['TZID'] = $tzid;
		}
	}

	/**
	 * How this date is written today: its `TZID` and the zone that means, or
	 * the viewer's zone for a floating time, or null for UTC and for a date
	 * that is not there at all.
	 *
	 * @return array{0: string|null, 1: DateTimeZone}|null
	 */
	private function dateForm(VTodo $todo, string $name, DateTimeZone $floating): ?array {
		$property = $todo->{$name};
		if (!$property instanceof Property) {
			return null;
		}

		/** @var mixed $raw */
		$raw = $property->getValue();
		$value = is_scalar($raw) ? (string)$raw : '';
		if ($value === '' || str_ends_with($value, 'Z')) {
			return null;
		}

		if ($property->offsetExists('TZID')) {
			$tzid = $this->valueOf($property['TZID']);
			try {
				return $tzid === '' ? null : [$tzid, new DateTimeZone($tzid)];
			} catch (Throwable) {
				// A zone nobody has heard of is not one to write back.
				return null;
			}
		}

		// No TZID and no Z: a floating time, which stays floating. It is
		// written in the zone it was read in (§8.4), so the wall-clock time
		// the user set is the wall-clock time that comes back.
		return [null, $floating];
	}

	/**
	 * Replaces the dependencies, leaving the parent link and anything that is
	 * not a `RELATED-TO` alone (§12.5).
	 *
	 * Null means "not a link edit", which is every write but one, and then
	 * nothing here happens at all.
	 *
	 * @param list<ParsedLink>|null $links
	 */
	private function setLinks(VTodo $todo, ?array $links): void {
		if ($links === null) {
			return;
		}

		foreach ($this->relatedTo($todo) as $property) {
			if ($this->reltypeOf($property) !== ParsedLink::RELTYPE_PARENT) {
				$todo->remove($property);
			}
		}

		foreach ($links as $link) {
			// A temporal link is written on the predecessor and points at the
			// successor; `DEPENDS-ON` is written on the dependent task and
			// points at what it waits for (§7, RFC 9253).
			$target = $link->reltype === ParsedLink::RELTYPE_DEPENDS_ON
				? $link->predecessorUid
				: $link->successorUid;

			$property = $todo->add('RELATED-TO', $target);
			if (!$property instanceof Property) {
				continue;
			}

			$property['RELTYPE'] = $link->reltype;
			if ($link->gapSeconds !== 0) {
				$property['GAP'] = IcalDuration::format($link->gapSeconds);
			}
		}
	}

	/**
	 * Replaces the parent link while leaving every other `RELATED-TO` — the
	 * Gantt dependencies — exactly as it found them.
	 */
	private function setParent(VTodo $todo, ?string $parentUid): void {
		foreach ($this->relatedTo($todo) as $property) {
			if ($this->reltypeOf($property) === ParsedLink::RELTYPE_PARENT) {
				$todo->remove($property);
			}
		}

		if ($parentUid === null) {
			return;
		}

		$property = $todo->add('RELATED-TO', $parentUid);
		if ($property instanceof Property) {
			$property['RELTYPE'] = ParsedLink::RELTYPE_PARENT;
		}
	}

	/**
	 * Every `RELATED-TO` on the task, collected first: removing a property
	 * while iterating the same list would skip the next one.
	 *
	 * @return list<Property>
	 */
	private function relatedTo(VTodo $todo): array {
		$properties = [];
		foreach ($todo->select('RELATED-TO') as $property) {
			if ($property instanceof Property) {
				$properties[] = $property;
			}
		}

		return $properties;
	}

	/**
	 * A relationship's type. No `RELTYPE` means `PARENT` (RFC 5545 §3.2.15).
	 */
	private function reltypeOf(Property $property): string {
		if (!$property->offsetExists('RELTYPE')) {
			return ParsedLink::RELTYPE_PARENT;
		}

		return strtoupper($this->valueOf($property['RELTYPE']));
	}

	/**
	 * A parameter's value as text; vobject types these loosely.
	 */
	private function valueOf(mixed $node): string {
		if ($node instanceof Parameter || $node instanceof Property) {
			/** @var mixed $node */
			$node = $node->getValue();
		}

		return is_scalar($node) ? (string)$node : '';
	}

	private function setOrRemove(VTodo $todo, string $name, ?string $value): void {
		$todo->remove($name);
		if ($value !== null) {
			$todo->add($name, $value);
		}
	}

	/**
	 * Other clients use SEQUENCE to tell which version of a task they have.
	 */
	private function bumpSequence(VTodo $todo): void {
		$current = isset($todo->SEQUENCE) ? (int)$this->valueOf($todo->SEQUENCE) : 0;
		$todo->remove('SEQUENCE');
		$todo->add('SEQUENCE', $current + 1);
	}
}
