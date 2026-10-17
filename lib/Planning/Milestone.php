<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * A date something has to be true by: a VTODO with `X-CHRONOS-TYPE:MILESTONE`
 * and a `DUE` (§6, §7).
 *
 * On a timeline a milestone is a row like any other, drawn as a diamond. On the
 * roadmap it is not a row at all — it is a mark on its project's line, which is
 * the only thing about a project worth reading from across a year.
 */
final class Milestone implements JsonSerializable {

	public function __construct(
		public readonly string $uid,
		public readonly ?string $summary,
		public readonly int $at,
		public readonly bool $isDate,
		public readonly bool $done,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'uid' => $this->uid,
			'summary' => $this->summary,
			'at' => $this->at,
			'isDate' => $this->isDate,
			'done' => $this->done,
		];
	}
}
