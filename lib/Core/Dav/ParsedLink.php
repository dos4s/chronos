<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

/**
 * One `RELATED-TO` relationship read off a task (§7, RFC 9253).
 *
 * The direction is normalised here: a temporal link is written on the
 * predecessor and points at the successor, while `DEPENDS-ON` is written on the
 * dependent task and points at what it depends on. Both end up as
 * predecessor → successor, so queries do not have to care which form the
 * client used.
 */
final class ParsedLink {

	public const RELTYPE_PARENT = 'PARENT';
	public const RELTYPE_DEPENDS_ON = 'DEPENDS-ON';

	public const RELTYPE_FINISH_TO_START = 'FINISHTOSTART';
	public const RELTYPE_START_TO_START = 'STARTTOSTART';
	public const RELTYPE_FINISH_TO_FINISH = 'FINISHTOFINISH';
	public const RELTYPE_START_TO_FINISH = 'STARTTOFINISH';

	/** The temporal relationships Chronos schedules with (RFC 9253 §4). */
	public const TEMPORAL_RELTYPES = [
		self::RELTYPE_FINISH_TO_START,
		self::RELTYPE_START_TO_START,
		self::RELTYPE_FINISH_TO_FINISH,
		self::RELTYPE_START_TO_FINISH,
	];

	public function __construct(
		public readonly string $predecessorUid,
		public readonly string $successorUid,
		public readonly string $reltype,
		public readonly int $gapSeconds,
	) {
	}
}
