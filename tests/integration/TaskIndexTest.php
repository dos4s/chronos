<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCA\Chronos\Core\Event\TaskSource;
use OCA\Chronos\Core\Index\TaskIndexer;
use OCA\Chronos\Core\Index\TaskLinkMapper;
use OCA\Chronos\Core\Index\TaskMapper;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The task index against the real database: that a VTODO lands in
 * `chronos_tasks` with the right columns, that its links land in
 * `chronos_task_links`, and that re-indexing replaces rather than duplicates.
 *
 * Uses a calendar id that cannot exist, so nothing here touches real data.
 */
class TaskIndexTest extends TestCase {

	private const USER = 'chronos-itest-index';
	private const CALENDAR_ID = -4242;
	private const CALENDAR_URI = 'chronos-itest';

	private TaskIndexer $indexer;
	private TaskMapper $tasks;
	private TaskLinkMapper $links;

	protected function setUp(): void {
		parent::setUp();

		$this->indexer = Server::get(TaskIndexer::class);
		$this->tasks = Server::get(TaskMapper::class);
		$this->links = Server::get(TaskLinkMapper::class);

		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testIndexesATaskWithItsDatesAndLinks(): void {
		$task = $this->index('task-1', [
			'SUMMARY:An integration task',
			'STATUS:IN-PROCESS',
			'PRIORITY:3',
			'DUE;VALUE=DATE:20261030',
			'RELATED-TO;RELTYPE=PARENT:parent-1',
			'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=PT2H:task-2',
		]);

		self::assertNotNull($task);
		self::assertSame('An integration task', $task->getSummary());
		self::assertSame('IN-PROCESS', $task->getVtodoStatus());
		self::assertSame(3, $task->getPriority());
		self::assertSame('parent-1', $task->getParentUid());
		self::assertTrue($task->getDueIsDate());
		self::assertSame(1_793_318_400, $task->getDueAt());

		$links = $this->links->findForTask(self::USER, 'task-1');
		self::assertCount(1, $links);
		self::assertSame('task-2', $links[0]->getSuccessorUid());
		self::assertSame(7200, $links[0]->getGapSeconds());
	}

	public function testReIndexingReplacesRatherThanDuplicates(): void {
		$this->index('task-1', ['SUMMARY:First', 'RELATED-TO;RELTYPE=FINISHTOSTART:task-2']);
		$again = $this->index('task-1', ['SUMMARY:Second']);

		self::assertSame('Second', $again?->getSummary());
		self::assertCount(1, $this->tasks->findForCalendars([self::CALENDAR_ID]));
		// The dependency was removed in the second version, so it must be gone.
		self::assertSame([], $this->links->findForTask(self::USER, 'task-1'));
	}

	public function testEtagsComeBackForTheReconcileJob(): void {
		$this->index('task-1', ['SUMMARY:One']);
		$this->index('task-2', ['SUMMARY:Two']);

		$etags = $this->tasks->etagsForCalendar(self::CALENDAR_ID);

		self::assertSame(['task-1.ics' => '"etag-task-1"', 'task-2.ics' => '"etag-task-2"'], $etags);
	}

	public function testADeletedTaskLeavesTheListButKeepsItsRow(): void {
		$this->index('task-1', ['SUMMARY:Going away']);

		$this->indexer->markDeleted(self::CALENDAR_ID, 'task-1.ics', TaskSource::External);

		self::assertSame([], $this->tasks->findForCalendars([self::CALENDAR_ID]));
		self::assertNotNull($this->tasks->findByObject(self::CALENDAR_ID, 'task-1.ics'));
	}

	public function testSubtasksAreFoundByTheirParent(): void {
		$this->index('parent-1', ['SUMMARY:Parent']);
		$this->index('child-1', ['SUMMARY:Child', 'RELATED-TO;RELTYPE=PARENT:parent-1']);

		$children = $this->tasks->findChildren(self::CALENDAR_ID, 'parent-1');

		self::assertCount(1, $children);
		self::assertSame('child-1', $children[0]->getUid());
	}

	/**
	 * @param list<string> $lines
	 */
	private function index(string $uid, array $lines): ?\OCA\Chronos\Core\Index\Task {
		$data = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			'UID:' . $uid,
			...$lines,
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		return $this->indexer->index(
			self::USER,
			self::CALENDAR_ID,
			self::CALENDAR_URI,
			[
				'uri' => $uid . '.ics',
				'etag' => '"etag-' . $uid . '"',
				'calendardata' => $data,
				'component' => 'vtodo',
			],
			TaskSource::External,
		);
	}

	private function cleanUp(): void {
		$this->tasks->deleteForCalendar(self::CALENDAR_ID);
		$this->links->deleteForCalendar(self::CALENDAR_ID);
	}
}
