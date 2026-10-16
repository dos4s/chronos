<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;

/**
 * How the task layer asks about workflow statuses without depending on the
 * workflow module (§5).
 *
 * The container binds this to the workflow module when the instance has it
 * switched on, and to `NoWorkflow` when it does not, so the task layer has one
 * code path either way.
 */
interface StatusGateway {

	/**
	 * The workflow status id the index should store for a task it has just
	 * read from CalDAV.
	 *
	 * @param string|null $statusKey what `X-CHRONOS-STATUS` said, if anything
	 * @param string|null $vtodoStatus the standard `STATUS`
	 * @param int|null $currentStatusId what the index holds now, which is the
	 *                                  fallback source of truth when a client
	 *                                  strips `X-CHRONOS-STATUS` (§7)
	 */
	public function resolveForIndex(
		string $userId,
		int $calendarId,
		?string $statusKey,
		?string $vtodoStatus,
		?int $currentStatusId,
	): ?int;

	/**
	 * Decides what a write should set, and refuses a move the workflow does
	 * not allow.
	 *
	 * @param Task|null $current null when the task is being created
	 * @throws ValidationFailedException
	 */
	public function prepareWrite(
		string $userId,
		string $projectRef,
		?Task $current,
		?string $statusKey,
		?string $requestedVtodoStatus,
		?string $comment,
	): StatusWrite;
}
