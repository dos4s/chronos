<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Dav;

use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Dav\VTodoParser;
use OCA\Chronos\Core\Dav\VTodoWriter;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\TaskWrite;
use PHPUnit\Framework\TestCase;

class VTodoWriterTest extends TestCase {

	private const NOW = 1_760_000_000;

	private VTodoWriter $writer;
	private VTodoParser $parser;
	private DateTimeZone $madrid;

	protected function setUp(): void {
		parent::setUp();
		$this->writer = new VTodoWriter();
		$this->parser = new VTodoParser();
		$this->madrid = new DateTimeZone('Europe/Madrid');
	}

	public function testWritesATaskThatCanBeReadBack(): void {
		$data = $this->writer->create('task-1', new TaskWrite(
			summary: 'Write the spike report',
			status: 'IN-PROCESS',
			priority: 2,
		), self::NOW);

		$task = $this->parse($data);
		self::assertSame('task-1', $task->uid);
		self::assertSame('Write the spike report', $task->summary);
		self::assertSame('IN-PROCESS', $task->vtodoStatus);
		self::assertSame(2, $task->priority);
	}

	public function testANewTaskWithoutAStatusNeedsAction(): void {
		$task = $this->parse($this->writer->create('task-1', new TaskWrite(summary: 'Something'), self::NOW));

		self::assertSame('NEEDS-ACTION', $task->vtodoStatus);
	}

	public function testTheWorkflowStatusIsWrittenAsAnExtensionProperty(): void {
		$data = $this->writer->create('task-1', new TaskWrite(
			summary: 'Waiting on the customer',
			status: 'IN-PROCESS',
			statusKey: 'waiting_customer',
		), self::NOW);

		self::assertStringContainsString('X-CHRONOS-STATUS:waiting_customer', $data);
		// Every other client still sees a sensible standard status (§12.1).
		self::assertStringContainsString('STATUS:IN-PROCESS', $data);
		self::assertSame('waiting_customer', $this->parse($data)->statusKey);
	}

	public function testATaskWithNoWorkflowCarriesNoWorkflowStatus(): void {
		$data = $this->writer->create('task-1', new TaskWrite(summary: 'Plain'), self::NOW);

		self::assertStringNotContainsString('X-CHRONOS-STATUS', $data);
		self::assertNull($this->parse($data)->statusKey);
	}

	public function testAWriteWithoutAWorkflowStatusRemovesTheOneThatWasThere(): void {
		$withStatus = $this->writer->create('task-1', new TaskWrite(
			summary: 'Was in a workflow',
			statusKey: 'doing',
		), self::NOW);

		$without = $this->writer->apply($withStatus, new TaskWrite(summary: 'Now in none'), self::NOW, $this->madrid);

		self::assertNotNull($without);
		self::assertStringNotContainsString('X-CHRONOS-STATUS', $without);
	}

	public function testAnAllDayDateIsWrittenAsADate(): void {
		$data = $this->writer->create('task-1', new TaskWrite(
			summary: 'Due that day',
			dueAt: 1_793_318_400, // 2026-10-30T00:00:00Z
			dueIsDate: true,
		), self::NOW);

		self::assertStringContainsString('DUE;VALUE=DATE:20261030', $data);

		$task = $this->parse($data);
		self::assertTrue($task->dueIsDate);
		self::assertSame(1_793_318_400, $task->dueAt);
	}

	public function testATimedDateIsWrittenAsAnInstant(): void {
		$data = $this->writer->create('task-1', new TaskWrite(
			summary: 'Due at six',
			dueAt: 1_793_379_600, // 2026-10-30T17:00:00Z
			dueIsDate: false,
		), self::NOW);

		self::assertStringContainsString('DUE:20261030T170000Z', $data);

		$task = $this->parse($data);
		self::assertFalse($task->dueIsDate);
		self::assertSame(1_793_379_600, $task->dueAt);
	}

