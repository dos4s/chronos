<?php

declare(strict_types=1);

namespace OCA\Chronos\Projects\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * An area: the one level of the hierarchy that exists only in Chronos (§6).
 *
 * CalDAV has no grouping above a calendar, so "Work", "Personal" and "Homelab"
 * live here and nowhere else. Deleting an area never deletes a project.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method int getSort()
 * @method void setSort(int $sort)
 */
class Area extends Entity implements JsonSerializable {

	protected string $userId = '';
	protected string $name = '';
	protected ?string $color = null;
	protected int $sort = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('sort', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'color' => $this->color,
			'sort' => $this->sort,
		];
	}
}
