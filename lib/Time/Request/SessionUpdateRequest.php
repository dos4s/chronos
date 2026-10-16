<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Request;

use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Time\Service\PauseNormalizer;

/**
 * The body of a session edit, validated once on the way in (D13).
 *
 * @psalm-import-type PauseShape from PauseNormalizer
 */
final class SessionUpdateRequest {

	/** A session cannot hold more pauses than a long day could contain. */
	private const MAX_PAUSES = 200;

	/**
	 * @param list<PauseShape>|null $pauses
	 */
	private function __construct(
		public readonly int $startAt,
		public readonly ?int $endAt,
		public readonly ?array $pauses,
		public readonly ?string $note,
	) {
	}

	/**
	 * @throws ValidationFailedException
	 */
	public static function fromParams(
		int $startAt,
		?int $endAt,
		?array $pauses,
		?string $note,
	): self {
		return new self($startAt, $endAt, self::parsePauses($pauses), $note);
	}

	/**
	 * @return list<PauseShape>|null
	 * @throws ValidationFailedException
	 */
	private static function parsePauses(?array $pauses): ?array {
		if ($pauses === null) {
			return null;
		}
		if (count($pauses) > self::MAX_PAUSES) {
			throw new ValidationFailedException('too_many_pauses');
		}

		$parsed = [];
		foreach ($pauses as $pause) {
			if (!is_array($pause) || !array_key_exists('startAt', $pause)) {
				throw new ValidationFailedException('invalid_pauses');
			}

			$start = self::toInt($pause['startAt']);
			if ($start === null) {
				throw new ValidationFailedException('invalid_pauses');
			}

			$hasEnd = array_key_exists('endAt', $pause) && $pause['endAt'] !== null;
			$end = $hasEnd ? self::toInt($pause['endAt']) : null;
			if ($hasEnd && $end === null) {
				throw new ValidationFailedException('invalid_pauses');
			}

			$parsed[] = ['start' => $start, 'end' => $end];
		}

		return $parsed;
	}

	private static function toInt(mixed $value): ?int {
		if (is_int($value)) {
			return $value;
		}
		if (is_float($value) && $value === floor($value)) {
			return (int)$value;
		}
		if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
			return (int)$value;
		}
		return null;
	}
}
