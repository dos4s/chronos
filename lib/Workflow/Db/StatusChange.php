<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One row of status history (§10.3): what changed, who changed it, and from
 * which side of CalDAV.
 *
 * Time in a state cannot be worked out afterwards, so these rows are written
 * from the first release that has statuses, long before SLA needs them. The
 * status keys are kept beside the ids, because an id stops meaning anything
 * the moment its workflow is deleted.
 *
 * @method string getTaskUid()
 * @method void setTaskUid(string $taskUid)
 * @method int|null getFromStatusId()
 * @method void setFromStatusId(?int $fromStatusId)
 * @method int|null getToStatusId()
 * @method void setToStatusId(?int $toStatusId)
 * @method string|null getFromKey()
 * @method void setFromKey(?string $fromKey)
 * @method string|null getToKey()
 * @method void setToKey(?string $toKey)
 * @method string|null getFromAssignee()
 * @method void setFromAssignee(?string $fromAssignee)
 * @method string|null getToAssignee()
 * @method void setToAssignee(?string $toAssignee)
 * @method string getChangedBy()
 * @method void setChangedBy(string $changedBy)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method int getChangedAt()
 * @method void setChangedAt(int $changedAt)
 */
class StatusChange extends Entity implements JsonSerializable {

	protected string $taskUid = '';
	protected ?int $fromStatusId = null;
	protected ?int $toStatusId = null;
	protected ?string $fromKey = null;
	protected ?string $toKey = null;
	protected ?string $fromAssignee = null;
	protected ?string $toAssignee = null;
	protected string $changedBy = '';
	protected string $source = 'chronos';
	protected ?string $comment = null;
	protected int $changedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('fromStatusId', 'integer');
		$this->addType('toStatusId', 'integer');
		$this->addType('changedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'taskUid' => $this->taskUid,
			'fromStatusId' => $this->fromStatusId,
			'toStatusId' => $this->toStatusId,
			'fromKey' => $this->fromKey,
			'toKey' => $this->toKey,
			'fromAssignee' => $this->fromAssignee,
			'toAssignee' => $this->toAssignee,
			'changedBy' => $this->changedBy,
			'source' => $this->source,
			'comment' => $this->comment,
			'changedAt' => $this->changedAt,
		];
	}
}
