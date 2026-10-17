<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Integration;

use OCA\Chronos\Board\Db\BoardRank;
use OCA\Chronos\Board\Db\BoardRankMapper;
use OCA\Chronos\Board\Rank;
use OCA\Chronos\Board\Service\BoardService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Card ranks against the real database (§15.5).
 *
 * The algorithm is covered by the unit tests; what needs a real engine is
 * everything around it: that a card has one row and not two, that a column
 * comes back in rank order from the database's own collation rather than from
 * PHP, that `charLength` finds the long ranks the rebalance job looks for, and
 * that rewriting a column keeps the order it described.
 *
 * Uses a calendar id that cannot exist, so nothing here touches real data.
 */
class BoardRankTest extends TestCase {

	private const CALENDAR_ID = -4244;
	private const COLUMN = 'doing';

	private BoardRankMapper $ranks;
	private BoardService $board;

	protected function setUp(): void {
		parent::setUp();

		$this->ranks = Server::get(BoardRankMapper::class);
		$this->board = Server::get(BoardService::class);

		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	/**
	 * Both string columns default to empty and are never written empty, which
	 * is the trap D30 was: `Entity::setter()` ignores a value identical to the
	 * property's default, and the insert would leave the column out.
	 */
	public function testARankLandsAsARowWithEverythingItWasGiven(): void {
		$this->ranks->place(self::CALENDAR_ID, 'card-a', self::COLUMN, 'i');

		$row = $this->ranks->find(self::CALENDAR_ID, 'card-a');

		self::assertNotNull($row);
		self::assertSame(self::CALENDAR_ID, $row->getCalendarId());
		self::assertSame('card-a', $row->getTaskUid());
		self::assertSame(self::COLUMN, $row->getColumnKey());
		self::assertSame('i', $row->getCardRank());
	}

	/**
	 * A card has one position, so moving it twice leaves one row: a second
	 * would be two answers to where it sits, and the unique index is what
	 * makes that impossible rather than unlikely.
	 */
	public function testMovingTheSameCardAgainReplacesItsRank(): void {
		$this->ranks->place(self::CALENDAR_ID, 'card-a', self::COLUMN, 'i');
		$this->ranks->place(self::CALENDAR_ID, 'card-a', 'done', 'r');

		$rows = $this->ranks->findForCalendar(self::CALENDAR_ID);

		self::assertCount(1, $rows);
		self::assertSame('done', $rows['card-a']->getColumnKey());
		self::assertSame('r', $rows['card-a']->getCardRank());
	}

	/**
	 * The database does the ordering, so its collation has to agree with
	 * PHP's string comparison. That is why the alphabet has no uppercase
	 * letters: a case-insensitive collation would make `A` and `a` one rank.
	 */
	public function testAColumnComesBackInRankOrder(): void {
		$this->ranks->place(self::CALENDAR_ID, 'third', self::COLUMN, 'r');
		$this->ranks->place(self::CALENDAR_ID, 'first', self::COLUMN, '9');
		$this->ranks->place(self::CALENDAR_ID, 'second', self::COLUMN, 'i');
		$this->ranks->place(self::CALENDAR_ID, 'elsewhere', 'done', '0i');

		$order = array_map(
			static fn (BoardRank $rank): string => $rank->getTaskUid(),
			$this->ranks->findForColumn(self::CALENDAR_ID, self::COLUMN),
		);

		self::assertSame(['first', 'second', 'third'], $order);
	}

	public function testRewritingAColumnKeepsTheOrderItDescribed(): void {
		$ranks = ['a' => '9', 'b' => 'i', 'c' => 'r'];
		foreach ($ranks as $uid => $rank) {
			// Each one made deliberately long, as a column that has been
			// dropped into over and over ends up.
			$this->ranks->place(self::CALENDAR_ID, $uid, self::COLUMN, $rank . str_repeat('i', 40));
		}

		$rewritten = $this->board->rebalance(self::CALENDAR_ID, self::COLUMN);

		self::assertSame(3, $rewritten);

		$column = $this->ranks->findForColumn(self::CALENDAR_ID, self::COLUMN);

		self::assertSame(
			['a', 'b', 'c'],
			array_map(static fn (BoardRank $rank): string => $rank->getTaskUid(), $column),
		);
		foreach ($column as $rank) {
			self::assertFalse(Rank::isLong($rank->getCardRank()), 'a rewritten rank is short again');
		}
	}

	/**
	 * What the rebalance job asks the database: which columns have ranks long
	 * enough to be worth rewriting. `charLength` is one of the few functions
	 * that differ between engines, so it is worth running for real.
	 */
	public function testTheJobFindsAColumnWithLongRanksAndNotOneWithout(): void {
		$this->ranks->place(self::CALENDAR_ID, 'short', 'done', 'i');
		$this->ranks->place(self::CALENDAR_ID, 'long', self::COLUMN, str_repeat('i', Rank::MAX_LENGTH + 2));

		$columns = $this->ranks->findLongColumns(Rank::MAX_LENGTH);
		$mine = array_values(array_filter(
			$columns,
			static fn (array $column): bool => $column['calendarId'] === self::CALENDAR_ID,
		));

		self::assertSame([['calendarId' => self::CALENDAR_ID, 'columnKey' => self::COLUMN]], $mine);
	}

	public function testRebalancingAColumnThatHasNoRanksDoesNothing(): void {
		self::assertSame(0, $this->board->rebalance(self::CALENDAR_ID, 'empty'));
	}

	public function testForgettingACardLeavesTheRestOfTheColumn(): void {
		$this->ranks->place(self::CALENDAR_ID, 'gone', self::COLUMN, '9');
		$this->ranks->place(self::CALENDAR_ID, 'stays', self::COLUMN, 'i');

		$this->ranks->deleteForTask(self::CALENDAR_ID, 'gone');

		self::assertNull($this->ranks->find(self::CALENDAR_ID, 'gone'));
		self::assertNotNull($this->ranks->find(self::CALENDAR_ID, 'stays'));
	}

	private function cleanUp(): void {
		$this->ranks->deleteForCalendar(self::CALENDAR_ID);
	}
}
