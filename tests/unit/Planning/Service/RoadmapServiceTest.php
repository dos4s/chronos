<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Planning\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Planning\RoadmapRow;
use OCA\Chronos\Planning\Service\RoadmapService;
use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\Db\Project;
use OCA\Chronos\Projects\ProjectView;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every project at once (§12.6): a line each, grouped by area, with what is
 * due in them marked on it.
 */
class RoadmapServiceTest extends TestCase {

	private const USER = 'alice';

	private TaskMapper&MockObject $tasks;
	private ProjectService&MockObject $projects;
	private RoadmapService $roadmap;

	/** @var list<Task> */
	private array $index = [];

	/** @var list<ProjectView> */
	private array $lists = [];

	/** @var list<Area> */
	private array $areas = [];

	protected function setUp(): void {
		parent::setUp();

		$this->tasks = $this->createMock(TaskMapper::class);
		$this->projects = $this->createMock(ProjectService::class);

		$this->tasks->method('findForCalendars')->willReturnCallback(fn (): array => $this->index);
		$this->projects->method('listProjects')->willReturnCallback(fn (): array => $this->lists);
		$this->projects->method('listAreas')->willReturnCallback(fn (): array => $this->areas);

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));

		$this->roadmap = new RoadmapService($this->tasks, $this->projects, $zone);
	}

	public function testEachProjectIsALineSpanningEverythingInIt(): void {
		$this->lists = [$this->project(1, 'work', 'Work')];
		$this->index = [
			$this->task(1, 'a', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task(1, 'b', startAt: '2026-10-20', dueAt: '2026-10-21'),
		];

		$rows = $this->roadmap->roadmap(self::USER)->rows;

		self::assertCount(1, $rows);
		self::assertNotNull($rows[0]->span);
		self::assertSame($this->day('2026-10-05'), $rows[0]->span->startAt);
		self::assertSame($this->day('2026-10-22'), $rows[0]->span->endAt);
		self::assertSame(2, $rows[0]->tasks);
		self::assertSame(2, $rows[0]->open);
	}

	/** A subproject is how a big list is divided, so it is a line of its own. */
	public function testASubprojectIsALineBeneathItsProject(): void {
		$this->lists = [$this->project(1, 'work', 'Work')];
		$this->index = [
			$this->task(1, 'phase', summary: 'Phase one', type: ParsedTask::TYPE_SUBPROJECT),
			$this->task(1, 'inside', parentUid: 'phase', startAt: '2026-10-05', dueAt: '2026-10-09'),
			$this->task(1, 'deeper', parentUid: 'inside', startAt: '2026-10-10', dueAt: '2026-10-12'),
			$this->task(1, 'elsewhere', startAt: '2026-11-01', dueAt: '2026-11-02'),
		];

		$rows = $this->roadmap->roadmap(self::USER)->rows;

		self::assertSame(['Work', 'Phase one'], array_map(static fn (RoadmapRow $row): string => $row->name, $rows));
		self::assertSame([0, 1], array_map(static fn (RoadmapRow $row): int => $row->depth, $rows));
		// The subproject spans its own branch, not the whole list.
		self::assertNotNull($rows[1]->span);
		self::assertSame($this->day('2026-10-05'), $rows[1]->span->startAt);
		self::assertSame($this->day('2026-10-13'), $rows[1]->span->endAt);
		self::assertSame('phase', $rows[1]->parentUid);
	}

	public function testAMilestoneIsAMarkOnItsProjectsLine(): void {
		$this->lists = [$this->project(1, 'work', 'Work')];
		$this->index = [
			$this->task(1, 'ship', summary: 'Ship it', type: ParsedTask::TYPE_MILESTONE, dueAt: '2026-12-01'),
			$this->task(1, 'early', summary: 'Alpha', type: ParsedTask::TYPE_MILESTONE, dueAt: '2026-11-01'),
			$this->task(1, 'done', type: ParsedTask::TYPE_MILESTONE, dueAt: '2026-10-01', status: 'COMPLETED'),
		];

		$milestones = $this->roadmap->roadmap(self::USER)->rows[0]->milestones;

		// In date order, whatever order the list came in.
		self::assertSame(['done', 'early', 'ship'], array_column(array_map(
			static fn ($milestone): array => ['uid' => $milestone->uid],
			$milestones,
		), 'uid'));
		self::assertTrue($milestones[0]->done);
		self::assertFalse($milestones[1]->done);
	}

	/** A milestone with no date is not a date anything has to be true by. */
	public function testAMilestoneWithoutADateIsNotOnTheRoadmap(): void {
		$this->lists = [$this->project(1, 'work', 'Work')];
		$this->index = [$this->task(1, 'someday', type: ParsedTask::TYPE_MILESTONE)];

		self::assertSame([], $this->roadmap->roadmap(self::USER)->rows[0]->milestones);
	}

	public function testAProjectWithNothingInItIsStillALine(): void {
		$this->lists = [$this->project(1, 'empty', 'Empty')];

		$rows = $this->roadmap->roadmap(self::USER)->rows;

		self::assertCount(1, $rows);
		self::assertNull($rows[0]->span);
		self::assertSame(0, $rows[0]->tasks);
	}

	public function testProjectsComeInAreaOrderWithTheUnfiledOnesLast(): void {
		$personal = $this->area(1, 'Personal');
		$work = $this->area(2, 'Work');
		$this->areas = [$personal, $work];
		$this->lists = [
			$this->project(1, 'unfiled', 'Unfiled'),
			$this->project(2, 'job', 'Job', areaId: 2),
			$this->project(3, 'home', 'Home', areaId: 1),
		];

		$view = $this->roadmap->roadmap(self::USER);

		self::assertSame(
			['Home', 'Job', 'Unfiled'],
			array_map(static fn (RoadmapRow $row): string => $row->name, $view->rows),
		);
		// The last group is everything nobody has filed.
		self::assertSame([1, 2, null], array_column($view->areas, 'id'));
	}

	public function testTheWholeRoadmapSpansEveryProjectOnIt(): void {
		$this->lists = [$this->project(1, 'work', 'Work'), $this->project(2, 'home', 'Home')];
		$this->index = [
			$this->task(1, 'a', startAt: '2026-10-05', dueAt: '2026-10-06'),
			$this->task(2, 'b', startAt: '2026-11-20', dueAt: '2026-11-21'),
		];

		$json = $this->roadmap->roadmap(self::USER)->jsonSerialize();

		self::assertSame($this->day('2026-10-05'), $json['from']);
		self::assertSame($this->day('2026-11-22'), $json['to']);
	}

	private function project(
		int $calendarId,
		string $uri,
		string $name,
		?int $areaId = null,
	): ProjectView {
		$settings = null;
		if ($areaId !== null) {
			$settings = new Project();
			$settings->setAreaId($areaId);
		}

		return new ProjectView(
			new CalendarRef(
				$calendarId,
				$uri,
				'principals/users/alice/' . $uri,
				$name,
				'#0082c9',
				true,
				false,
				true,
			),
			$settings,
			null,
		);
	}

	private function area(int $id, string $name): Area {
		$area = new Area();
		$area->setId($id);
		$area->setName($name);

		return $area;
	}

	private function task(
		int $calendarId,
		string $uid,
		?string $startAt = null,
		?string $dueAt = null,
		string $type = ParsedTask::TYPE_TASK,
		?string $parentUid = null,
		?string $status = 'NEEDS-ACTION',
		string $summary = 'A task',
	): Task {
		$task = new Task();
		$task->setUid($uid);
		$task->setSummary($summary);
		$task->setCalendarId($calendarId);
		$task->setType($type);
		$task->setParentUid($parentUid);
		$task->setVtodoStatus($status);
		$task->setStartAt($startAt === null ? null : $this->day($startAt));
		$task->setDueAt($dueAt === null ? null : $this->day($dueAt));
		$task->setStartIsDate(true);
		$task->setDueIsDate(true);

		return $task;
	}

	private function day(string $date): int {
		return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
	}
}
