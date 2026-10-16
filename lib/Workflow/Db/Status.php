<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One status of a workflow, and a kanban column once that view exists (§12.1).
 *
 * `vtodoStatus` is what every other CalDAV client sees, so a task in "Waiting
 * for customer" is still `IN-PROCESS` to the Tasks app and to a phone.
 *
 * @method int getWorkflowId()
 * @method void setWorkflowId(int $workflowId)
 * @method string getStatusKey()
 * @method void setStatusKey(string $statusKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method int getSort()
 * @method void setSort(int $sort)
 * @method string getVtodoStatus()
 * @method void setVtodoStatus(string $vtodoStatus)
 * @method bool getIsInitial()
 * @method void setIsInitial(bool $isInitial)
 * @method bool getIsFinal()
 * @method void setIsFinal(bool $isFinal)
 * @method bool getSlaPaused()
 * @method void setSlaPaused(bool $slaPaused)
 * @method bool getAutoTimer()
 * @method void setAutoTimer(bool $autoTimer)
 * @method bool getRequiresAssignee()
 * @method void setRequiresAssignee(bool $requiresAssignee)
 * @method int|null getWipLimit()
 * @method void setWipLimit(?int $wipLimit)
 */
class Status extends Entity implements JsonSerializable {

	protected int $workflowId = 0;
	protected string $statusKey = '';
	protected string $name = '';
	protected ?string $color = null;
	protected int $sort = 0;
	/**
	 * Empty on purpose, and never written empty.
	 *
	 * `Entity::setter()` ignores a value identical to the one the property
	 * already holds, so a property whose default is also a real value is left
	 * out of the insert `QBMapper` builds — and a NOT NULL column without a
	 * database default then refuses the row (D30).
	 */
	protected string $vtodoStatus = '';
	protected bool $isInitial = false;
	protected bool $isFinal = false;
	protected bool $slaPaused = false;
	protected bool $autoTimer = false;
	protected bool $requiresAssignee = false;
	protected ?int $wipLimit = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('workflowId', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('isInitial', 'boolean');
		$this->addType('isFinal', 'boolean');
		$this->addType('slaPaused', 'boolean');
		$this->addType('autoTimer', 'boolean');
		$this->addType('requiresAssignee', 'boolean');
		$this->addType('wipLimit', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'workflowId' => $this->workflowId,
			'key' => $this->statusKey,
			'name' => $this->name,
			'color' => $this->color,
			'sort' => $this->sort,
			'vtodoStatus' => $this->vtodoStatus,
			'isInitial' => $this->isInitial,
			'isFinal' => $this->isFinal,
			'slaPaused' => $this->slaPaused,
			'autoTimer' => $this->autoTimer,
			'requiresAssignee' => $this->requiresAssignee,
			'wipLimit' => $this->wipLimit,
		];
	}
}
