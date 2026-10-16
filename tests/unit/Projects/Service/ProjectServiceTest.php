<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Projects\Service;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\Db\AreaMapper;
use OCA\Chronos\Projects\Db\Project;
use OCA\Chronos\Projects\Db\ProjectMapper;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProjectServiceTest extends TestCase {

	private const USER = 'alice';
	private const WORK = 'principals/users/alice/work';
	private const HOME = 'principals/users/alice/home';

	private CalDavGateway&MockObject $gateway;
	private AreaMapper&MockObject $areas;
	private ProjectMapper&MockObject $projects;
	private IDBConnection&MockObject $db;
	private ProjectService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->gateway = $this->createMock(CalDavGateway::class);
		$this->areas = $this->createMock(AreaMapper::class);
		$this->projects = $this->createMock(ProjectMapper::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->service = new ProjectService(
			$this->gateway,
			$this->areas,
			$this->projects,
			$this->db,
		);

		$this->gateway->method('findTaskCalendarByRef')
			->willReturnCallback(fn (string $userId, string $ref): ?CalendarRef => match ($ref) {
				self::WORK => $this->calendar(1, 'work', self::WORK, 'Work'),
				self::HOME => $this->calendar(2, 'home', self::HOME, 'Home'),
				default => null,
			});
	}

	public function testConfiguringAProjectForTheFirstTimeCreatesItsRow(): void {
		$this->projects->method('findByUri')->willReturn(null);
		$this->areas->method('find')->willReturn($this->area(5, 'Work'));

		$inserted = null;
		$this->projects->expects(self::once())
			->method('insert')
			->willReturnCallback(function (Project $project) use (&$inserted): Project {
				$project->setId(11);
				$inserted = $project;
				return $project;
			});
		$this->projects->expects(self::never())->method('update');

		$view = $this->service->configure(self::USER, self::WORK, 5, null, false, 0);

		self::assertInstanceOf(Project::class, $inserted);
		self::assertSame(self::USER, $inserted->getUserId());
		self::assertSame(self::WORK, $inserted->getProjectUri());
		self::assertSame(5, $view->areaId());
		self::assertSame(self::WORK, $view->ref());
	}

	public function testAProjectWithNothingLeftToSayLosesItsRow(): void {
		$stored = $this->project(11, self::WORK, areaId: 5);
		$this->projects->method('findByUri')->willReturn($stored);
		$this->projects->method('findChildren')->willReturn([]);

		$this->projects->expects(self::once())->method('delete')->with($stored);
		$this->projects->expects(self::never())->method('update');
		$this->projects->expects(self::never())->method('insert');

		$view = $this->service->configure(self::USER, self::WORK, null, null, false, 0);

		// The project is still a project; it simply has no settings any more.
		self::assertSame(self::WORK, $view->ref());
		self::assertNull($view->areaId());
		self::assertFalse($view->isArchived());
	}

	public function testARowThatIsStillAParentIsKeptEvenWithDefaultSettings(): void {
		$stored = $this->project(11, self::WORK, areaId: 5);
		$this->projects->method('findByUri')->willReturn($stored);
		$this->projects->method('findChildren')->willReturn([$this->project(12, self::HOME, parentId: 11)]);

		$this->projects->expects(self::never())->method('delete');
		$this->projects->expects(self::once())->method('update')->willReturnArgument(0);

		$this->service->configure(self::USER, self::WORK, null, null, false, 0);
	}

	public function testAnAreaThatIsNotTheUsersIsRefused(): void {
		$this->projects->method('findByUri')->willReturn(null);
		$this->areas->method('find')->willReturn(null);

		$this->expectException(ValidationFailedException::class);
		$this->service->configure(self::USER, self::WORK, 404, null, false, 0);
	}

	public function testAProjectThatIsNotVisibleIsNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->service->configure(self::USER, 'principals/users/bob/secret', null, null, false, 0);
	}

	public function testAProjectCannotBeItsOwnParent(): void {
		$this->projects->method('findByUri')->willReturn(null);

		$this->expectException(ValidationFailedException::class);
		$this->service->configure(self::USER, self::WORK, null, self::WORK, false, 0);
	}

	public function testAParentLinkThatWouldLoopIsRefused(): void {
		$work = $this->project(11, self::WORK);
		// Home already hangs under Work, so making Home the parent of Work
		// would close the circle.
		$home = $this->project(12, self::HOME, parentId: 11);

		$this->projects->method('findByUri')->willReturnCallback(
			fn (string $userId, string $ref): ?Project => match ($ref) {
				self::WORK => $work,
				self::HOME => $home,
				default => null,
			},
		);
		$this->projects->method('find')->willReturnCallback(
			fn (string $userId, int $id): ?Project => match ($id) {
				11 => $work,
				12 => $home,
				default => null,
			},
		);

		$this->expectException(ValidationFailedException::class);
		$this->service->configure(self::USER, self::WORK, null, self::HOME, false, 0);
	}

	public function testListingMergesSettingsAndSkipsRowsWhoseListIsGone(): void {
		$this->gateway->method('getTaskCalendars')->willReturn([
			$this->calendar(1, 'work', self::WORK, 'Work'),
			$this->calendar(2, 'home', self::HOME, 'Home'),
		]);
		$this->projects->method('findAll')->willReturn([
			$this->project(11, self::WORK, areaId: 5),
			// A list that was deleted or unshared since it was configured.
			$this->project(13, 'principals/users/alice/gone', areaId: 5),
			$this->project(12, self::HOME, parentId: 11),
		]);

		$views = $this->service->listProjects(self::USER);

		self::assertCount(2, $views);
		$byRef = [];
		foreach ($views as $view) {
			$byRef[$view->ref()] = $view;
		}
		self::assertSame(5, $byRef[self::WORK]->areaId());
		self::assertSame(self::WORK, $byRef[self::HOME]->jsonSerialize()['parentUri']);
		self::assertNull($byRef[self::WORK]->jsonSerialize()['parentUri']);
	}

	public function testArchivedProjectsCanBeLeftOut(): void {
		$this->gateway->method('getTaskCalendars')->willReturn([
			$this->calendar(1, 'work', self::WORK, 'Work'),
			$this->calendar(2, 'home', self::HOME, 'Home'),
		]);
		$this->projects->method('findAll')->willReturn([
			$this->project(11, self::WORK, archived: true),
		]);

		$views = $this->service->listProjects(self::USER, false);

		self::assertCount(1, $views);
		self::assertSame(self::HOME, $views[0]->ref());
	}

	public function testDeletingAnAreaKeepsItsProjectsAndRunsInOneTransaction(): void {
		$area = $this->area(5, 'Work');
		$this->areas->method('find')->willReturn($area);

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');
		$this->projects->expects(self::once())->method('clearArea')->with(self::USER, 5);
		$this->areas->expects(self::once())->method('delete')->with($area);

		$this->service->deleteArea(self::USER, 5);
	}

	public function testDeletingAnAreaThatIsNotTheUsersIsNotFound(): void {
		$this->areas->method('find')->willReturn(null);

		$this->expectException(NotFoundException::class);
		$this->service->deleteArea(self::USER, 5);
	}

	public function testAnAreaNeedsAName(): void {
		$this->expectException(ValidationFailedException::class);
		$this->service->createArea(self::USER, '   ', null);
	}

	public function testAnAreaColourMustBeAHexTriple(): void {
		$this->expectException(ValidationFailedException::class);
		$this->service->createArea(self::USER, 'Work', 'rebeccapurple');
	}

	public function testAnAreaIsCreatedLastWithANormalisedColour(): void {
		$this->areas->method('nextSort')->willReturn(3);
		$this->areas->expects(self::once())
			->method('insert')
			->willReturnCallback(static fn (Area $area): Area => $area);

		$area = $this->service->createArea(self::USER, '  Homelab ', '#AABBCC');

		self::assertSame('Homelab', $area->getName());
		self::assertSame('#aabbcc', $area->getColor());
		self::assertSame(3, $area->getSort());
	}

	private function calendar(int $id, string $uri, string $ref, string $name): CalendarRef {
		return new CalendarRef(
			id: $id,
			uri: $uri,
			ref: $ref,
			displayName: $name,
			color: null,
			supportsVTodo: true,
			isShared: false,
			isWritable: true,
		);
	}

	private function area(int $id, string $name): Area {
		$area = new Area();
		$area->setId($id);
		$area->setUserId(self::USER);
		$area->setName($name);

		return $area;
	}

	private function project(
		int $id,
		string $ref,
		?int $areaId = null,
		?int $parentId = null,
		bool $archived = false,
	): Project {
		$project = new Project();
		$project->setId($id);
		$project->setUserId(self::USER);
		$project->setProjectUri($ref);
		$project->setAreaId($areaId);
		$project->setParentProjectId($parentId);
		$project->setArchived($archived);
		$project->setSort(0);

		return $project;
	}
}
