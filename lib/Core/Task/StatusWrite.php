<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

/**
 * What a status change comes to, once the workflow has had its say (§12.1).
 *
 * `statusKey` goes into `X-CHRONOS-STATUS` and `vtodoStatus` into the standard
 * `STATUS`, so a task in "Waiting for customer" is still `IN-PROCESS` to every
 * other client. Both are null when the project has no workflow, and then the
 * task keeps the standard status the caller asked for and nothing else.
 */
final class StatusWrite {

	public function __construct(
		public readonly ?string $statusKey = null,
		public readonly ?string $vtodoStatus = null,
	) {
	}
}
