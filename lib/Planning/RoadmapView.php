<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * Every project the user can see, when each one runs, and what is due in it
 * (§12.6).
 *
 * Grouped by area, because that is how somebody with fifteen lists thinks
 * about them, and one request, because a roadmap is one screen (§16): the
 * alternative is fifteen round trips to draw fifteen lines.
 */
final class RoadmapView implements JsonSerializable {

	/**
	 * @param list<array{id: int|null, name: string|null, color: string|null}> $areas
	 * @param list<RoadmapRow> $rows in area order, each project followed by its subprojects
	 */
	public function __construct(
		public readonly array $areas,
		public readonly array $rows,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$from = null;
		$to = null;
		foreach ($this->rows as $row) {
			if ($row->span === null) {
				continue;
			}
			$from = $from === null ? $row->span->startAt : min($from, $row->span->startAt);
			$to = $to === null ? $row->span->endAt : max($to, $row->span->endAt);
		}

		return [
			'areas' => $this->areas,
			'from' => $from,
			'to' => $to,
			'rows' => array_map(
				static fn (RoadmapRow $row): array => $row->jsonSerialize(),
				$this->rows,
			),
		];
	}
}
