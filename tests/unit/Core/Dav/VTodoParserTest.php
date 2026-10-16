<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Dav;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Dav\VTodoParser;
use PHPUnit\Framework\TestCase;

class VTodoParserTest extends TestCase {

	private VTodoParser $parser;
	private DateTimeZone $madrid;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new VTodoParser();
		$this->madrid = new DateTimeZone('Europe/Madrid');
	}

	public function testReadsTheBasics(): void {
		$task = $this->parse(['UID:task-1', 'SUMMARY:Write the spike report', 'STATUS:IN-PROCESS', 'PRIORITY:2']);

		self::assertNotNull($task);
		self::assertSame('task-1', $task->uid);
		self::assertSame('Write the spike report', $task->summary);
		self::assertSame('IN-PROCESS', $task->vtodoStatus);
		self::assertSame(2, $task->priority);
		self::assertSame(ParsedTask::TYPE_TASK, $task->type);
	}

	public function testKeepsAnAllDayDueDateAllDay(): void {
		$task = $this->parse(['UID:task-1', 'DUE;VALUE=DATE:20261030']);

		self::assertTrue($task->dueIsDate);
		self::assertSame('2026-10-30T00:00:00+00:00', $this->iso($task->dueAt));
	}

	public function testKeepsATimedDueDateTimed(): void {
		$task = $this->parse(['UID:task-1', 'DUE;TZID=Europe/Madrid:20261030T180000']);

		self::assertFalse($task->dueIsDate);
		self::assertSame('2026-10-30T17:00:00+00:00', $this->iso($task->dueAt));
	}

	public function testReadsStartAndCompletion(): void {
		$task = $this->parse([
			'UID:task-1',
			'DTSTART;VALUE=DATE:20261020',
			'COMPLETED:20261030T120000Z',
			'PERCENT-COMPLETE:100',
		]);

		self::assertTrue($task->startIsDate);
		self::assertSame('2026-10-20T00:00:00+00:00', $this->iso($task->startAt));
		self::assertSame('2026-10-30T12:00:00+00:00', $this->iso($task->completedAt));
		self::assertSame(100, $task->percent);
	}

	public function testIgnoresAStatusICalendarDoesNotDefine(): void {
		self::assertNull($this->parse(['UID:task-1', 'STATUS:WAITING-FOR-COFFEE'])->vtodoStatus);
	}

	public function testClampsPriorityAndPercent(): void {
		$high = $this->parse(['UID:task-1', 'PRIORITY:42', 'PERCENT-COMPLETE:900']);
		$odd = $this->parse(['UID:task-2', 'PRIORITY:urgent']);

		self::assertSame(9, $high->priority);
		self::assertSame(100, $high->percent);
		self::assertSame(0, $odd->priority);
		self::assertNull($odd->percent);
	}

	public function testReadsTheParentLink(): void {
		$task = $this->parse(['UID:child', 'RELATED-TO;RELTYPE=PARENT:parent-1']);

		self::assertSame('parent-1', $task->parentUid);
		self::assertSame([], $task->links);
	}

	public function testRelatedToWithoutAReltypeIsAParent(): void {
		// RFC 5545: PARENT is the default relationship type.
		self::assertSame('parent-1', $this->parse(['UID:child', 'RELATED-TO:parent-1'])->parentUid);
	}

	/**
	 * RFC 9253: a temporal link lives on the predecessor and points at the
	 * successor.
	 */
	public function testReadsATemporalLinkInTheDirectionItIsWritten(): void {
		$task = $this->parse(['UID:first', 'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=PT2H:second']);

		self::assertCount(1, $task->links);
		self::assertSame('first', $task->links[0]->predecessorUid);
		self::assertSame('second', $task->links[0]->successorUid);
		self::assertSame('FINISHTOSTART', $task->links[0]->reltype);
		self::assertSame(7200, $task->links[0]->gapSeconds);
	}

	public function testReadsALeadAsANegativeGap(): void {
		$task = $this->parse(['UID:first', 'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=-P1D:second']);

		self::assertSame(-86400, $task->links[0]->gapSeconds);
	}

	public function testATemporalLinkWithoutAGapHasNoLag(): void {
		$task = $this->parse(['UID:first', 'RELATED-TO;RELTYPE=STARTTOSTART:second']);

		self::assertSame(0, $task->links[0]->gapSeconds);
		self::assertSame('STARTTOSTART', $task->links[0]->reltype);
	}

	public function testAnUnreadableGapIsTreatedAsNoLag(): void {
		$task = $this->parse(['UID:first', 'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=soon:second']);

		self::assertSame(0, $task->links[0]->gapSeconds);
	}

	/**
	 * RFC 9253: DEPENDS-ON lives on the dependent task, so the direction has to
	 * be turned around to match the temporal links.
	 */
	public function testNormalisesTheDirectionOfDependsOn(): void {
		$task = $this->parse(['UID:blocked', 'RELATED-TO;RELTYPE=DEPENDS-ON:blocker']);

		self::assertSame('blocker', $task->links[0]->predecessorUid);
		self::assertSame('blocked', $task->links[0]->successorUid);
		self::assertSame('DEPENDS-ON', $task->links[0]->reltype);
	}

	public function testIgnoresARelationshipTypeItDoesNotKnow(): void {
		$task = $this->parse(['UID:task-1', 'RELATED-TO;RELTYPE=SNOOZES:other']);

		self::assertSame([], $task->links);
		self::assertNull($task->parentUid);
	}

	public function testReadsSeveralRelationshipsAtOnce(): void {
		$task = $this->parse([
			'UID:middle',
			'RELATED-TO;RELTYPE=PARENT:parent-1',
			'RELATED-TO;RELTYPE=FINISHTOSTART:next',
			'RELATED-TO;RELTYPE=DEPENDS-ON:blocker',
		]);

		self::assertSame('parent-1', $task->parentUid);
		self::assertCount(2, $task->links);
	}

	public function testReadsTheChronosType(): void {
		self::assertSame(
			ParsedTask::TYPE_SUBPROJECT,
			$this->parse(['UID:a', 'X-CHRONOS-TYPE:SUBPROJECT'])->type,
		);
		self::assertSame(
			ParsedTask::TYPE_MILESTONE,
			$this->parse(['UID:b', 'X-CHRONOS-TYPE:MILESTONE'])->type,
		);
		self::assertSame(
			ParsedTask::TYPE_TASK,
			$this->parse(['UID:c', 'X-CHRONOS-TYPE:NONSENSE'])->type,
		);
	}

	public function testReadsAnAssigneeFromAPrincipal(): void {
		self::assertSame(
			'alice',
			$this->parse(['UID:a', 'ATTENDEE:principals/users/alice'])->assignee,
		);
	}

	public function testLeavesAnEmailAttendeeAlone(): void {
		self::assertNull($this->parse(['UID:a', 'ATTENDEE:mailto:alice@example.com'])->assignee);
	}

	public function testPrefersTheMasterTaskOverARecurrenceOverride(): void {
		$data = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:repeating',
			'RECURRENCE-ID:20261030T090000Z',
			'SUMMARY:This occurrence',
			'END:VTODO',
			'BEGIN:VTODO',
			'UID:repeating',
			'SUMMARY:The task itself',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		self::assertSame('The task itself', $this->parser->parse($data, $this->madrid)->summary);
	}

	public function testFallsBackToAnOverrideWhenThereIsNoMaster(): void {
		$data = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:repeating',
			'RECURRENCE-ID:20261030T090000Z',
			'SUMMARY:Only this one',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		self::assertSame('Only this one', $this->parser->parse($data, $this->madrid)->summary);
	}

	public function testReportsObjectsThatAreNotTasks(): void {
		$event = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VEVENT',
			'UID:event-1',
			'SUMMARY:Standup',
			'DTSTART:20261030T090000Z',
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		]);

		self::assertNull($this->parser->parse($event, $this->madrid));
	}

	public function testReportsATaskWithoutAUid(): void {
		self::assertNull($this->parse(['SUMMARY:Nameless']));
	}

	public function testReportsUnreadableData(): void {
		self::assertNull($this->parser->parse('this is not iCalendar', $this->madrid));
		self::assertNull($this->parser->parse('', $this->madrid));
	}

	/**
	 * @param list<string> $lines
	 */
	private function parse(array $lines): ?ParsedTask {
		$data = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Chronos//Tests//EN',
			'BEGIN:VTODO',
			...$lines,
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		return $this->parser->parse($data, $this->madrid);
	}

	public function testItReadsTheWorkflowStatusKey(): void {
		$task = $this->parse(['UID:task-1', 'SUMMARY:A ticket', 'STATUS:IN-PROCESS', 'X-CHRONOS-STATUS:Waiting_Customer']);

		// Keys are lower case, so a client that changed the case of the
		// property value still names the same status.
		self::assertSame('waiting_customer', $task?->statusKey);
	}

	public function testAWorkflowStatusKeyThatCannotBeOneIsIgnored(): void {
		$task = $this->parse(['UID:task-1', 'SUMMARY:A ticket', 'X-CHRONOS-STATUS:not a key at all']);

		self::assertNull($task?->statusKey);
	}

	public function testATaskWithoutTheExtensionPropertyHasNoWorkflowStatus(): void {
		$task = $this->parse(['UID:task-1', 'SUMMARY:A ticket', 'STATUS:NEEDS-ACTION']);

		self::assertNull($task?->statusKey);
	}

	private function iso(?int $at): string {
		self::assertNotNull($at);
		return (new DateTimeImmutable('@' . $at))->format('c');
	}
}
