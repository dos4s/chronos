<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects\Service;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\Db\AreaMapper;
use OCA\Chronos\Projects\Db\Project;
use OCA\Chronos\Projects\Db\ProjectMapper;
use OCA\Chronos\Projects\ProjectView;
use OCP\IDBConnection;
use Throwable;

/**
 * Areas and project settings (§6, §10.2).
 *
 * The projects themselves belong to CalDAV: this service never creates or
 * deletes one, it only records what Chronos has been told about it. A project
 * gets a settings row the moment it is configured and loses it again when its
 * settings go back to the defaults, so a row always means something.
 */
class ProjectService {

	private const MAX_NAME = 255;

	public function __construct(
		private CalDavGateway $gateway,
		private AreaMapper $areas,
		private ProjectMapper $projects,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<Area>
	 */
	public function listAreas(string $userId): array {
		return $this->areas->findAll($userId);
	}

	/**
	 * @throws ValidationFailedException
	 */
	public function createArea(string $userId, ?string $name, ?string $color): Area {
		$area = new Area();
		$area->setUserId($userId);
		$area->setName($this->validName($name));
		$area->setColor($this->validColor($color));
		$area->setSort($this->areas->nextSort($userId));

		return $this->areas->insert($area);
	}

	/**
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function updateArea(string $userId, int $id, ?string $name, ?string $color, ?int $sort): Area {
		$area = $this->requireArea($userId, $id);
		$area->setName($this->validName($name));
		$area->setColor($this->validColor($color));
		if ($sort !== null) {
			$area->setSort($sort);
		}

		return $this->areas->update($area);
	}

	/**
	 * Deleting an area deletes no project: the projects in it simply stop
	 * being in an area (§6).
	 *
	 * @throws NotFoundException
	 */
	public function deleteArea(string $userId, int $id): void {
		$area = $this->requireArea($userId, $id);

		$this->db->beginTransaction();
		try {
			$this->projects->clearArea($userId, $id);
			$this->areas->delete($area);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Every task list the user can see, with its settings.
	 *
	 * A settings row whose task list is gone — deleted or unshared — is left
	 * out rather than shown as a project that cannot be opened.
	 *
	 * @return list<ProjectView>
	 */
	public function listProjects(string $userId, bool $includeArchived = true): array {
		$calendars = $this->gateway->getTaskCalendars($userId);
		$rows = $this->indexByUri($this->projects->findAll($userId));
		$refsById = $this->refsById($rows);

		$views = [];
		foreach ($calendars as $calendar) {
			$settings = $rows[$calendar->ref] ?? null;
			$parentId = $settings?->getParentProjectId();
			$view = new ProjectView(
				$calendar,
				$settings,
				$parentId === null ? null : ($refsById[$parentId] ?? null),
			);

			if (!$includeArchived && $view->isArchived()) {
				continue;
			}

			$views[] = $view;
		}

		usort($views, static function (ProjectView $a, ProjectView $b): int {
			return [$a->sort(), strtolower($a->calendar->name())]
				<=> [$b->sort(), strtolower($b->calendar->name())];
		});

		return $views;
	}

	/**
	 * Replaces a project's Chronos settings, the way a task write replaces the
	 * fields Chronos manages: the caller sends the configuration as it should
	 * end up, not only what changed.
	 *
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function configure(
		string $userId,
		string $ref,
		?int $areaId,
		?string $parentRef,
		bool $archived,
		int $sort,
	): ProjectView {
		$calendar = $this->requireCalendar($userId, $ref);

		if ($areaId !== null && $this->areas->find($userId, $areaId) === null) {
			throw new ValidationFailedException('unknown_area');
		}

		$existing = $this->projects->findByUri($userId, $ref);
		$project = $existing ?? $this->newRow($userId, $ref);
		$parent = $this->resolveParent($userId, $ref, $parentRef, $existing?->getId());

		$project->setAreaId($areaId);
		$project->setParentProjectId($parent?->getId());
		$project->setArchived($archived);
		$project->setSort($sort);

		$project = $this->save($userId, $project, $existing?->getId());

		return new ProjectView(
			$calendar,
			$project,
			$parent === null ? null : $parent->getProjectUri(),
		);
	}

	/**
	 * The settings row for a project, created on the spot if it has none.
	 *
	 * Other modules need a row to point at — a workflow, a field, a board — so
	 * this is how they get one without each repeating the lazy creation rule.
	 *
	 * @throws NotFoundException
	 */
	public function requireRow(string $userId, string $ref): Project {
		$this->requireCalendar($userId, $ref);

		$project = $this->projects->findByUri($userId, $ref);
		if ($project !== null) {
			return $project;
		}

		return $this->projects->insert($this->newRow($userId, $ref));
	}

	public function findRow(string $userId, string $ref): ?Project {
		return $this->projects->findByUri($userId, $ref);
	}

	/**
	 * Writes the row, or deletes it when it has nothing left to say.
	 *
	 * A row that is still somebody's parent stays: deleting it would leave a
	 * child pointing at nothing.
	 *
	 * @param int|null $id the stored row's id, or null when there was no row
	 */
	private function save(string $userId, Project $project, ?int $id): Project {
		if ($project->isDefault() && ($id === null || $this->projects->findChildren($userId, $id) === [])) {
			if ($id !== null) {
				$this->projects->delete($project);
			}
			return $this->newRow($userId, $project->getProjectUri());
		}

		return $id === null ? $this->projects->insert($project) : $this->projects->update($project);
	}

	/**
	 * @param int|null $currentId the row being configured, when it has one
	 * @throws NotFoundException|ValidationFailedException
	 */
	private function resolveParent(string $userId, string $ref, ?string $parentRef, ?int $currentId): ?Project {
		if ($parentRef === null || $parentRef === '') {
			return null;
		}

		if ($parentRef === $ref) {
			throw new ValidationFailedException('project_own_parent');
		}

		// A parent must be a project this user can actually see, and needs a
		// row of its own to be pointed at.
		$parent = $this->requireRow($userId, $parentRef);

		if ($currentId !== null && $this->descendsFrom($userId, $parent, $currentId)) {
			throw new ValidationFailedException('project_cycle');
		}

		return $parent;
	}

	/**
	 * Whether `$project` is at or below `$ancestorId`, which is what makes a
	 * parent link a loop.
	 */
	private function descendsFrom(string $userId, Project $project, int $ancestorId): bool {
		$seen = [];
		$current = $project;

		while (true) {
			// Every row on this walk comes from the database, so it has an id.
			$id = $current->getId();
			if (isset($seen[$id])) {
				return false;
			}
			if ($id === $ancestorId) {
				return true;
			}
			$seen[$id] = true;

			$parentId = $current->getParentProjectId();
			if ($parentId === null) {
				return false;
			}

			$parent = $this->projects->find($userId, $parentId);
			if ($parent === null) {
				return false;
			}
			$current = $parent;
		}
	}

	private function newRow(string $userId, string $ref): Project {
		$project = new Project();
		$project->setUserId($userId);
		$project->setProjectUri($ref);

		return $project;
	}

	/**
	 * @param list<Project> $rows
	 * @return array<string, Project>
	 */
	private function indexByUri(array $rows): array {
		$byUri = [];
		foreach ($rows as $row) {
			$byUri[$row->getProjectUri()] = $row;
		}

		return $byUri;
	}

	/**
	 * @param array<string, Project> $rows
	 * @return array<int, string>
	 */
	private function refsById(array $rows): array {
		$byId = [];
		foreach ($rows as $ref => $row) {
			$byId[$row->getId()] = $ref;
		}

		return $byId;
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireCalendar(string $userId, string $ref): CalendarRef {
		$calendar = $this->gateway->findTaskCalendarByRef($userId, $ref);
		if ($calendar === null) {
			throw new NotFoundException('project_not_found');
		}

		return $calendar;
	}

	/**
	 * @throws NotFoundException
	 */
	private function requireArea(string $userId, int $id): Area {
		$area = $this->areas->find($userId, $id);
		if ($area === null) {
			throw new NotFoundException('area_not_found');
		}

		return $area;
	}

	/**
	 * @throws ValidationFailedException
	 */
	private function validName(?string $name): string {
		$name = trim($name ?? '');
		if ($name === '') {
			throw new ValidationFailedException('name_required');
		}
		if (mb_strlen($name) > self::MAX_NAME) {
			throw new ValidationFailedException('name_too_long');
		}

		return $name;
	}

	/**
	 * A colour is a hex triple or nothing; anything else is a typo, and a
	 * stored typo would reach a stylesheet.
	 *
	 * @throws ValidationFailedException
	 */
	private function validColor(?string $color): ?string {
		$color = trim($color ?? '');
		if ($color === '') {
			return null;
		}
		if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new ValidationFailedException('invalid_color');
		}

		return strtolower($color);
	}
}
