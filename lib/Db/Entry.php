<?php

declare(strict_types=1);

namespace OCA\Chronos\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getStartTime()
 * @method void setStartTime(int $startTime)
 * @method int|null getEndTime()
 * @method void setEndTime(?int $endTime)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int|null getPausedAt()
 * @method void setPausedAt(?int $pausedAt)
 * @method int getPausedDuration()
 * @method void setPausedDuration(int $pausedDuration)
 * @method string|null getProjectUri()
 * @method void setProjectUri(?string $projectUri)
 * @method string|null getProjectName()
 * @method void setProjectName(?string $projectName)
 * @method string|null getPausesBlob()
 * @method void setPausesBlob(?string $pausesBlob)
 */
class Entry extends Entity implements JsonSerializable {

	protected string $userId = '';
	protected int $startTime = 0;
	protected ?int $endTime = null;
	protected ?string $note = null;
	protected ?int $pausedAt = null;
	protected int $pausedDuration = 0;
	protected ?string $projectUri = null;
	protected ?string $projectName = null;
	protected ?string $pausesBlob = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('startTime', 'integer');
		$this->addType('endTime', 'integer');
		$this->addType('pausedAt', 'integer');
		$this->addType('pausedDuration', 'integer');
		$this->addType('pausesBlob', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'startTime' => $this->startTime,
			'endTime' => $this->endTime,
			'note' => $this->note,
			'pausedAt' => $this->pausedAt,
			'pausedDuration' => $this->pausedDuration,
			'projectUri' => $this->projectUri,
			'projectName' => $this->projectName,
			'pausesBlob' => $this->pausesBlob,
		];
	}
}
