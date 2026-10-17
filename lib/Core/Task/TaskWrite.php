<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\Task;

/**
 * The fields Chronos manages on a task.
 *
 * A write replaces exactly these and leaves every other property in the VTODO
 * alone, which is what lets Chronos edit a task another client created without
 * throwing away anything it does not understand (§7).
 */
final class TaskWrite {

	private const MAX_SUMMARY = 512;
	private const MAX_COMMENT = 2048;

	public function __construct(
		public readonly string $summary,
		public readonly ?string $status = null,
		public readonly int $priority = 0,
		public readonly ?int $startAt = null,
		public readonly bool $startIsDate = true,
		public readonly ?int $dueAt = null,
		public readonly bool $dueIsDate = true,
		public readonly ?int $percent = null,
		public readonly ?string $parentUid = null,
		public readonly string $type = ParsedTask::TYPE_TASK,
		/** The workflow status asked for, as a key; null leaves it alone. */
		public readonly ?string $statusKey = null,
		/** A note on the status change, which a transition may require. */
		public readonly ?string $comment = null,
	) {
	}

	/**
	 * The same write with the status the workflow settled on (§12.1).
	 *
	 * The status is decided once, by the workflow module, and the write then
	 * carries both what every client sees and what Chronos records.
	 */
	public function withStatus(StatusWrite $status): self {
		return new self(
			summary: $this->summary,
			status: $status->vtodoStatus ?? $this->status,
			priority: $this->priority,
			startAt: $this->startAt,
			startIsDate: $this->startIsDate,
			dueAt: $this->dueAt,
			dueIsDate: $this->dueIsDate,
			percent: $this->percent,
			parentUid: $this->parentUid,
			type: $this->type,
			statusKey: $status->statusKey,
			comment: $this->comment,
		);
	}

	/**
	 * The task as the index has it, with a different status.
	 *
	 * A write replaces every field Chronos manages, so changing one of them
	 * means sending the rest back unchanged. A board drop does exactly that
	 * and no more: it is a status change, so the summary, the dates and the
	 * progress of the card come from the row rather than from the client, and
	 * a card with no summary at all stays a card with no summary rather than
	 * being refused as a task without one.
	 *
	 * @throws ValidationFailedException
	 */
	public static function fromTask(
		Task $task,
		?string $vtodoStatus = null,
		?string $statusKey = null,
		?string $comment = null,
	): self {
		$comment = $comment === null ? null : trim($comment);
		if ($comment === '') {
			$comment = null;
		}
		if ($comment !== null && mb_strlen($comment) > self::MAX_COMMENT) {
			throw new ValidationFailedException('comment_too_long');
		}

		if ($vtodoStatus !== null && !in_array($vtodoStatus, ParsedTask::VTODO_STATUSES, true)) {
			throw new ValidationFailedException('invalid_status');
		}

		return new self(
			summary: $task->getSummary() ?? '',
			status: $vtodoStatus ?? $task->getVtodoStatus(),
			priority: $task->getPriority(),
			startAt: $task->getStartAt(),
			startIsDate: $task->getStartIsDate(),
			dueAt: $task->getDueAt(),
			dueIsDate: $task->getDueIsDate(),
			percent: $task->getPercent(),
			parentUid: $task->getParentUid(),
			type: $task->getType(),
			statusKey: $statusKey,
			comment: $comment,
		);
	}

	/**
	 * @throws ValidationFailedException
	 */
	public static function fromParams(
		?string $summary,
		?string $status,
		?int $priority,
		?int $startAt,
		?bool $startIsDate,
		?int $dueAt,
		?bool $dueIsDate,
		?int $percent,
		?string $parentUid,
		?string $type,
		?string $statusKey = null,
		?string $comment = null,
	): self {
		$summary = trim($summary ?? '');
		if ($summary === '') {
			throw new ValidationFailedException('summary_required');
		}
		if (mb_strlen($summary) > self::MAX_SUMMARY) {
			throw new ValidationFailedException('summary_too_long');
		}

		if ($status !== null && !in_array($status, ParsedTask::VTODO_STATUSES, true)) {
			throw new ValidationFailedException('invalid_status');
		}

		if ($startAt !== null && $dueAt !== null && $dueAt < $startAt) {
			throw new ValidationFailedException('invalid_dates');
		}

		$type ??= ParsedTask::TYPE_TASK;
		if (!in_array($type, [ParsedTask::TYPE_TASK, ParsedTask::TYPE_SUBPROJECT, ParsedTask::TYPE_MILESTONE], true)) {
			throw new ValidationFailedException('invalid_type');
		}

		$statusKey = $statusKey === null ? null : strtolower(trim($statusKey));
		if ($statusKey === '') {
			$statusKey = null;
		}
		if ($statusKey !== null && preg_match('/^[a-z0-9_-]{1,64}$/', $statusKey) !== 1) {
			throw new ValidationFailedException('invalid_status_key');
		}

		$comment = $comment === null ? null : trim($comment);
		if ($comment === '') {
			$comment = null;
		}
		if ($comment !== null && mb_strlen($comment) > self::MAX_COMMENT) {
			throw new ValidationFailedException('comment_too_long');
		}

		return new self(
			summary: $summary,
			status: $status,
			priority: max(0, min(9, $priority ?? 0)),
			startAt: $startAt,
			startIsDate: $startIsDate ?? true,
			dueAt: $dueAt,
			dueIsDate: $dueIsDate ?? true,
			percent: $percent === null ? null : max(0, min(100, $percent)),
			parentUid: $parentUid === null || trim($parentUid) === '' ? null : trim($parentUid),
			type: $type,
			statusKey: $statusKey,
			comment: $comment,
		);
	}
}
