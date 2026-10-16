<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

/**
 * A calendar that supports tasks, which Chronos shows as a project (§6).
 *
 * `ref` keeps the format the time sessions already use,
 * `principals/users/<uid>/<calendar-uri>`, so existing sessions stay linked to
 * their project.
 */
final class CalendarRef {

	public function __construct(
		public readonly int $id,
		public readonly string $uri,
		public readonly string $ref,
		public readonly ?string $displayName,
		public readonly ?string $color,
		public readonly bool $supportsVTodo,
		public readonly bool $isShared,
		public readonly bool $isWritable,
	) {
	}

	public function name(): string {
		return $this->displayName ?? $this->uri;
	}
}