	public function testWritesTheParentLink(): void {
		$data = $this->writer->create('child', new TaskWrite(summary: 'A subtask', parentUid: 'parent-1'), self::NOW);

		self::assertSame('parent-1', $this->parse($data)->parentUid);
	}

	public function testWritesASubprojectAsOne(): void {
		$data = $this->writer->create('sub', new TaskWrite(
			summary: 'Phase one',
			type: ParsedTask::TYPE_SUBPROJECT,
		), self::NOW);

		self::assertStringContainsString('X-CHRONOS-TYPE:SUBPROJECT', $data);
		self::assertSame(ParsedTask::TYPE_SUBPROJECT, $this->parse($data)->type);
	}

	public function testAPlainTaskCarriesNoChronosType(): void {
		$data = $this->writer->create('task-1', new TaskWrite(summary: 'Plain'), self::NOW);

		self::assertStringNotContainsString('X-CHRONOS-TYPE', $data);
	}

	public function testCompletingATaskRecordsWhen(): void {
		$data = $this->writer->create('task-1', new TaskWrite(
			summary: 'Done',
			status: 'COMPLETED',
			percent: 100,
		), self::NOW);

		$task = $this->parse($data);
		self::assertSame('COMPLETED', $task->vtodoStatus);
		self::assertSame(self::NOW, $task->completedAt);
		self::assertSame(100, $task->percent);
	}

	public function testReopeningATaskClearsTheCompletion(): void {
		$done = $this->writer->create('task-1', new TaskWrite(summary: 'Done', status: 'COMPLETED'), self::NOW);

		$reopened = $this->writer->apply($done, new TaskWrite(summary: 'Done', status: 'NEEDS-ACTION'), self::NOW, $this->madrid);

		self::assertNotNull($reopened);
		self::assertStringNotContainsString('COMPLETED:', $reopened);
		self::assertNull($this->parse($reopened)->completedAt);
	}

	/**
	 * The guarantee the whole design rests on: Chronos edits its own fields and
	 * leaves everything else exactly as the other client wrote it (§7).
	 */
	public function testAnEditKeepsWhatChronosDoesNotUnderstand(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Another Client//EN',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Their summary',
			'DESCRIPTION:Something they wrote',
			'CATEGORIES:homelab,urgent',
			'LOCATION:The shed',
			'URL:https://example.com/ticket/7',
			'RRULE:FREQ=WEEKLY',
			'X-SOMETHING-ELSE:keep me',
			'BEGIN:VALARM',
			'ACTION:DISPLAY',
			'TRIGGER:-PT15M',
			'END:VALARM',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Our summary'), self::NOW, $this->madrid);

		self::assertNotNull($edited);
		self::assertStringContainsString('SUMMARY:Our summary', $edited);
		self::assertStringContainsString('DESCRIPTION:Something they wrote', $edited);
		self::assertStringContainsString('CATEGORIES:homelab,urgent', $edited);
		self::assertStringContainsString('LOCATION:The shed', $edited);
		// vobject writes a property's default value type back explicitly, so
		// this comes out as `URL;VALUE=URI:…` — the same property, re-spelled.
		self::assertStringContainsString('https://example.com/ticket/7', $edited);
		self::assertStringContainsString('RRULE:FREQ=WEEKLY', $edited);
		self::assertStringContainsString('X-SOMETHING-ELSE:keep me', $edited);
		self::assertStringContainsString('BEGIN:VALARM', $edited);
		self::assertStringContainsString('TRIGGER:-PT15M', $edited);
	}

