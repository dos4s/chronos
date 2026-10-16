<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;

/**
 * The task layer without the workflow module (§13).
 *
 * Tasks then have the four standard statuses and nothing else, which is a
 * complete way to work; asking for a workflow status says so rather than being
 * quietly ignored.
 */
class NoWorkflow implements StatusGateway {

	public function resolveForIndex(
		string $userId,
		int $calendarId,
		?string $statusKey,
		?string $vtodoStatus,
		?int $currentStatusId,
	): ?int {
		return null;
	}

	public function prepareWrite(
		string $userId,
		string $projectRef,
		?Task $current,
		?string $statusKey,
		?string $requestedVtodoStatus,
		?string $comment,
	): StatusWrite {
		if ($statusKey !== null) {
			throw new ValidationFailedException('workflow_unavailable');
		}

		return new StatusWrite();
	}
}
