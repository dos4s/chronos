<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Projects\Service;

use DateTimeZone;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Dav\VTodoWriter;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Task\TaskWrite;
use OCA\Chronos\Projects\ProjectView;
use OCA\Chronos\Projects\Service\ProjectService;
use OCA\Chronos\Projects\Service\PromoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Promoting a subproject to a list of its own (F2, §6).
 *
 * The thing worth testing here is not the happy path — it is what happens
 * half way: CalDAV moves one object at a time and has no transaction, so the
 * service has to be honest about how far it got and leave something valid
 * behind either way.
 */
class PromoteServiceTest extends TestCase {

	private const USER = 'alice';
	private const REF = 'principals/users/alice/work';
	private const CALENDAR_ID = 11;
	private const NEW_ID = 42;

	private CalDavGateway&MockObject $calendars;
	private TaskMapper&MockObject $tasks;
	private ProjectService&MockObject $projects;
	private PromoteService $promote;

	/** @var list<Task> */
	private array $index = [];

	/** @var list<array{calendarId: int, uri: string, data: string}> */
	private array $created = [];

	/** @var list<array{calendarId: int, uri: string}> */
	private array $deleted = [];

	/** @var list<int> */
	private array $deletedCalendars = [];

	/** @var array<string, mixed>|null */
	private ?array $configured = null;

	/** Which object URI the gateway should refuse to create, for the half-way case. */
	private ?string $refuse = null;

	private string $listName = 'Promoted';

