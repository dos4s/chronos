<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Planning\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedLink;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskLink;
use OCA\Chronos\Core\Index\TaskLinkMapper;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Core\Task\TaskWrite;
use OCA\Chronos\Planning\PlanRow;
use OCA\Chronos\Planning\PlanSettings;
use OCA\Chronos\Planning\Service\PlanService;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a timeline reads, what a drag on it writes, and what it refuses
 * (§12.5, §15.4).
 *
 * The arithmetic and the cascade have tests of their own; these are the
 * decisions this class makes — which rows a plan has, which writes a move
 * makes, and which of them it will not make at all.
 */
class PlanServiceTest extends TestCase {

	private const USER = 'alice';
	private const REF = 'principals/users/alice/work';
	private const CALENDAR_ID = 11;

	private CalDavGateway&MockObject $calendars;
	private TaskMapper&MockObject $tasks;
	private TaskLinkMapper&MockObject $links;
	private TaskService&MockObject $taskService;
	private ProjectService&MockObject $projects;
	private PlanService $plan;

	/** @var list<Task> */
	private array $index = [];

	/** @var list<TaskLink> */
	private array $stored = [];

	/** @var list<array{uid: string, write: TaskWrite, etag: string|null}> */
	private array $written = [];

	private ?string $settingsJson = null;

