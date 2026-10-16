<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

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

		$this->applyTo($todo, $write, $now);

		return $document->serialize();
	}

	/**
	 * The same task with Chronos's fields changed.
	 *
	 * @return string|null null when the data cannot be read as a task
	 */
	public function apply(string $calendarData, TaskWrite $write, int $now): ?string {
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

		$this->applyTo($todo, $write, $now);
		$this->bumpSequence($todo);

		return $document->serialize();
	}

	private function applyTo(VTodo $todo, TaskWrite $write, int $now): void {
		$todo->SUMMARY = $write->summary;
		$todo->STATUS = $write->status ?? 'NEEDS-ACTION';
		$todo->{'LAST-MODIFIED'} = TaskDates::toDateTimeValue($now);
		$todo->DTSTAMP = TaskDates::toDateTimeValue($now);

		$this->setOrRemove($todo, 'PRIORITY', $write->priority === 0 ? null : (string)$write->priority);
		$this->setOrRemove($todo, 'PERCENT-COMPLETE', $write->percent === null ? null : (string)$write->percent);

		$this->setDate($todo, 'DTSTART', $write->startAt, $write->startIsDate);
		$this->setDate($todo, 'DUE', $write->dueAt, $write->dueIsDate);

		// COMPLETED belongs to a completed task and to no other.
		if ($write->status === 'COMPLETED') {
			$todo->COMPLETED = TaskDates::toDateTimeValue($now);
		} else {
			$this->setOrRemove($todo, 'COMPLETED', null);
		}

		$this->setParent($todo, $write->parentUid);
		$this->setOrRemove($todo, self::X_TYPE, $write->type === 'task' ? null : strtoupper($write->type));
	}

	/**
	 * Writes a date, keeping the kind of date the caller asked for: an all-day
	 * date stays all-day, a timed one stays timed (§8.4).
	 */
	private function setDate(VTodo $todo, string $name, ?int $at, bool $isDate): void {
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

		$todo->add($name, TaskDates::toDateTimeValue($at));
	}

	/**
	 * Replaces the parent link while leaving every other `RELATED-TO` — the
	 * Gantt dependencies — exactly as it found them.
	 */
	private function setParent(VTodo $todo, ?string $parentUid): void {
		// Collected first: removing a property while iterating the same list
		// would skip the next one.
		$existing = [];
		foreach ($todo->select('RELATED-TO') as $property) {
			if ($property instanceof Property) {
				$existing[] = $property;
			}
		}

		foreach ($existing as $property) {
			$parameter = $property->offsetExists('RELTYPE') ? $property['RELTYPE'] : null;
			$reltype = $parameter === null
				? ParsedLink::RELTYPE_PARENT
				: strtoupper($this->valueOf($parameter));

			if ($reltype === ParsedLink::RELTYPE_PARENT) {
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
