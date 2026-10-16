<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One row of the task index (§10.1): a rebuildable copy of the fields Chronos
 * queries, never edited by hand. The VTODO stays the source of truth.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCalendarId()
 * @method void setCalendarId(int $calendarId)
 * @method string getCalendarUri()
 * @method void setCalendarUri(string $calendarUri)
 * @method string getObjectUri()
 * @method void setObjectUri(string $objectUri)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getParentUid()
 * @method void setParentUid(?string $parentUid)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getVtodoStatus()
 * @method void setVtodoStatus(?string $vtodoStatus)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method int|null getPercent()
 * @method void setPercent(?int $percent)
 * @method string|null getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method int|null getStartAt()
 * @method void setStartAt(?int $startAt)
 * @method int|null getDueAt()
 * @method void setDueAt(?int $dueAt)
 * @method bool getStartIsDate()
 * @method void setStartIsDate(bool $startIsDate)
 * @method bool getDueIsDate()
 * @method void setDueIsDate(bool $dueIsDate)
 * @method int|null getCompletedAt()
 * @method void setCompletedAt(?int $completedAt)
 * @method int|null getStatusId()
 * @method void setStatusId(?int $statusId)
 * @method int|null getSprintId()
 * @method void setSprintId(?int $sprintId)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
class Task extends Entity implements JsonSerializable {

	protected string $userId = '';
	protected int $calendarId = 0;
	protected string $calendarUri = '';
	protected string $objectUri = '';
	protected string $etag = '';
	protected string $uid = '';
	protected string $type = 'task';
	protected ?string $parentUid = null;
	protected ?string $summary = null;
	protected ?string $vtodoStatus = null;
	protected int $priority = 0;
	protected ?int $percent = null;
	protected ?string $assignee = null;
	protected ?int $startAt = null;
	protected ?int $dueAt = null;
	protected bool $startIsDate = false;
	protected bool $dueIsDate = false;
	protected ?int $completedAt = null;
	protected ?int $statusId = null;
	protected ?int $sprintId = null;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('calendarId', 'integer');
		$this->addType('priority', 'integer');
		$this->addType('percent', 'integer');
		$this->addType('startAt', 'integer');
		$this->addType('dueAt', 'integer');
		$this->addType('startIsDate', 'boolean');
		$this->addType('dueIsDate', 'boolean');
		$this->addType('completedAt', 'integer');
		$this->addType('statusId', 'integer');
		$this->addType('sprintId', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('deletedAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'calendarUri' => $this->calendarUri,
			'objectUri' => $this->objectUri,
			'etag' => $this->etag,
			'uid' => $this->uid,
			'type' => $this->type,
			'parentUid' => $this->parentUid,
			'summary' => $this->summary,
			'vtodoStatus' => $this->vtodoStatus,
			'priority' => $this->priority,
			'percent' => $this->percent,
			'assignee' => $this->assignee,
			'startAt' => $this->startAt,
			'dueAt' => $this->dueAt,
			'startIsDate' => $this->startIsDate,
			'dueIsDate' => $this->dueIsDate,
			'completedAt' => $this->completedAt,
			'statusId' => $this->statusId,
			'sprintId' => $this->sprintId,
			'updatedAt' => $this->updatedAt,
		];
	}
}
