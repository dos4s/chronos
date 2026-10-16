<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Event;

/**
 * Where a change came from (§11.2).
 *
 * History records this, because a change Chronos made is timestamped exactly,
 * while a change from a phone is timestamped when it reached the server — the
 * phone may have been offline for hours.
 */
enum TaskSource: string {
	case Chronos = 'chronos';
	case External = 'external';
}
