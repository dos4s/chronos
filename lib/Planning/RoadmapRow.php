<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * One line of the roadmap: a project, or a subproject inside one (§12.6).
 *
 * A roadmap is the timeline's question asked across every list at once — when
 * does this *project* run, and what has to be true by when — so a row carries
 * a span and the milestones in it rather than a task's own dates. Clicking one
 * opens its timeline, which is where the tasks are.
 */
final class RoadmapRow implements JsonSerializable {

	/**
	 * @param string $ref the project reference, which is what a click opens
	 * @param string|null $parentUid for a subproject, the container it is
	 * @param list<Milestone> $milestones
	 */
	public function __construct(
		public readonly string $ref,
		public readonly string $name,
		public readonly ?string $color,
		public readonly int $depth,
		public readonly ?int $areaId,
		public readonly ?string $parentUid,
		public readonly ?Bar $span,
		public readonly int $tasks,
		public readonly int $open,
		public readonly array $milestones,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'ref' => $this->ref,
			'name' => $this->name,
			'color' => $this->color,
			'depth' => $this->depth,
			'areaId' => $this->areaId,
			'parentUid' => $this->parentUid,
			'span' => $this->span?->jsonSerialize(),
			'tasks' => $this->tasks,
			'open' => $this->open,
			'milestones' => array_map(
				static fn (Milestone $milestone): array => $milestone->jsonSerialize(),
				$this->milestones,
			),
		];
	}
}
