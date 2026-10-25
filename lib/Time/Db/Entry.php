<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A time session. All timestamps and durations are UNIX seconds (§8).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getStartAt()
 * @method void setStartAt(int $startAt)
 * @method int|null getEndAt()
 * @method void setEndAt(?int $endAt)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int|null getPausedSinceAt()
 * @method void setPausedSinceAt(?int $pausedSinceAt)
 * @method int getPausedSeconds()
 * @method void setPausedSeconds(int $pausedSeconds)
 * @method string|null getProjectUri()
 * @method void setProjectUri(?string $projectUri)
 * @method string|null getProjectName()
 * @method void setProjectName(?string $projectName)
 * @method string|null getTaskUid()
 * @method void setTaskUid(?string $taskUid)
 * @method string|null getTaskSummary()
 * @method void setTaskSummary(?string $taskSummary)
 * @method string|null getActiveUserId()
 * @method void setActiveUserId(?string $activeUserId)
 */
class Entry extends Entity implements JsonSerializable {

	/** Columns this app reads and writes; `pauses_blob` is deliberately absent. */
	public const COLUMNS = [
		'id',
		'user_id',
		'start_at',
		'end_at',
		'note',
		'paused_since_at',
		'paused_seconds',
		'project_uri',
		'project_name',
		'task_uid',
		'task_summary',
		'active_user_id',
	];

	protected string $userId = '';
	protected int $startAt = 0;
	protected ?int $endAt = null;
	protected ?string $note = null;
	protected ?int $pausedSinceAt = null;
	protected int $pausedSeconds = 0;
	protected ?string $projectUri = null;
	protected ?string $projectName = null;
	/**
	 * The task this was worked on, by uid, and its name as it read at the
	 * time. Neither is a foreign key: a session records what somebody did and
	 * outlives the task it was about (§12.2).
	 */
	protected ?string $taskUid = null;
	protected ?string $taskSummary = null;
	/**
	 * The owner's uid while this session runs, NULL once it ends. A unique
	 * index over this column is what makes two simultaneous check-ins
	 * impossible at the database level (D5); it is never sent to clients.
	 */
	protected ?string $activeUserId = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('startAt', 'integer');
		$this->addType('endAt', 'integer');
		$this->addType('pausedSinceAt', 'integer');
		$this->addType('pausedSeconds', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'startAt' => $this->startAt,
			'endAt' => $this->endAt,
			'note' => $this->note,
			'pausedSinceAt' => $this->pausedSinceAt,
			'pausedSeconds' => $this->pausedSeconds,
			'projectUri' => $this->projectUri,
			'projectName' => $this->projectName,
			'taskUid' => $this->taskUid,
			'taskSummary' => $this->taskSummary,
		];
	}
}