	protected function setUp(): void {
		parent::setUp();

		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->links = $this->createMock(TaskLinkMapper::class);
		$this->taskService = $this->createMock(TaskService::class);
		$this->projects = $this->createMock(ProjectService::class);

		$this->calendars->method('findTaskCalendarByRef')->willReturn($this->calendar());
		$this->tasks->method('findForCalendars')->willReturnCallback(fn (): array => $this->index);
		$this->links->method('findForCalendar')->willReturnCallback(fn (): array => $this->stored);
		$this->links->method('findForSource')->willReturnCallback(
			fn (int $calendarId, string $sourceUid): array => array_values(array_filter(
				$this->stored,
				static fn (TaskLink $row): bool => $row->getSourceUid() === $sourceUid,
			)),
		);
		$this->projects->method('settings')->willReturnCallback(fn (): ?string => $this->settingsJson);
		$this->taskService->method('update')->willReturnCallback(
			function (string $userId, string $uid, TaskWrite $write, ?string $etag = null): Task {
				$this->written[] = ['uid' => $uid, 'write' => $write, 'etag' => $etag];

				return $this->remember($uid, $write);
			},
		);

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));

		$this->plan = new PlanService(
			$this->calendars,
			$this->tasks,
			$this->links,
			$this->taskService,
			$this->projects,
			$zone,
		);
	}

	public function testAPlanIsTheTaskTreeFlattenedWithItsDepths(): void {
		$this->index = [
			$this->task('parent'),
			$this->task('child', parentUid: 'parent'),
			$this->task('grandchild', parentUid: 'child'),
			$this->task('other'),
		];

		$rows = $this->plan->plan(self::USER, self::REF)->rows;

		self::assertSame(['parent', 'child', 'grandchild', 'other'], $this->uids($rows));
		self::assertSame([0, 1, 2, 0], array_map(static fn (PlanRow $row): int => $row->depth, $rows));
	}

	/**
	 * The index reads tasks by due date, which in every engine puts the
	 * undated ones first — so a plan opened at the top showed rows of "No
	 * dates" before anything scheduled. A plan is read as a plan: earliest
	 * first, with whatever has no dates at the bottom (§15.13).
	 */
	public function testABranchComesInTheOrderItStartsWithTheUndatedLast(): void {
		$this->index = [
			$this->task('nothing-planned'),
			$this->task('later', startAt: '2026-11-01', dueAt: '2026-11-05'),
			$this->task('sooner', startAt: '2026-10-01', dueAt: '2026-10-05'),
			$this->task('nothing-planned-either'),
		];

		$rows = $this->plan->plan(self::USER, self::REF)->rows;

		self::assertSame(['sooner', 'later', 'nothing-planned', 'nothing-planned-either'], $this->uids($rows));
	}

	/** A branch sorts on the earliest date anywhere in it, children included. */
	public function testABranchSortsOnWhenItsChildrenStart(): void {
		$this->index = [
			$this->task('phase-two', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('late-work', parentUid: 'phase-two', startAt: '2026-12-01', dueAt: '2026-12-05'),
			$this->task('phase-one', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('early-work', parentUid: 'phase-one', startAt: '2026-10-01', dueAt: '2026-10-05'),
		];

		$rows = $this->plan->plan(self::USER, self::REF)->rows;

		self::assertSame(['phase-one', 'early-work', 'phase-two', 'late-work'], $this->uids($rows));
	}

	/**
	 * A subproject has no dates of its own and is never dragged, but it is a
	 * row: a container with a span is exactly what a summary bar is for (§6).
	 */
	public function testASubprojectIsASummaryRowSpanningItsChildren(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('one', parentUid: 'container', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task('two', parentUid: 'container', startAt: '2026-10-01', dueAt: '2026-10-03'),
		];

		$rows = $this->plan->plan(self::USER, self::REF)->rows;
		$summary = $this->row($rows, 'container');

		self::assertTrue($summary->isSummary);
		self::assertNotNull($summary->bar);
		self::assertSame($this->day('2026-10-01'), $summary->bar->startAt);
		self::assertSame($this->day('2026-10-07'), $summary->bar->endAt);
	}

	/** A parent with dates of its own draws its own bar, which can be dragged. */
	public function testAParentWithItsOwnDatesIsNotASummary(): void {
		$this->index = [
			$this->task('parent', startAt: '2026-10-01', dueAt: '2026-10-02'),
			$this->task('child', parentUid: 'parent', startAt: '2026-10-05', dueAt: '2026-10-06'),
		];

		$parent = $this->row($this->plan->plan(self::USER, self::REF)->rows, 'parent');

		self::assertFalse($parent->isSummary);
		self::assertNotNull($parent->bar);
		self::assertSame($this->day('2026-10-03'), $parent->bar->endAt);
	}

	/** A summary spans the whole branch, not only its direct children. */
	public function testASummarySpansTheWholeBranchBeneathIt(): void {
		$this->index = [
			$this->task('top', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('middle', parentUid: 'top', startAt: '2026-10-01', dueAt: '2026-10-02'),
			$this->task('deep', parentUid: 'middle', startAt: '2026-10-20', dueAt: '2026-10-21'),
		];

		$top = $this->row($this->plan->plan(self::USER, self::REF)->rows, 'top');

		self::assertNotNull($top->bar);
		self::assertSame($this->day('2026-10-22'), $top->bar->endAt);
	}

	public function testAPercentageSomebodySetWinsOverTheRatioOfFinishedChildren(): void {
		$this->index = [
			$this->task('parent', percent: 10),
			$this->task('done', parentUid: 'parent', status: 'COMPLETED'),
			$this->task('open', parentUid: 'parent'),
		];

		self::assertSame(10, $this->row($this->plan->plan(self::USER, self::REF)->rows, 'parent')->progress);
	}

	public function testAParentWithoutOneShowsHowMuchOfItsBranchIsFinished(): void {
		$this->index = [
			$this->task('parent'),
			$this->task('done', parentUid: 'parent', status: 'COMPLETED'),
			$this->task('dropped', parentUid: 'parent', status: 'CANCELLED'),
			$this->task('open', parentUid: 'parent'),
			$this->task('also-open', parentUid: 'parent'),
		];

		self::assertSame(50, $this->row($this->plan->plan(self::USER, self::REF)->rows, 'parent')->progress);
	}

	/**
	 * Almost nothing in a real list has dates, so a task without them is a row
	 * with nothing to drag rather than a task missing from the plan.
	 */
	public function testATaskWithNoDatesIsStillARow(): void {
		$this->index = [$this->task('undated')];

		$row = $this->row($this->plan->plan(self::USER, self::REF)->rows, 'undated');

		self::assertNull($row->bar);
		self::assertFalse($row->isSummary);
	}

	public function testADragWritesTheDatesAndNothingElse(): void {
		$this->index = [$this->task('a', summary: 'Keep me', percent: 40, startAt: '2026-10-01', dueAt: '2026-10-02')];

		$this->plan->move(
			self::USER,
			self::REF,
			'a',
			$this->day('2026-10-05'),
			true,
			$this->day('2026-10-07'),
			true,
			'etag-from-the-page',
		);

		self::assertCount(1, $this->written);
		$write = $this->written[0]['write'];
		self::assertSame($this->day('2026-10-05'), $write->startAt);
		self::assertSame($this->day('2026-10-07'), $write->dueAt);
		self::assertTrue($write->startIsDate);
		self::assertSame('Keep me', $write->summary);
		self::assertSame(40, $write->percent);
		// A drag is not a link edit, so the dependencies are left alone (§7).
		self::assertNull($write->links);
		self::assertSame('etag-from-the-page', $this->written[0]['etag']);
	}

	/** The inclusive `DUE` an all-day bar is written back as (§8.4). */
	public function testAnAllDayBarIsWrittenBackAsTheLastDayItCovers(): void {
		$this->index = [$this->task('a', startAt: '2026-10-01', dueAt: '2026-10-02')];

		$this->plan->move(
			self::USER,
			self::REF,
			'a',
			$this->day('2026-10-05'),
			true,
			$this->day('2026-10-09'),
			true,
		);

		self::assertSame($this->day('2026-10-09'), $this->written[0]['write']->dueAt);
	}

	public function testCascadeModeWritesTheDependentsToo(): void {
		$this->settingsJson = (new PlanSettings(PlanSettings::MODE_CASCADE, []))->toJson(null);
		$this->index = [
			$this->task('a', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task('b', startAt: '2026-10-07', dueAt: '2026-10-08'),
		];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART')];

		$outcome = $this->plan->move(
			self::USER,
			self::REF,
			'a',
			$this->day('2026-10-12'),
			true,
			$this->day('2026-10-13'),
			true,
		);

		self::assertSame(['a', 'b'], array_column($this->written, 'uid'));
		self::assertSame($this->day('2026-10-14'), $this->written[1]['write']->startAt);
		// The page never saw the dependent, so its own etag is used.
		self::assertNull($this->written[1]['etag']);
		self::assertTrue($outcome->cascaded);
	}

	/**
	 * In warn mode the move happens and the broken link is reported: that is
	 * what the warning is (§15.3).
	 */
	public function testWarnModeWritesOneTaskAndReportsTheConflict(): void {
		$this->index = [
			$this->task('a', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task('b', startAt: '2026-10-07', dueAt: '2026-10-08'),
		];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART')];

		$outcome = $this->plan->move(
			self::USER,
			self::REF,
			'a',
			$this->day('2026-10-12'),
			true,
			$this->day('2026-10-13'),
			true,
		);

		self::assertSame(['a'], array_column($this->written, 'uid'));
		self::assertCount(1, $outcome->conflicts);
		self::assertFalse($outcome->cascaded);
	}

	/** The mode the drag previewed is the mode it is written in. */
	public function testADragCanAskForTheOtherModeThanTheProjectsOwn(): void {
		$this->index = [
			$this->task('a', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task('b', startAt: '2026-10-07', dueAt: '2026-10-08'),
		];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART')];

		$this->plan->move(
			self::USER,
			self::REF,
			'a',
			$this->day('2026-10-12'),
			true,
			$this->day('2026-10-13'),
			true,
			null,
			PlanSettings::MODE_CASCADE,
		);

		self::assertSame(['a', 'b'], array_column($this->written, 'uid'));
	}

	/**
	 * A move sends back the rows it changed *and* the ancestors above them,
	 * because a summary bar is the span of its children and has just moved too.
	 */
	public function testAMoveSendsBackTheSummaryRowsItStretched(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('a', parentUid: 'container', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task('elsewhere', startAt: '2026-10-01', dueAt: '2026-10-02'),
		];

		$outcome = $this->plan->move(
			self::USER,
			self::REF,
			'a',
			$this->day('2026-10-20'),
			true,
			$this->day('2026-10-21'),
			true,
		);

		self::assertSame(['container', 'a'], $this->uids($outcome->rows));
		$summary = $this->row($outcome->rows, 'container');
		self::assertNotNull($summary->bar);
		self::assertSame($this->day('2026-10-20'), $summary->bar->startAt);
	}

	/** A summary bar is derived, so there is nothing to write. */
	public function testASummaryRowCannotBeDragged(): void {
		$this->index = [
			$this->task('container', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task('a', parentUid: 'container', startAt: '2026-10-05', dueAt: '2026-10-06'),
		];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('summary_not_movable');

		$this->plan->move(self::USER, self::REF, 'container', $this->day('2026-10-01'), true, $this->day('2026-10-02'), true);
	}

	public function testABarThatEndsBeforeItStartsIsRefused(): void {
		$this->index = [$this->task('a', startAt: '2026-10-05', dueAt: '2026-10-06')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('invalid_dates');

		$this->plan->move(self::USER, self::REF, 'a', $this->day('2026-10-09'), true, $this->day('2026-10-05'), true);
	}

	public function testADragOnAListSharedReadOnlyIsRefused(): void {
		$this->calendars = $this->createMock(CalDavGateway::class);
		$this->calendars->method('findTaskCalendarByRef')->willReturn($this->calendar(writable: false));
		$this->plan = $this->serviceWith($this->calendars);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('project_read_only');

		$this->plan->move(self::USER, self::REF, 'a', $this->day('2026-10-05'), true, $this->day('2026-10-06'), true);
	}

	public function testDraggingATaskThatIsNotOnThePlanIsRefused(): void {
		$this->index = [$this->task('a')];

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('task_not_found');

		$this->plan->move(self::USER, self::REF, 'ghost', $this->day('2026-10-05'), true, $this->day('2026-10-06'), true);
	}

	/**
	 * A temporal link is declared by the predecessor, so that is the task
	 * whose VTODO is written (§7, RFC 9253 §6.2).
	 */
	public function testALinkIsWrittenOnThePredecessor(): void {
		$this->index = [$this->task('a'), $this->task('b')];

		$this->plan->link(self::USER, self::REF, 'a', 'b', 'FINISHTOSTART', 86400);

		self::assertSame(['a'], array_column($this->written, 'uid'));
		$links = $this->written[0]['write']->links;
		self::assertNotNull($links);
		self::assertCount(1, $links);
		self::assertSame('b', $links[0]->successorUid);
		self::assertSame(86400, $links[0]->gapSeconds);
	}

	/** `DEPENDS-ON` is declared by the task that waits (RFC 9253 §5). */
	public function testADependsOnLinkIsWrittenOnTheDependentTask(): void {
		$this->index = [$this->task('a'), $this->task('b')];

		$this->plan->link(self::USER, self::REF, 'a', 'b', ParsedLink::RELTYPE_DEPENDS_ON);

		self::assertSame(['b'], array_column($this->written, 'uid'));
	}

	public function testANewLinkKeepsTheOnesTheTaskAlreadyDeclared(): void {
		$this->index = [$this->task('a'), $this->task('b'), $this->task('c')];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART')];

		$this->plan->link(self::USER, self::REF, 'a', 'c', 'STARTTOSTART');

		$links = $this->written[0]['write']->links;
		self::assertNotNull($links);
		self::assertCount(2, $links);
	}

	public function testALinkToItselfIsRefused(): void {
		$this->index = [$this->task('a')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('link_to_itself');

		$this->plan->link(self::USER, self::REF, 'a', 'a', 'FINISHTOSTART');
	}

	public function testARelationshipChronosDoesNotScheduleWithIsRefused(): void {
		$this->index = [$this->task('a'), $this->task('b')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('unknown_reltype');

		$this->plan->link(self::USER, self::REF, 'a', 'b', 'SIBLING');
	}

	public function testTheSameLinkTwiceIsRefused(): void {
		$this->index = [$this->task('a'), $this->task('b')];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('link_exists');

		$this->plan->link(self::USER, self::REF, 'a', 'b', 'STARTTOSTART');
	}

	/** No dates can satisfy a loop, so it is refused rather than reported. */
	public function testALinkThatWouldCloseALoopIsRefused(): void {
		$this->index = [$this->task('a'), $this->task('b'), $this->task('c')];
		$this->stored = [
			$this->link('a', 'a', 'b', 'FINISHTOSTART'),
			$this->link('b', 'b', 'c', 'FINISHTOSTART'),
		];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('link_cycle');

		$this->plan->link(self::USER, self::REF, 'c', 'a', 'FINISHTOSTART');
	}

	public function testAGapOfMoreThanAYearIsRefused(): void {
		$this->index = [$this->task('a'), $this->task('b')];

		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('gap_too_large');

		$this->plan->link(self::USER, self::REF, 'a', 'b', 'FINISHTOSTART', 400 * 86400);
	}

	public function testUnlinkingRewritesTheDeclaringTaskWithoutThatLink(): void {
		$this->index = [$this->task('a'), $this->task('b'), $this->task('c')];
		$this->stored = [
			$this->link('a', 'a', 'b', 'FINISHTOSTART', id: 7),
			$this->link('a', 'a', 'c', 'STARTTOSTART', id: 8),
		];

		$this->plan->unlink(self::USER, self::REF, 'a', 'b');

		self::assertSame(['a'], array_column($this->written, 'uid'));
		$links = $this->written[0]['write']->links;
		self::assertNotNull($links);
		self::assertCount(1, $links);
		self::assertSame('c', $links[0]->successorUid);
	}

	/**
	 * A link is named by the two tasks it joins, not by its row id. The index
	 * *replaces* a task's link rows whenever that task is written, so every
	 * id changes the moment a bar is dragged — and deleting by id then fails
	 * with `link_not_found` on a link that is plainly there, which is what the
	 * live check caught on a real instance (D42).
	 */
	public function testALinkCanStillBeRemovedAfterItsRowsHaveBeenRewritten(): void {
		$this->index = [$this->task('a'), $this->task('b')];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART', id: 7)];

		// What a write of task `a` does to the index: same link, new row.
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART', id: 9814)];

		$this->plan->unlink(self::USER, self::REF, 'a', 'b');

		self::assertSame(['a'], array_column($this->written, 'uid'));
		self::assertSame([], $this->written[0]['write']->links);
	}

	public function testUnlinkingSomethingThatIsNotThereIsRefused(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('link_not_found');

		$this->plan->unlink(self::USER, self::REF, 'a', 'nothing-like-this');
	}

	public function testTheProjectsSettingsAreStoredBesideTheOtherModulesOwn(): void {
		$this->settingsJson = '{"somebody-else":{"kept":true}}';
		$stored = null;
		$this->projects->method('writeSettings')->willReturnCallback(
			function (string $userId, string $ref, ?string $json) use (&$stored) {
				$stored = $json;

				return new \OCA\Chronos\Projects\Db\Project();
			},
		);

		$settings = $this->plan->configure(self::USER, self::REF, PlanSettings::MODE_CASCADE, [1, 2, 3, 4, 5]);

		self::assertSame(PlanSettings::MODE_CASCADE, $settings->mode);
		self::assertSame([1, 2, 3, 4, 5], $settings->workingDays);
		self::assertIsString($stored);
		self::assertStringContainsString('somebody-else', $stored);
		self::assertStringContainsString('cascade', $stored);
	}

	public function testAnUnknownRescheduleModeIsRefused(): void {
		$this->expectException(ValidationFailedException::class);
		$this->expectExceptionMessage('unknown_mode');

		$this->plan->configure(self::USER, self::REF, 'whatever', []);
	}

	public function testThePlanSaysWhatTheDatesAlreadyBreak(): void {
		$this->index = [
			$this->task('a', startAt: '2026-10-05', dueAt: '2026-10-09'),
			$this->task('b', startAt: '2026-10-06', dueAt: '2026-10-07'),
		];
		$this->stored = [$this->link('a', 'a', 'b', 'FINISHTOSTART')];

		$view = $this->plan->plan(self::USER, self::REF);

		self::assertCount(1, $view->conflicts);
		self::assertCount(1, $view->links);
		self::assertSame(PlanSettings::MODE_WARN, $view->settings->mode);
	}

	private function serviceWith(CalDavGateway&MockObject $calendars): PlanService {
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));

		return new PlanService(
			$calendars,
			$this->tasks,
			$this->links,
			$this->taskService,
			$this->projects,
			$zone,
		);
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

	private function task(
		string $uid,
		?string $parentUid = null,
		?string $startAt = null,
		?string $dueAt = null,
		string $type = ParsedTask::TYPE_TASK,
		?string $status = 'NEEDS-ACTION',
		?int $percent = null,
		string $summary = 'A task',
	): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setSummary($summary);
		$task->setEtag('etag-' . $uid);
		$task->setCalendarId(self::CALENDAR_ID);
		$task->setType($type);
		$task->setParentUid($parentUid);
		$task->setVtodoStatus($status);
		$task->setPercent($percent);
		$task->setStartAt($startAt === null ? null : $this->day($startAt));
		$task->setDueAt($dueAt === null ? null : $this->day($dueAt));
		$task->setStartIsDate(true);
		$task->setDueIsDate(true);

		return $task;
	}

	private function link(
		string $sourceUid,
		string $predecessorUid,
		string $successorUid,
		string $reltype,
		int $gap = 0,
		int $id = 1,
	): TaskLink {
		$link = new TaskLink();
		$link->setId($id);
		$link->setSourceUid($sourceUid);
		$link->setPredecessorUid($predecessorUid);
		$link->setSuccessorUid($successorUid);
		$link->setReltype($reltype);
		$link->setGapSeconds($gap);

		return $link;
	}

	/**
	 * The index as it is after a write, so a second read of the plan — the one
	 * a move makes to work out the rows it changed — sees the new dates.
	 */
	private function remember(string $uid, TaskWrite $write): Task {
		foreach ($this->index as $task) {
			if ($task->getUid() !== $uid) {
				continue;
			}
			$task->setStartAt($write->startAt);
			$task->setDueAt($write->dueAt);
			$task->setStartIsDate($write->startIsDate);
			$task->setDueIsDate($write->dueIsDate);

			return $task;
		}

		return $this->task($uid);
	}

	/**
	 * @param list<PlanRow> $rows
	 */
	private function row(array $rows, string $uid): PlanRow {
		foreach ($rows as $row) {
			if ($row->task->getUid() === $uid) {
				return $row;
			}
		}

		self::fail('no row for ' . $uid);
	}

	/**
	 * @param list<PlanRow> $rows
	 * @return list<string>
	 */
	private function uids(array $rows): array {
		return array_map(static fn (PlanRow $row): string => $row->task->getUid(), $rows);
	}

	private function day(string $date): int {
		return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
	}
}
