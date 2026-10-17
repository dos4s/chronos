<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Board;

use OCA\Chronos\Board\Rank;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rank algorithm (§15.5), which every card's position rests on: a rank
 * that sorts in the wrong place, or the same as another, is a card that moves
 * on its own.
 */
class RankTest extends TestCase {

	public function testTheFirstCardSitsInTheMiddleOfTheRange(): void {
		self::assertSame('i', Rank::between(null, null));
	}

	public function testACardDroppedAtTheTopSortsBeforeTheOneBelowIt(): void {
		$rank = Rank::between(null, 'i');

		self::assertLessThan(0, strcmp($rank, 'i'));
	}

	public function testACardDroppedAtTheBottomSortsAfterTheOneAboveIt(): void {
		$rank = Rank::between('i', null);

		self::assertGreaterThan(0, strcmp($rank, 'i'));
	}

	public function testACardDroppedBetweenTwoSortsBetweenThem(): void {
		$rank = Rank::between('9', 'r');

		self::assertLessThan(0, strcmp('9', $rank));
		self::assertLessThan(0, strcmp($rank, 'r'));
	}

	/**
	 * Neighbouring digits leave no room at that position, so the rank grows a
	 * character instead of landing outside the gap.
	 */
	public function testThereIsRoomBetweenTwoAdjacentRanks(): void {
		$rank = Rank::between('i', 'j');

		self::assertLessThan(0, strcmp('i', $rank));
		self::assertLessThan(0, strcmp($rank, 'j'));
	}

	public function testRanksAreComparedAsStringsNotAsNumbers(): void {
		// A rank is a fraction without its leading `0.`, so a longer rank is
		// not a bigger one: `i` sorts before `i1`, and both before `j`.
		self::assertLessThan(0, strcmp('i', 'i1'));
		self::assertLessThan(0, strcmp('i1', 'j'));
	}

	public function testTheAlphabetHasNoUppercaseLetters(): void {
		// A case-insensitive collation — the MySQL default — would make `A`
		// and `a` the same rank, and a rank is a position.
		self::assertSame(strtolower(Rank::ALPHABET), Rank::ALPHABET);
		self::assertSame(Rank::BASE, strlen(Rank::ALPHABET));
	}

	#[DataProvider('columnSizes')]
	public function testAColumnOfRanksIsInOrderAndUnique(int $count): void {
		$ranks = Rank::sequence($count);

		self::assertCount($count, $ranks);
		self::assertSame($count, count(array_unique($ranks)));

		$sorted = $ranks;
		sort($sorted, SORT_STRING);
		self::assertSame($sorted, $ranks);
	}

	/**
	 * @return list<array{int}>
	 */
	public static function columnSizes(): array {
		return [[1], [2], [5], [37], [500], [2000]];
	}

	public function testAnEmptyColumnGetsNoRanks(): void {
		self::assertSame([], Rank::sequence(0));
	}

	/**
	 * A rank ending in the bottom digit has no room below it without growing,
	 * and the digit says nothing, so no rank ends in one.
	 */
	public function testNoRankEndsInTheBottomDigit(): void {
		foreach (Rank::sequence(200) as $rank) {
			self::assertStringEndsNotWith('0', $rank);
		}

		self::assertStringEndsNotWith('0', Rank::between(null, '1'));
		self::assertStringEndsNotWith('0', Rank::between('0', '01'));
	}

	/**
	 * The worst case a board can produce: every card dropped into the same
	 * gap, which narrows it each time. Ranks grow, but the column stays in
	 * order — and `isLong` is what tells the service to rewrite it (§15.5).
	 */
	public function testDroppingIntoTheSameGapKeepsTheColumnInOrder(): void {
		$column = Rank::sequence(5);

		for ($i = 0; $i < 60; $i++) {
			$rank = Rank::between($column[0], $column[1]);

			self::assertLessThan(0, strcmp($column[0], $rank), "drop $i is below the card above it");
			self::assertLessThan(0, strcmp($rank, $column[1]), "drop $i is above the card below it");

			array_splice($column, 1, 0, [$rank]);
		}

		$sorted = $column;
		sort($sorted, SORT_STRING);
		self::assertSame($sorted, $column);
		self::assertSame(count($column), count(array_unique($column)));
	}

	public function testALongRankIsRecognisedSoItsColumnCanBeRewritten(): void {
		self::assertFalse(Rank::isLong('ii'));
		self::assertFalse(Rank::isLong(str_repeat('i', Rank::MAX_LENGTH)));
		self::assertTrue(Rank::isLong(str_repeat('i', Rank::MAX_LENGTH + 1)));
	}

	/**
	 * Two ranks the wrong way round describe a gap that does not exist — a
	 * board read from a stale page. The card lands after the lower of the two
	 * rather than inside somebody else's gap, and the column is still ordered.
	 */
	public function testAGapThatDoesNotExistStillGivesAUsableRank(): void {
		$equal = Rank::between('i', 'i');
		self::assertGreaterThan(0, strcmp($equal, 'i'));

		$reversed = Rank::between('z', 'a');
		self::assertGreaterThan(0, strcmp($reversed, 'z'));
	}

	/**
	 * A rank from outside this class — hand-edited, or restored from a backup
	 * of some other scheme — must not hang the request that reads it.
	 */
	public function testARankFromSomewhereElseStillGetsAnAnswer(): void {
		$rank = Rank::between('!!!', '!!!!');

		self::assertNotSame('', $rank);
	}
}