	/**
	 * Changing the parent must not disturb the Gantt dependencies, which are
	 * RELATED-TO properties too (§7).
	 */
	public function testChangingTheParentLeavesDependenciesAlone(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Linked',
			'RELATED-TO;RELTYPE=PARENT:old-parent',
			'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=PT2H:next-task',
			'RELATED-TO;RELTYPE=DEPENDS-ON:blocker',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		$edited = $this->writer->apply(
			$original,
			new TaskWrite(summary: 'Linked', parentUid: 'new-parent'),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		$task = $this->parse($edited);

		self::assertSame('new-parent', $task->parentUid);
		self::assertStringNotContainsString('old-parent', $edited);
		self::assertCount(2, $task->links);
		self::assertStringContainsString('GAP=PT2H', $edited);
	}

	public function testRemovingTheParentRemovesOnlyThatLink(): void {
		$original = $this->writer->create('task-1', new TaskWrite(
			summary: 'Had a parent',
			parentUid: 'parent-1',
		), self::NOW);

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Had a parent'), self::NOW, $this->madrid);

		self::assertNotNull($edited);
		self::assertNull($this->parse($edited)->parentUid);
	}

	public function testAnEditTellsOtherClientsTheTaskIsNewer(): void {
		$original = $this->writer->create('task-1', new TaskWrite(summary: 'First'), self::NOW);

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Second'), self::NOW + 60, $this->madrid);

		self::assertNotNull($edited);
		self::assertStringContainsString('SEQUENCE:1', $edited);
		self::assertStringContainsString('LAST-MODIFIED:', $edited);
	}

	public function testClearingADateRemovesIt(): void {
		$original = $this->writer->create('task-1', new TaskWrite(
			summary: 'Had a due date',
			dueAt: 1_793_318_400,
		), self::NOW);

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Had a due date'), self::NOW, $this->madrid);

		self::assertNotNull($edited);
		self::assertStringNotContainsString('DUE', $edited);
		self::assertNull($this->parse($edited)->dueAt);
	}

	public function testRefusesDataThatIsNotATask(): void {
		$event = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VEVENT',
			'UID:event-1',
			'DTSTART:20261030T090000Z',
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		]);

