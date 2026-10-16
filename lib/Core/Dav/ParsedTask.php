<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

/**
 * A VTODO as the index needs it (§7).
 *
 * Everything here comes from the task itself. What Chronos knows but iCalendar
 * cannot carry — the workflow status, the sprint, custom fields — is read from
 * `X-CHRONOS-*` by the module that owns it, once that module exists (§7); the
 * index keeps the columns ready.
 */
final class ParsedTask {

	public const TYPE_TASK = 'task';
	public const TYPE_SUBPROJECT = 'subproject';
	public const TYPE_MILESTONE = 'milestone';

	/** The statuses iCalendar defines for a VTODO. */
	public const VTODO_STATUSES = ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'];

	/**
	 * @param list<ParsedLink> $links
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $type,
		public readonly ?string $parentUid,
		public readonly ?string $summary,
		public readonly ?string $vtodoStatus,
		public readonly int $priority,
		public readonly ?int $startAt,
		public readonly bool $startIsDate,
		public readonly ?int $dueAt,
		public readonly bool $dueIsDate,
		public readonly ?int $completedAt,
		public readonly ?int $percent,
		public readonly ?string $assignee,
		public readonly array $links = [],
	) {
	}
}
