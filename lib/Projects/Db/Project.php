<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects\Db;

use OCP\AppFramework\Db\Entity;

/**
 * What Chronos knows about a project that CalDAV cannot hold (§10.2).
 *
 * The project itself is the task list; this row is its configuration, and it
 * exists only once the user has configured something. A project with no row is
 * a project with default settings, not a missing project.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getProjectUri()
 * @method void setProjectUri(string $projectUri)
 * @method int|null getAreaId()
 * @method void setAreaId(?int $areaId)
 * @method int|null getParentProjectId()
 * @method void setParentProjectId(?int $parentProjectId)
 * @method int|null getWorkflowId()
 * @method void setWorkflowId(?int $workflowId)
 * @method string|null getSettings()
 * @method void setSettings(?string $settings)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method int getSort()
 * @method void setSort(int $sort)
 */
class Project extends Entity {

	protected string $userId = '';
	protected string $projectUri = '';
	protected ?int $areaId = null;
	protected ?int $parentProjectId = null;
	protected ?int $workflowId = null;
	protected ?string $settings = null;
	protected bool $archived = false;
	protected int $sort = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('areaId', 'integer');
		$this->addType('parentProjectId', 'integer');
		$this->addType('workflowId', 'integer');
		$this->addType('archived', 'boolean');
		$this->addType('sort', 'integer');
	}

	/**
	 * Whether this row says anything at all. A row that says nothing is
	 * deleted, so "configured" stays the same thing as "has a row" (§6).
	 */
	public function isDefault(): bool {
		return $this->areaId === null
			&& $this->parentProjectId === null
			&& $this->workflowId === null
			&& ($this->settings === null || $this->settings === '' || $this->settings === '{}')
			&& $this->archived === false
			&& $this->sort === 0;
	}
}
