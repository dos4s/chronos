<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A workflow: a named set of statuses a project's tasks move through (§12.1).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method bool getIsTemplate()
 * @method void setIsTemplate(bool $isTemplate)
 */
class Workflow extends Entity {

	protected string $userId = '';
	protected string $name = '';
	protected bool $isTemplate = false;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('isTemplate', 'boolean');
	}
}
