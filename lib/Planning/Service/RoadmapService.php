<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning\Service;

use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Planning\Bar;
use OCA\Chronos\Planning\Milestone;
use OCA\Chronos\Planning\PlanDates;
use OCA\Chronos\Planning\RoadmapRow;
use OCA\Chronos\Planning\RoadmapView;
use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\ProjectView;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\IDateTimeZone;

/**
 * The roadmap: every project at once, and what is due in each (§12.6).
 *
 * The timeline answers "when does this task run"; this answers the same
 * question about the projects, which is a different question with a different
 * shape — a span and the milestones in it, rather than a bar per task. A row
 * is a project or a subproject inside one, grouped by area, and clicking one
 * opens its timeline.
 *
 * It is one request and **one index query** for every list the user can see:
 * fifteen round trips to draw fifteen lines would be fifteen times the work
 * for the same screen (§16).
 */
class RoadmapService {

	public function __construct(
		private TaskMapper $tasks,
		private ProjectService $projects,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	public function roadmap(string $userId, bool $includeArchived = false): RoadmapView {
		$projects = $this->projects->listProjects($userId, $includeArchived);
		$dates = new PlanDates($this->dateTimeZone->getTimeZone());

		$byCalendar = [];
		foreach ($projects as $project) {
			$byCalendar[$project->calendar->id] = $project;
		}

		$tasksByCalendar = [];
		foreach ($this->tasks->findForCalendars(array_keys($byCalendar)) as $task) {
			$tasksByCalendar[$task->getCalendarId()][] = $task;
		}

		$rows = [];
		foreach ($this->inAreaOrder($userId, $projects) as $project) {
			$own = $tasksByCalendar[$project->calendar->id] ?? [];
			$rows[] = $this->projectRow($project, $own, $dates);

			// A subproject is a line of its own: it is how a big list is
			// divided, and the roadmap is where that division is visible (§6).
			foreach ($own as $task) {
				if ($task->getType() !== ParsedTask::TYPE_SUBPROJECT) {
					continue;
				}
				$rows[] = $this->subprojectRow($project, $task, $own, $dates);
			}
		}

		return new RoadmapView($this->areas($userId), $rows);
	}

	/**
	 * @param list<Task> $tasks
	 */
	private function projectRow(ProjectView $project, array $tasks, PlanDates $dates): RoadmapRow {
		return new RoadmapRow(
			$project->ref(),
			$project->calendar->name(),
			$project->calendar->color,
			0,
			$project->areaId(),
			null,
			$this->span($project->ref(), $tasks, $dates),
			count($tasks),
			$this->openCount($tasks),
			$this->milestones($tasks),
		);
	}

	/**
	 * @param list<Task> $all every task in the list, for the children
	 */
	private function subprojectRow(
		ProjectView $project,
		Task $subproject,
		array $all,
		PlanDates $dates,
	): RoadmapRow {
		$children = $this->descendantsOf($subproject->getUid(), $all);

		return new RoadmapRow(
			$project->ref(),
			$subproject->getSummary() ?? $project->calendar->name(),
			$project->calendar->color,
			1,
			$project->areaId(),
			$subproject->getUid(),
			$this->span($subproject->getUid(), $children, $dates),
			count($children),
			$this->openCount($children),
			$this->milestones($children),
		);
	}

	/**
	 * The span of a set of tasks: from the earliest thing in it to the latest.
	 *
	 * @param list<Task> $tasks
	 */
	private function span(string $uid, array $tasks, PlanDates $dates): ?Bar {
		$bars = [];
		foreach ($tasks as $task) {
			if ($task->getType() === ParsedTask::TYPE_SUBPROJECT) {
				continue;
			}
			$bar = $dates->bar(
				$task->getUid(),
				$task->getStartAt(),
				$task->getStartIsDate(),
				$task->getDueAt(),
				$task->getDueIsDate(),
				$task->getType(),
			);
			if ($bar !== null) {
				$bars[] = $bar;
			}
		}

		return $dates->summary($uid, $bars);
	}

	/**
	 * @param list<Task> $tasks
	 * @return list<Milestone>
	 */
	private function milestones(array $tasks): array {
		$milestones = [];
		foreach ($tasks as $task) {
			$at = $task->getDueAt();
			if ($task->getType() !== ParsedTask::TYPE_MILESTONE || $at === null) {
				continue;
			}
			$milestones[] = new Milestone(
				$task->getUid(),
				$task->getSummary(),
				$at,
				$task->getDueIsDate(),
				in_array($task->getVtodoStatus(), ['COMPLETED', 'CANCELLED'], true),
			);
		}

		usort(
			$milestones,
			static fn (Milestone $a, Milestone $b): int => $a->at <=> $b->at,
		);

		return $milestones;
	}

	/**
	 * Everything under a task, however deep, cycle-guarded like every other
	 * walk over data another client wrote.
	 *
	 * @param list<Task> $all
	 * @return list<Task>
	 */
	private function descendantsOf(string $uid, array $all): array {
		$children = [];
		foreach ($all as $task) {
			$parentUid = $task->getParentUid();
			if ($parentUid !== null) {
				$children[$parentUid][] = $task;
			}
		}

		$found = [];
		$seen = [$uid => true];
		$queue = [$uid];

		while ($queue !== []) {
			$current = array_shift($queue);
			foreach ($children[$current] ?? [] as $child) {
				if (isset($seen[$child->getUid()])) {
					continue;
				}
				$seen[$child->getUid()] = true;
				$found[] = $child;
				$queue[] = $child->getUid();
			}
		}

		return $found;
	}

	/**
	 * @param list<Task> $tasks
	 */
	private function openCount(array $tasks): int {
		$open = 0;
		foreach ($tasks as $task) {
			if ($task->getType() === ParsedTask::TYPE_SUBPROJECT) {
				continue;
			}
			if (!in_array($task->getVtodoStatus(), ['COMPLETED', 'CANCELLED'], true)) {
				$open++;
			}
		}

		return $open;
	}

	/**
	 * The projects in the order the roadmap shows them: by area, in the areas'
	 * own order, with the projects that have no area last.
	 *
	 * @param list<ProjectView> $projects
	 * @return list<ProjectView>
	 */
	private function inAreaOrder(string $userId, array $projects): array {
		$order = [];
		foreach ($this->projects->listAreas($userId) as $index => $area) {
			$order[$area->getId()] = $index;
		}

		$sorted = $projects;
		usort($sorted, static function (ProjectView $a, ProjectView $b) use ($order): int {
			$left = $a->areaId();
			$right = $b->areaId();

			return [
				$left === null ? PHP_INT_MAX : ($order[$left] ?? PHP_INT_MAX - 1),
				$a->sort(),
				strtolower($a->calendar->name()),
			] <=> [
				$right === null ? PHP_INT_MAX : ($order[$right] ?? PHP_INT_MAX - 1),
				$b->sort(),
				strtolower($b->calendar->name()),
			];
		});

		return $sorted;
	}

	/**
	 * @return list<array{id: int|null, name: string|null, color: string|null}>
	 */
	private function areas(string $userId): array {
		$areas = array_map(
			static fn (Area $area): array => [
				'id' => $area->getId(),
				'name' => $area->getName(),
				'color' => $area->getColor(),
			],
			$this->projects->listAreas($userId),
		);

		// The last group is everything nobody has filed, which is where a
		// project starts and where most of them stay.
		$areas[] = ['id' => null, 'name' => null, 'color' => null];

		return $areas;
	}
}