	protected function setUp(): void {
		parent::setUp();

		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->projects = $this->createMock(ProjectService::class);

		$this->calendars->method('findTaskCalendarByRef')->willReturnCallback(
			fn (): ?CalendarRef => $this->calendar(),
		);
		$this->tasks->method('findForCalendars')->willReturnCallback(fn (): array => $this->index);
		$this->calendars->method('getObject')->willReturnCallback(
			fn (int $calendarId, string $uri): ?array => ['calendarData' => $this->icsFor($uri), 'etag' => 'etag'],
		);
		$this->calendars->method('createTaskCalendar')->willReturnCallback(
			function (string $userId, string $name, ?string $color): CalendarRef {
				$this->listName = $name;

				return $this->newList($name);
			},
		);
		$this->calendars->method('createObject')->willReturnCallback(
			function (int $calendarId, string $uri, string $data): string {
				if ($uri === $this->refuse) {
					throw new RuntimeException('the storage said no');
				}
				$this->created[] = ['calendarId' => $calendarId, 'uri' => $uri, 'data' => $data];

				return 'etag-new';
			},
		);
		$this->calendars->method('deleteObject')->willReturnCallback(
			function (int $calendarId, string $uri): void {
				$this->deleted[] = ['calendarId' => $calendarId, 'uri' => $uri];
			},
		);
		$this->calendars->method('deleteCalendar')->willReturnCallback(
			function (int $calendarId): void {
				$this->deletedCalendars[] = $calendarId;
			},
		);
		$this->projects->method('findRow')->willReturn(null);
		$this->projects->method('configure')->willReturnCallback(
			function (string $userId, string $ref, ?int $areaId, ?string $parentRef): ProjectView {
				$this->configured = ['ref' => $ref, 'areaId' => $areaId, 'parentRef' => $parentRef];

				return new ProjectView($this->newList($this->listName), null, $parentRef);
			},
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_760_000_000);
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));

		$this->promote = new PromoteService(
			$this->calendars,
			$this->tasks,
			new VTodoWriter(),
			$this->projects,
			$time,
			$zone,
			new NullLogger(),
		);
	}

	public function testMovesTheWholeBranchIntoANewList(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('one', parentUid: 'container'),
			$this->task('two', parentUid: 'container'),
			$this->task('deep', parentUid: 'two'),
			$this->task('elsewhere'),
		];

		$result = $this->promote->promote(self::USER, self::REF, 'container', null);

		// Three tasks, not four: the container is not part of its own branch.
		self::assertSame(3, $result->moved);
		self::assertSame(0, $result->left);
		self::assertNull($result->failure);
		self::assertSame(
			['one.ics', 'two.ics', 'deep.ics'],
			array_slice(array_column($this->created, 'uri'), 0, 3),
		);
		// Copied into the new list, removed from the old one.
		self::assertSame([self::NEW_ID, self::NEW_ID, self::NEW_ID], array_slice(array_column($this->created, 'calendarId'), 0, 3));
		self::assertSame([self::CALENDAR_ID], array_unique(array_column($this->deleted, 'calendarId')));
		// A task in the list but outside the branch stays where it is.
		self::assertNotContains('elsewhere.ics', array_column($this->created, 'uri'));
	}

	/**
	 * The container has become the list its children are in, so the link that
	 * pointed at it has nothing left to point at (§6).
	 */
	public function testTheDirectChildrenLoseTheParentLinkAndTheDeeperOnesKeepTheirs(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('one', parentUid: 'container'),
			$this->task('deep', parentUid: 'one'),
		];

		$this->promote->promote(self::USER, self::REF, 'container', null);

		$written = [];
		foreach ($this->created as $object) {
			$written[$object['uri']] = $object['data'];
		}

		self::assertStringNotContainsString('container', $written['one.ics']);
		self::assertStringContainsString('RELTYPE=PARENT:one', $written['deep.ics']);
	}

	public function testTheContainerItselfIsRemovedOnceItsTasksAreOut(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('one', parentUid: 'container'),
		];

		$this->promote->promote(self::USER, self::REF, 'container', null);

		self::assertSame(['one.ics', 'container.ics'], array_column($this->deleted, 'uri'));
	}

	/** The new list remembers where it came from (§6). */
	public function testTheNewListIsLinkedToTheProjectItCameFrom(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT, summary: 'Phase one'),
			$this->task('one', parentUid: 'container'),
		];

		$result = $this->promote->promote(self::USER, self::REF, 'container', null);

		self::assertSame(self::REF, $this->configured['parentRef'] ?? null);
		self::assertSame('Phase one', $result->project->calendar->name());
	}

	public function testTheNameCanBeGivenInsteadOfTakenFromTheSubproject(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT, summary: 'Phase one'),
			$this->task('one', parentUid: 'container'),
		];

		$result = $this->promote->promote(self::USER, self::REF, 'container', '  Autumn work  ');

		self::assertSame('Autumn work', $result->project->calendar->name());
	}

	/**
	 * Half way: the tasks that moved have moved, the answer says how many are
	 * left, and the container stays — so running it again finishes the job.
	 */
	public function testAFailurePartWaySaysHowFarItGotAndLeavesTheContainerAlone(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('one', parentUid: 'container'),
			$this->task('two', parentUid: 'container'),
			$this->task('three', parentUid: 'container'),
		];
		$this->refuse = 'two.ics';

		$result = $this->promote->promote(self::USER, self::REF, 'container', null);

		self::assertSame(1, $result->moved);
		self::assertSame(2, $result->left);
		self::assertNotNull($result->failure);
		// The container is still there, holding what is left.
		self::assertNotContains('container.ics', array_column($this->deleted, 'uri'));
		self::assertSame([], $this->deletedCalendars);
	}

	/** Nothing moved, so the list it would have moved into is litter. */
	public function testAPromotionThatMovesNothingTakesItsNewListBackAgain(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('one', parentUid: 'container'),
		];
		$this->refuse = 'one.ics';

		try {
			$this->promote->promote(self::USER, self::REF, 'container', null);
			self::fail('it should have refused');
		} catch (ForbiddenException $e) {
			self::assertSame('promote_failed', $e->getErrorCode());
		}

		self::assertSame([self::NEW_ID], $this->deletedCalendars);
	}

	public function testAnEmptySubprojectIsNotWorthAList(): void {
		$this->index = [$this->task('container', type: ParsedTask::TYPE_SUBPROJECT)];

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('nothing_to_promote');

		$this->promote->promote(self::USER, self::REF, 'container', null);
	}

	/** Only a container becomes a list: a task is work (§6). */
	public function testATaskThatIsNotASubprojectIsRefused(): void {
		$this->index = [$this->task('plain'), $this->task('child', parentUid: 'plain')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('not_a_subproject');

		$this->promote->promote(self::USER, self::REF, 'plain', null);
	}

	public function testSomethingThatIsNotThereIsRefused(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('task_not_found');

		$this->promote->promote(self::USER, self::REF, 'ghost', null);
	}

	public function testAListSharedReadOnlyIsNobodysToReorganise(): void {
		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->calendars->method('findTaskCalendarByRef')->willReturn($this->calendar(writable: false));
		$this->promote = new PromoteService(
			$this->calendars,
			$this->tasks,
			new VTodoWriter(),
			$this->projects,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IDateTimeZone::class),
			new NullLogger(),
		);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('project_read_only');

		$this->promote->promote(self::USER, self::REF, 'container', null);
	}

	public function testABranchTooBigForOneRequestIsRefused(): void {
		$this->index = [$this->task('container', type: ParsedTask::TYPE_SUBPROJECT)];
		for ($i = 0; $i < 201; $i++) {
			$this->index[] = $this->task('t' . $i, parentUid: 'container');
		}

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('too_many_tasks');

		$this->promote->promote(self::USER, self::REF, 'container', null);
	}

	private function calendar(bool $writable = true): CalendarRef {
		return new CalendarRef(
			self::CALENDAR_ID,
			'work',
			self::REF,
			'Work',
			'#0082c9',
			true,
			false,
			$writable,
		);
	}

	private function newList(string $name): CalendarRef {
		return new CalendarRef(
			self::NEW_ID,
			'phase-one',
			'principals/users/alice/phase-one',
			$name,
			'#0082c9',
			true,
			false,
			true,
		);
	}

	/** The ICS the gateway hands back for an object, built by the real writer. */
	private function icsFor(string $uri): string {
		$uid = str_replace('.ics', '', $uri);
		$task = null;
		foreach ($this->index as $candidate) {
			if ($candidate->getUid() === $uid) {
				$task = $candidate;
			}
		}

		return (new VTodoWriter())->create($uid, new TaskWrite(
			summary: $task?->getSummary() ?? $uid,
			parentUid: $task?->getParentUid(),
			type: $task?->getType() ?? ParsedTask::TYPE_TASK,
		), 1_760_000_000);
	}

	private function task(
		string $uid,
		?string $parentUid = null,
		string $type = ParsedTask::TYPE_TASK,
		string $summary = 'A task',
	): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setSummary($summary);
		$task->setObjectUri($uid . '.ics');
		$task->setEtag('etag-' . $uid);
		$task->setCalendarId(self::CALENDAR_ID);
		$task->setType($type);
		$task->setParentUid($parentUid);
		$task->setVtodoStatus('NEEDS-ACTION');
		$task->setStartIsDate(true);
		$task->setDueIsDate(true);

		return $task;
	}
}
