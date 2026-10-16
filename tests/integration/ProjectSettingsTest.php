<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\Db\AreaMapper;
use OCA\Chronos\Projects\Db\Project;
use OCA\Chronos\Projects\Db\ProjectMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Areas and project settings against the real database (§10.2): that the
 * unique key really stops a second settings row for the same project, that
 * deleting an area leaves its projects alone, and that one user cannot read
 * another's.
 *
 * Uses synthetic user ids, so nothing here touches real data.
 */
class ProjectSettingsTest extends TestCase {

	private const USER = 'chronos-itest-projects';
	private const OTHER = 'chronos-itest-projects-2';
	private const WORK = 'principals/users/chronos-itest-projects/work';
	private const HOME = 'principals/users/chronos-itest-projects/home';

	private AreaMapper $areas;
	private ProjectMapper $projects;
	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();

		$this->areas = Server::get(AreaMapper::class);
		$this->projects = Server::get(ProjectMapper::class);
		$this->db = Server::get(IDBConnection::class);

		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testAreasComeBackInTheUsersOrder(): void {
		$second = $this->area('Personal', 1);
		$first = $this->area('Work', 0);

		$all = $this->areas->findAll(self::USER);

		self::assertCount(2, $all);
		self::assertSame($first->getId(), $all[0]->getId());
		self::assertSame($second->getId(), $all[1]->getId());
	}

	public function testANewAreaGoesLast(): void {
		self::assertSame(0, $this->areas->nextSort(self::USER));

		$this->area('Work', 0);
		$this->area('Personal', 4);

		self::assertSame(5, $this->areas->nextSort(self::USER));
	}

	public function testOneUserCannotReadAnothersArea(): void {
		$area = $this->area('Work', 0);

		self::assertNotNull($this->areas->find(self::USER, $area->getId()));
		self::assertNull($this->areas->find(self::OTHER, $area->getId()));
	}

	public function testAProjectHasAtMostOneSettingsRow(): void {
		$this->project(self::WORK);

		$this->expectException(DbException::class);
		$this->project(self::WORK);
	}

	public function testTheSameProjectUriCanBeConfiguredByTwoUsers(): void {
		$this->project(self::WORK);

		$shared = new Project();
		$shared->setUserId(self::OTHER);
		$shared->setProjectUri(self::WORK);
		$this->projects->insert($shared);

		self::assertNotNull($this->projects->findByUri(self::USER, self::WORK));
		self::assertNotNull($this->projects->findByUri(self::OTHER, self::WORK));
	}

	public function testDeletingAnAreaLeavesItsProjectsInNoArea(): void {
		$area = $this->area('Work', 0);
		$work = $this->project(self::WORK, areaId: $area->getId());
		$home = $this->project(self::HOME, areaId: $area->getId());

		$this->projects->clearArea(self::USER, $area->getId());
		$this->areas->delete($area);

		self::assertNull($this->projects->find(self::USER, $work->getId())?->getAreaId());
		self::assertNull($this->projects->find(self::USER, $home->getId())?->getAreaId());
		self::assertNull($this->areas->find(self::USER, $area->getId()));
	}

	public function testAProjectKnowsItsChildren(): void {
		$parent = $this->project(self::WORK);
		$child = $this->project(self::HOME, parentId: $parent->getId());

		$children = $this->projects->findChildren(self::USER, $parent->getId());

		self::assertCount(1, $children);
		self::assertSame($child->getId(), $children[0]->getId());
		self::assertSame([], $this->projects->findChildren(self::USER, $child->getId()));
	}

	public function testAWorkflowCanBeClearedFromEveryProjectThatUsedIt(): void {
		$work = $this->project(self::WORK, workflowId: 77);
		$home = $this->project(self::HOME, workflowId: 78);

		$this->projects->clearWorkflow(self::USER, 77);

		self::assertNull($this->projects->find(self::USER, $work->getId())?->getWorkflowId());
		self::assertSame(78, $this->projects->find(self::USER, $home->getId())?->getWorkflowId());
	}

	private function area(string $name, int $sort): Area {
		$area = new Area();
		$area->setUserId(self::USER);
		$area->setName($name);
		$area->setSort($sort);

		return $this->areas->insert($area);
	}

	private function project(
		string $ref,
		?int $areaId = null,
		?int $parentId = null,
		?int $workflowId = null,
	): Project {
		$project = new Project();
		$project->setUserId(self::USER);
		$project->setProjectUri($ref);
		$project->setAreaId($areaId);
		$project->setParentProjectId($parentId);
		$project->setWorkflowId($workflowId);
		$project->setArchived(false);
		$project->setSort(0);

		return $this->projects->insert($project);
	}

	private function cleanUp(): void {
		foreach (['chronos_projects', 'chronos_areas'] as $table) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete($table)
				->where($delete->expr()->in(
					'user_id',
					$delete->createNamedParameter([self::USER, self::OTHER], IQueryBuilder::PARAM_STR_ARRAY),
				));
			$delete->executeStatement();
		}
	}
}
