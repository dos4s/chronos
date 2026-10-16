<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Index;

use DateTimeZone;
use OCA\Chronos\Core\Dav\VTodoParser;
use OCA\Chronos\Core\Event\TaskAssignedEvent;
use OCA\Chronos\Core\Event\TaskDatesChangedEvent;
use OCA\Chronos\Core\Event\TaskDeletedEvent;
use OCA\Chronos\Core\Event\TaskIndexedEvent;
use OCA\Chronos\Core\Event\TaskSource;
use OCA\Chronos\Core\Event\TaskStatusChangedEvent;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskIndexer;
use OCA\Chronos\Core\Index\TaskLinkMapper;
use OCA\Chronos\Core\Index\TaskMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaskIndexerTest extends TestCase {

	private const USER = 'alice';
	private const CALENDAR_ID = 7;
	private const CALENDAR_URI = 'work';
	private const NOW = 1_760_000_000;

	private TaskMapper&MockObject $tasks;
	private TaskLinkMapper&MockObject $links;
	private TaskIndexer $indexer;

	/** @var list<Event> */
	private array $events = [];
	private ?Task $saved = null;

	protected function setUp(): void {
		parent::setUp();

		$this->tasks = $this->createMock(TaskMapper::class);
		$this->links = $this->createMock(TaskLinkMapper::class);

		$this->tasks->method('insert')->willReturnCallback(function (Task $task): Task {
			$task->setId(1);
			$this->saved = $task;
			return $task;
		});
		$this->tasks->method('update')->willReturnCallback(function (Task $task): Task {
			$this->saved = $task;
			return $task;
		});
		$this->tasks->method('softDelete')->willReturnCallback(function (Task $task, int $at): Task {
			$task->setDeletedAt($at);
			$this->saved = $task;
			return $task;
		});

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->events[] = $event;
		});

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::NOW);

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Madrid'));

		$this->indexer = new TaskIndexer(
			$this->tasks,
			$this->links,
			new VTodoParser(),
			$dispatcher,
			$timeFactory,
			$zone,
		);
	}

	public function testIndexesANewTask(): void {
		$this->tasks->method('findByObject')->willReturn(null);

		$task = $this->index(['UID:task-1', 'SUMMARY:Spike the CalDAV round trip', 'STATUS:IN-PROCESS']);

		self::assertNotNull($task);
		self::assertSame('task-1', $task->getUid());
		self::assertSame('Spike the CalDAV round trip', $task->getSummary());
		self::assertSame('IN-PROCESS', $task->getVtodoStatus());
		self::assertSame(self::USER, $task->getUserId());
		self::assertSame(self::CALENDAR_ID, $task->getCalendarId());
		self::assertSame(self::CALENDAR_URI, $task->getCalendarUri());
		self::assertSame('task-1.ics', $task->getObjectUri());
		self::assertSame('"etag-1"', $task->getEtag());
		self::assertSame(self::NOW, $task->getUpdatedAt());
	}

	public function testANewTaskIsAnnouncedAsNew(): void {
		$this->tasks->method('findByObject')->willReturn(null);

		$this->index(['UID:task-1', 'SUMMARY:First']);

		$indexed = $this->firstEvent(TaskIndexedEvent::class);
		self::assertInstanceOf(TaskIndexedEvent::class, $indexed);
		self::assertTrue($indexed->isNew());
		self::assertSame(TaskSource::External, $indexed->getSource());
	}

	public function testStoresTheLinksTheTaskDeclares(): void {
		$this->tasks->method('findByObject')->willReturn(null);
		$this->links->expects(self::once())
			->method('replaceForSource')
			->with(self::USER, self::CALENDAR_ID, 'task-1', self::callback(
				static fn (array $links): bool => count($links) === 1
					&& $links[0]->successorUid === 'task-2'
					&& $links[0]->gapSeconds === 7200,
			));

		$this->index(['UID:task-1', 'RELATED-TO;RELTYPE=FINISHTOSTART;GAP=PT2H:task-2']);
	}

	public function testAnnouncesAStatusChange(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing(['status' => 'NEEDS-ACTION']));

		$this->index(['UID:task-1', 'STATUS:COMPLETED']);

		$changed = $this->firstEvent(TaskStatusChangedEvent::class);
		self::assertInstanceOf(TaskStatusChangedEvent::class, $changed);
		self::assertSame('NEEDS-ACTION', $changed->getPreviousStatus());
		self::assertSame('COMPLETED', $changed->getTask()->getVtodoStatus());
	}

	public function testSaysNothingAboutAStatusThatDidNotChange(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing(['status' => 'IN-PROCESS']));

		$this->index(['UID:task-1', 'STATUS:IN-PROCESS']);

		self::assertNull($this->firstEvent(TaskStatusChangedEvent::class));
	}

	public function testAnnouncesADateChange(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing(['dueAt' => 1_759_000_000]));

		$this->index(['UID:task-1', 'DUE;VALUE=DATE:20261030']);

		$changed = $this->firstEvent(TaskDatesChangedEvent::class);
		self::assertInstanceOf(TaskDatesChangedEvent::class, $changed);
		self::assertSame(1_759_000_000, $changed->getPreviousDueAt());
		self::assertTrue($changed->getTask()->getDueIsDate());
	}

	public function testAnnouncesAnAssigneeChange(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing(['assignee' => 'bob']));

		$this->index(['UID:task-1', 'ATTENDEE:principals/users/alice']);

		$assigned = $this->firstEvent(TaskAssignedEvent::class);
		self::assertInstanceOf(TaskAssignedEvent::class, $assigned);
		self::assertSame('bob', $assigned->getPreviousAssignee());
		self::assertSame('alice', $assigned->getTask()->getAssignee());
	}

	public function testRecognisesItsOwnWriteAsSuch(): void {
		$this->tasks->method('findByObject')->willReturn(null);

		$this->index(['UID:task-1'], TaskSource::Chronos);

		self::assertSame(TaskSource::Chronos, $this->firstEvent(TaskIndexedEvent::class)->getSource());
	}

	public function testBringsATaskBackFromTheTrash(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing(['deletedAt' => self::NOW - 500]));

		$task = $this->index(['UID:task-1', 'SUMMARY:Back again']);

		self::assertNull($task->getDeletedAt());
	}

	public function testIgnoresAnObjectThatIsNotATask(): void {
		$this->tasks->method('findByObject')->willReturn(null);
		$this->tasks->expects(self::never())->method('insert');

		$result = $this->indexer->index(
			self::USER,
			self::CALENDAR_ID,
			self::CALENDAR_URI,
			['uri' => 'meeting.ics', 'etag' => '"e"', 'calendardata' => 'ignored', 'component' => 'vevent'],
			TaskSource::External,
		);

		self::assertNull($result);
	}

	/**
	 * A task edited into an event in another client has stopped being a task,
	 * and should stop being in the index.
	 */
	public function testForgetsATaskThatBecameSomethingElse(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing());
		$this->links->expects(self::once())->method('deleteForSource');

		$result = $this->indexer->index(
			self::USER,
			self::CALENDAR_ID,
			self::CALENDAR_URI,
			['uri' => 'task-1.ics', 'etag' => '"e"', 'calendardata' => 'ignored', 'component' => 'vevent'],
			TaskSource::External,
		);

		self::assertNull($result);
		self::assertInstanceOf(TaskDeletedEvent::class, $this->firstEvent(TaskDeletedEvent::class));
		self::assertNotNull($this->saved?->getDeletedAt());
	}

	public function testForgetsATaskItCanNoLongerRead(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing());

		$result = $this->indexer->index(
			self::USER,
			self::CALENDAR_ID,
			self::CALENDAR_URI,
			['uri' => 'task-1.ics', 'etag' => '"e"', 'calendardata' => 'not iCalendar at all', 'component' => 'vtodo'],
			TaskSource::External,
		);

		self::assertNull($result);
		self::assertInstanceOf(TaskDeletedEvent::class, $this->firstEvent(TaskDeletedEvent::class));
	}

	public function testMarksADeletedTaskDeleted(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing());

		$this->indexer->markDeleted(self::CALENDAR_ID, 'task-1.ics', TaskSource::External);

		self::assertNotNull($this->saved?->getDeletedAt());
		self::assertInstanceOf(TaskDeletedEvent::class, $this->firstEvent(TaskDeletedEvent::class));
	}

	public function testDeletingATaskTwiceAnnouncesItOnce(): void {
		$this->tasks->method('findByObject')->willReturn($this->existing(['deletedAt' => self::NOW - 10]));

		$this->indexer->markDeleted(self::CALENDAR_ID, 'task-1.ics', TaskSource::External);

		self::assertNull($this->firstEvent(TaskDeletedEvent::class));
	}

	public function testIgnoresAnObjectWithoutAUri(): void {
		self::assertNull($this->indexer->index(
			self::USER,
			self::CALENDAR_ID,
			self::CALENDAR_URI,
			['etag' => '"e"', 'calendardata' => 'x', 'component' => 'vtodo'],
			TaskSource::External,
		));
	}

	/**
	 * @param list<string> $lines
	 */
	private function index(array $lines, TaskSource $source = TaskSource::External): ?Task {
		$uid = 'task-1';
		foreach ($lines as $line) {
			if (str_starts_with($line, 'UID:')) {
				$uid = substr($line, 4);
			}
		}

		$data = implode("\r\n", [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VTODO',
			...$lines,
			'END:VTODO',
			'END:VCALENDAR',
			'',
		]);

		return $this->indexer->index(
			self::USER,
			self::CALENDAR_ID,
			self::CALENDAR_URI,
			['uri' => $uid . '.ics', 'etag' => '"etag-1"', 'calendardata' => $data, 'component' => 'vtodo'],
			$source,
		);
	}

	/**
	 * @param array{status?: string|null, dueAt?: int|null, assignee?: string|null, deletedAt?: int|null} $state
	 */
	private function existing(array $state = []): Task {
		$task = new Task();
		$task->setId(1);
		$task->setUserId(self::USER);
		$task->setCalendarId(self::CALENDAR_ID);
		$task->setCalendarUri(self::CALENDAR_URI);
		$task->setObjectUri('task-1.ics');
		$task->setUid('task-1');
		$task->setEtag('"older"');
		$task->setVtodoStatus($state['status'] ?? null);
		$task->setDueAt($state['dueAt'] ?? null);
		$task->setAssignee($state['assignee'] ?? null);
		$task->setDeletedAt($state['deletedAt'] ?? null);
		return $task;
	}

	/**
	 * @template T of Event
	 * @param class-string<T> $class
	 * @return T|null
	 */
	private function firstEvent(string $class): ?Event {
		foreach ($this->events as $event) {
			if ($event instanceof $class) {
				return $event;
			}
		}
		return null;
	}
}
