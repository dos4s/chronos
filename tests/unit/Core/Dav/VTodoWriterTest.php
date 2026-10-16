<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Dav;

use DateTimeZone;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Dav\VTodoParser;
use OCA\Chronos\Core\Dav\VTodoWriter;
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

		$reopened = $this->writer->apply($done, new TaskWrite(summary: 'Done', status: 'NEEDS-ACTION'), self::NOW);

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

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Our summary'), self::NOW);

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

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Had a parent'), self::NOW);

		self::assertNotNull($edited);
		self::assertNull($this->parse($edited)->parentUid);
	}

	public function testAnEditTellsOtherClientsTheTaskIsNewer(): void {
		$original = $this->writer->create('task-1', new TaskWrite(summary: 'First'), self::NOW);

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Second'), self::NOW + 60);

		self::assertNotNull($edited);
		self::assertStringContainsString('SEQUENCE:1', $edited);
		self::assertStringContainsString('LAST-MODIFIED:', $edited);
	}

	public function testClearingADateRemovesIt(): void {
		$original = $this->writer->create('task-1', new TaskWrite(
			summary: 'Had a due date',
			dueAt: 1_793_318_400,
		), self::NOW);

		$edited = $this->writer->apply($original, new TaskWrite(summary: 'Had a due date'), self::NOW);

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

		self::assertNull($this->writer->apply($event, new TaskWrite(summary: 'Nope'), self::NOW));
		self::assertNull($this->writer->apply('not iCalendar', new TaskWrite(summary: 'Nope'), self::NOW));
	}

	private function parse(string $data): ParsedTask {
		$task = $this->parser->parse($data, $this->madrid);
		self::assertNotNull($task);
		return $task;
	}
}
