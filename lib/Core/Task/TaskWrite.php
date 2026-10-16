<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Core\Error\ValidationFailedException;

/**
 * The fields Chronos manages on a task.
 *
 * A write replaces exactly these and leaves every other property in the VTODO
 * alone, which is what lets Chronos edit a task another client created without
 * throwing away anything it does not understand (§7).
 */
final class TaskWrite {

	private const MAX_SUMMARY = 512;

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
	) {
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
		);
	}
}
