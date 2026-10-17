<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

/**
 * Card order as a lexicographic rank (§15.5).
 *
 * A card dropped between two others is given a rank that sorts between
 * theirs, so a move writes one row instead of renumbering the column. The
 * ranks are compared as plain strings, by the database and by PHP alike, and
 * there is always room for one more card between any two.
 *
 * The alphabet is digits and lowercase letters, in ASCII order, and nothing
 * else: a case-insensitive database collation — which is the default on MySQL —
 * would make `A` and `a` the same rank, and a rank that is not unique within
 * its column is a card whose position depends on the query plan.
 *
 * A rank is a fraction written without its leading `0.`: `i` is the middle of
 * the range, `9` is below it, `r` above it. That is why no rank is ever empty
 * and why the digits that are missing from the shorter of two ranks count as
 * the bottom of the range rather than as nothing.
 */
final class Rank {

	public const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';
	public const BASE = 36;

	/** A rank longer than this has the rebalance job rewrite its column. */
	public const MAX_LENGTH = 32;

	/** The column is rewritten from scratch above this, never beyond it. */
	public const HARD_LIMIT = 64;

	/**
	 * A rank that sorts between the two given ones.
	 *
	 * Null stands for the end of the column: `between(null, $first)` is a rank
	 * above nothing and below the first card, `between($last, null)` one below
	 * nothing. `between(null, null)` is the rank of the only card there is.
	 *
	 * Two ranks that are equal, or the wrong way round, describe a gap that
	 * does not exist — a board read from a stale page, or a column whose ranks
	 * were written by two requests at once. Rather than return a rank inside
	 * somebody else's gap, this puts the card after the lower of the two; the
	 * column is still in a consistent order afterwards, which is what matters.
	 */
	public static function between(?string $before, ?string $after): string {
		if ($before !== null && $after !== null && strcmp($before, $after) >= 0) {
			return self::between($before, null);
		}

		$lower = $before ?? '';
		$upper = $after ?? '';

		$rank = '';
		$free = $upper === '';

		for ($i = 0; $i < self::HARD_LIMIT; $i++) {
			// The digits past the end of the lower bound are the bottom of the
			// range; past the end of the upper bound, the top of it.
			$low = self::digitAt($lower, $i, 0);
			$high = $free ? self::BASE : self::digitAt($upper, $i, self::BASE);

			if ($low === $high) {
				$rank .= self::char($low);
				continue;
			}

			if ($high - $low > 1) {
				return $rank . self::char(intdiv($low + $high, 2));
			}

			// The two digits are neighbours, so there is no room at this
			// position. Taking the lower one keeps the rank under the upper
			// bound whatever follows, which is what makes the rest free.
			$rank .= self::char($low);
			$free = true;
		}

		// Unreachable with ranks this class wrote: every step above either
		// returns or narrows the gap, and the rebalance job keeps ranks far
		// shorter than this. A rank from somewhere else still gets an answer
		// rather than a request that never ends.
		return $rank . self::char(intdiv(self::BASE, 2));
	}

	/**
	 * Ranks for a column of `$count` cards, evenly spaced.
	 *
	 * This is what a column gets the first time a card is dropped into it, and
	 * what the rebalance job writes: the gaps are as wide as the alphabet
	 * allows, so the ranks stay short for a long time afterwards.
	 *
	 * @return list<string>
	 */
	public static function sequence(int $count): array {
		if ($count < 1) {
			return [];
		}

		// Wide enough that every card gets a gap of its own, and one digit
		// wider than that so there is somewhere to drop between them.
		$width = 2;
		while (self::BASE ** $width <= ($count + 1) * self::BASE) {
			$width++;
		}

		$span = self::BASE ** $width;
		$step = intdiv($span, $count + 1);

		$ranks = [];
		for ($i = 1; $i <= $count; $i++) {
			$ranks[] = self::encode($i * $step, $width);
		}

		return $ranks;
	}

	/**
	 * Whether a rank has grown long enough to be worth rewriting. Dropping a
	 * card into the same gap over and over adds a digit each time, which costs
	 * nothing until the column is read on a phone.
	 */
	public static function isLong(string $rank): bool {
		return strlen($rank) > self::MAX_LENGTH;
	}

	/**
	 * `$value` as `$width` digits of the alphabet, most significant first.
	 */
	private static function encode(int $value, int $width): string {
		$digits = '';
		for ($i = 0; $i < $width; $i++) {
			$digits = self::char($value % self::BASE) . $digits;
			$value = intdiv($value, self::BASE);
		}

		// A rank never ends in the bottom digit: it would leave no room below
		// itself without growing, and the trailing zeros say nothing.
		$trimmed = rtrim($digits, self::ALPHABET[0]);

		return $trimmed === '' ? self::ALPHABET[1] : $trimmed;
	}

	private static function digitAt(string $rank, int $index, int $beyond): int {
		if ($index >= strlen($rank)) {
			return $beyond;
		}

		$position = strpos(self::ALPHABET, $rank[$index]);

		// A rank with a character from outside the alphabet cannot be ordered
		// against anything, so it counts as the bottom of the range.
		return $position === false ? 0 : $position;
	}

	private static function char(int $digit): string {
		return self::ALPHABET[max(0, min(self::BASE - 1, $digit))];
	}
}
