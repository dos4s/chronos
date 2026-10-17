<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;
use OCA\Chronos\Core\Dav\CalendarRef;

/**
 * One project's timeline: the rows, the dependencies between them, what the
 * dates already break, and how the project wants a move handled (§12.5).
 *
 * One response, because it is one screen (§16) — and because the browser needs
 * all of it to work out the ghost bars a drag shows before anything is sent.
 */
final class PlanView implements JsonSerializable {

	/**
	 * @param list<PlanRow> $rows in tree order
	 * @param list<Dependency> $links
	 * @param list<Conflict> $conflicts
	 */
	public function __construct(
		public readonly CalendarRef $project,
		public readonly array $rows,
		public readonly array $links,
		public readonly array $conflicts,
		public readonly PlanSettings $settings,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$from = null;
		$to = null;
		foreach ($this->rows as $row) {
			if ($row->bar === null) {
				continue;
			}
			$from = $from === null ? $row->bar->startAt : min($from, $row->bar->startAt);
			$to = $to === null ? $row->bar->endAt : max($to, $row->bar->endAt);
		}

		return [
			'project' => [
				'uri' => $this->project->ref,
				'calendarUri' => $this->project->uri,
				'name' => $this->project->name(),
				'color' => $this->project->color,
				'writable' => $this->project->isWritable,
			],
			// The extent of everything that has a bar, so the view knows where
			// to look first. Null when nothing on the plan has a date at all,
			// and the view decides for itself.
			'from' => $from,
			'to' => $to,
			'rows' => array_map(
				static fn (PlanRow $row): array => $row->jsonSerialize(),
				$this->rows,
			),
			'links' => array_map(
				static fn (Dependency $link): array => $link->jsonSerialize(),
				$this->links,
			),
			'conflicts' => array_map(
				static fn (Conflict $conflict): array => $conflict->jsonSerialize(),
				$this->conflicts,
			),
			'settings' => $this->settings->jsonSerialize(),
		];
	}
}
