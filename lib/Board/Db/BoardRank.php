<?php

declare(strict_types=1);

namespace OCA\Chronos\Board\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Where one card sits in its column (§15.5).
 *
 * The rank is the only thing a drop writes besides the task's status, and it
 * lives here rather than in the VTODO: an order on a board is Chronos's own
 * view of a list, not something another CalDAV client has any use for, and a
 * task moved by a phone should not lose its place.
 *
 * `columnKey` is the column the rank was given in, so a card whose status
 * changed elsewhere is known to have a rank from a column it has left.
 *
 * @method int getCalendarId()
 * @method void setCalendarId(int $calendarId)
 * @method string getTaskUid()
 * @method void setTaskUid(string $taskUid)
 * @method string getColumnKey()
 * @method void setColumnKey(string $columnKey)
 * @method string getCardRank()
 * @method void setCardRank(string $cardRank)
 */
class BoardRank extends Entity {

	protected int $calendarId = 0;
	protected string $taskUid = '';
	/**
	 * Both empty on purpose, and never written empty: `Entity::setter()`
	 * ignores a value identical to the property's default, which would leave
	 * the column out of the insert altogether (D30).
	 */
	protected string $columnKey = '';
	protected string $cardRank = '';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('calendarId', 'integer');
	}
}
