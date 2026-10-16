<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A dependency parsed from `RELATED-TO` (§10.1), always stored as
 * predecessor → successor whichever way the client wrote it.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCalendarId()
 * @method void setCalendarId(int $calendarId)
 * @method string getSourceUid()
 * @method void setSourceUid(string $sourceUid)
 * @method string getPredecessorUid()
 * @method void setPredecessorUid(string $predecessorUid)
 * @method string getSuccessorUid()
 * @method void setSuccessorUid(string $successorUid)
 * @method string getReltype()
 * @method void setReltype(string $reltype)
 * @method int getGapSeconds()
 * @method void setGapSeconds(int $gapSeconds)
 */
class TaskLink extends Entity implements JsonSerializable {

	protected string $userId = '';
	protected int $calendarId = 0;
	protected string $sourceUid = '';
	protected string $predecessorUid = '';
	protected string $successorUid = '';
	protected string $reltype = '';
	protected int $gapSeconds = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('calendarId', 'integer');
		$this->addType('gapSeconds', 'integer');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'predecessorUid' => $this->predecessorUid,
			'successorUid' => $this->successorUid,
			'reltype' => $this->reltype,
			'gapSeconds' => $this->gapSeconds,
		];
	}
}
