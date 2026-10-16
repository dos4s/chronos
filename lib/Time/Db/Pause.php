<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One pause inside a session. Replaces the `pauses_blob` JSON column (D4).
 *
 * @method int getEntryId()
 * @method void setEntryId(int $entryId)
 * @method int getStartAt()
 * @method void setStartAt(int $startAt)
 * @method int|null getEndAt()
 * @method void setEndAt(?int $endAt)
 */
class Pause extends Entity implements JsonSerializable {

	protected int $entryId = 0;
	protected int $startAt = 0;
	protected ?int $endAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('entryId', 'integer');
		$this->addType('startAt', 'integer');
		$this->addType('endAt', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'entryId' => $this->entryId,
			'startAt' => $this->startAt,
			'endAt' => $this->endAt,
		];
	}
}
