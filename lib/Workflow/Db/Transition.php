<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * An allowed move between two statuses (§10.3).
 *
 * A workflow with no transition rows allows every move; one with rows allows
 * exactly those, which is also what dims a kanban column (M3).
 *
 * @method int getWorkflowId()
 * @method void setWorkflowId(int $workflowId)
 * @method int getFromStatusId()
 * @method void setFromStatusId(int $fromStatusId)
 * @method int getToStatusId()
 * @method void setToStatusId(int $toStatusId)
 * @method bool getRequiresComment()
 * @method void setRequiresComment(bool $requiresComment)
 */
class Transition extends Entity implements JsonSerializable {

	protected int $workflowId = 0;
	protected int $fromStatusId = 0;
	protected int $toStatusId = 0;
	protected bool $requiresComment = false;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('workflowId', 'integer');
		$this->addType('fromStatusId', 'integer');
		$this->addType('toStatusId', 'integer');
		$this->addType('requiresComment', 'boolean');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'workflowId' => $this->workflowId,
			'fromStatusId' => $this->fromStatusId,
			'toStatusId' => $this->toStatusId,
			'requiresComment' => $this->requiresComment,
		];
	}
}