		self::assertNull($this->writer->apply($event, new TaskWrite(summary: 'Nope'), self::NOW, $this->madrid));
		self::assertNull($this->writer->apply('not iCalendar', new TaskWrite(summary: 'Nope'), self::NOW, $this->madrid));
	}

	/**
	 * A timed date keeps the zone it was written in (D39). Chronos used to
	 * rewrite every one as UTC, which §7 and §8.4 both say it does not: the
	 * instant survived, but the task stopped saying which zone it meant.
	 */
	public function testATimedDateKeepsItsTimeZone(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Zoned',
			'DUE;TZID=Europe/Madrid:20261030T180000',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);
		$due = $this->parse($original)->dueAt;

		$edited = $this->writer->apply(
			$original,
			new TaskWrite(summary: 'Zoned', dueAt: $due, dueIsDate: false),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('DUE;TZID=Europe/Madrid:20261030T180000', $edited);
		self::assertSame($due, $this->parse($edited)->dueAt);
	}

	/**
	 * A floating time means six o'clock wherever the reader is (§8.4). Pinning
	 * it to an instant says something else, so it stays floating.
	 */
	public function testAFloatingDateStaysFloating(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Floating',
			'DTSTART:20261030T090000',
			'DUE:20261030T180000',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);
		$task = $this->parse($original);

		$edited = $this->writer->apply(
			$original,
			new TaskWrite(
				summary: 'Floating',
				startAt: $task->startAt,
				startIsDate: false,
				dueAt: $task->dueAt,
				dueIsDate: false,
			),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('DTSTART:20261030T090000', $edited);
		self::assertStringContainsString('DUE:20261030T180000', $edited);
		self::assertStringNotContainsString('DUE:20261030T180000Z', $edited);
	}

	public function testAUtcDateStaysUtc(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Absolute',
			'DUE:20261030T170000Z',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);
		$due = $this->parse($original)->dueAt;

		$edited = $this->writer->apply(
			$original,
			new TaskWrite(summary: 'Absolute', dueAt: $due, dueIsDate: false),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('DUE:20261030T170000Z', $edited);
	}

	/** An all-day date is a date whatever zone anybody is in (§8.4). */
	public function testAnAllDayDateStaysAnAllDayDate(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:All day',
			'DUE;VALUE=DATE:20261030',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);
		$due = $this->parse($original)->dueAt;

		$edited = $this->writer->apply(
			$original,
			new TaskWrite(summary: 'All day', dueAt: $due, dueIsDate: true),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('DUE;VALUE=DATE:20261030', $edited);
	}

	/**
	 * A temporal dependency is written on the predecessor and points at the
	 * successor, with `GAP` carrying the lag (§7, RFC 9253 §4, §6.2).
	 */
	public function testWritesADependencyOnThePredecessor(): void {
		$original = $this->writer->create('task-1', new TaskWrite(summary: 'First'), self::NOW);

		$edited = $this->writer->apply(
			$original,
			TaskWrite::linksOf($this->task('task-1', 'First'), [
				new ParsedLink('task-1', 'task-2', 'FINISHTOSTART', 86400),
			]),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('RELTYPE=FINISHTOSTART', $edited);
		self::assertStringContainsString('GAP=P1D', $edited);

		$links = $this->parse($edited)->links;
		self::assertCount(1, $links);
		self::assertSame('task-1', $links[0]->predecessorUid);
		self::assertSame('task-2', $links[0]->successorUid);
		self::assertSame(86400, $links[0]->gapSeconds);
	}

	/** `DEPENDS-ON` goes the other way: on the task that is waiting. */
	public function testWritesADependsOnLinkOnTheTaskThatWaits(): void {
		$original = $this->writer->create('task-2', new TaskWrite(summary: 'Second'), self::NOW);

		$edited = $this->writer->apply(
			$original,
			TaskWrite::linksOf($this->task('task-2', 'Second'), [
				new ParsedLink('task-1', 'task-2', ParsedLink::RELTYPE_DEPENDS_ON, 0),
			]),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('RELATED-TO;RELTYPE=DEPENDS-ON:task-1', $edited);
		self::assertStringNotContainsString('GAP=', $edited);
	}

	/**
	 * A link edit replaces the dependencies and keeps the parent: they are all
	 * `RELATED-TO`, and only one of them is the tree (§6).
	 */
	public function testALinkEditKeepsTheParentAndReplacesTheRest(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Linked',
			'RELATED-TO;RELTYPE=PARENT:the-parent',
			'RELATED-TO;RELTYPE=FINISHTOSTART:old-successor',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		$edited = $this->writer->apply(
			$original,
			TaskWrite::linksOf($this->task('task-1', 'Linked', 'the-parent'), [
				new ParsedLink('task-1', 'new-successor', 'STARTTOSTART', 0),
			]),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertStringContainsString('RELATED-TO;RELTYPE=PARENT:the-parent', $edited);
		self::assertStringContainsString('new-successor', $edited);
		self::assertStringNotContainsString('old-successor', $edited);
	}

	/** An empty list is how the last dependency is taken away. */
	public function testAnEmptyLinkListRemovesEveryDependency(): void {
		$original = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:task-1',
			'SUMMARY:Linked',
			'RELATED-TO;RELTYPE=FINISHTOSTART:successor',
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		$edited = $this->writer->apply(
			$original,
			TaskWrite::linksOf($this->task('task-1', 'Linked'), []),
			self::NOW,
			$this->madrid,
		);

		self::assertNotNull($edited);
		self::assertSame([], $this->parse($edited)->links);
	}

	private function task(string $uid, string $summary, ?string $parentUid = null): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setSummary($summary);
		$task->setVtodoStatus('NEEDS-ACTION');
		$task->setType('task');
		$task->setParentUid($parentUid);

		return $task;
	}

	private function parse(string $data): ParsedTask {
		$task = $this->parser->parse($data, $this->madrid);
		self::assertNotNull($task);
		return $task;
	}
}
