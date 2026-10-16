<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects;

use JsonSerializable;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Projects\Db\Project;

/**
 * A project as the UI needs it: the task list CalDAV knows about, together with
 * what Chronos has been told about it (§6).
 *
 * Two sources, one object, so a view never has to join a list to its settings
 * itself — and a project nobody has configured still appears, with defaults.
 */
final class ProjectView implements JsonSerializable {

	public function __construct(
		public readonly CalendarRef $calendar,
		public readonly ?Project $settings,
		public readonly ?string $parentRef,
	) {
	}

	public function ref(): string {
		return $this->calendar->ref;
	}

	public function areaId(): ?int {
		return $this->settings?->getAreaId();
	}

	public function isArchived(): bool {
		return $this->settings?->getArchived() ?? false;
	}

	public function sort(): int {
		return $this->settings?->getSort() ?? 0;
	}

	public function workflowId(): ?int {
		return $this->settings?->getWorkflowId();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			// `uri` is the reference the rest of the API speaks in — the same
			// one a time session stores — not the bare calendar URI.
			'uri' => $this->calendar->ref,
			'calendarUri' => $this->calendar->uri,
			'name' => $this->calendar->name(),
			'color' => $this->calendar->color,
			'shared' => $this->calendar->isShared,
			'writable' => $this->calendar->isWritable,
			'areaId' => $this->areaId(),
			'parentUri' => $this->parentRef,
			'workflowId' => $this->workflowId(),
			'archived' => $this->isArchived(),
			'sort' => $this->sort(),
		];
	}
}
