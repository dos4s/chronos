<?php

declare(strict_types=1);

namespace OCA\Chronos\Planning;

use JsonSerializable;

/**
 * What a project says about its own timeline (§10.2, §12.5).
 *
 * Two things, and both of them have to be the project's rather than the user's:
 * what happens to the dependents when a task moves, and which days the project
 * works. They live in `chronos_projects.settings`, the JSON column that exists
 * so a module can keep a setting without a column on a table it does not own —
 * under a `plan` key, so the next module to want one does not have to care
 * about this one.
 *
 * The default is **warn**. A cascade rewrites tasks the user did not touch, and
 * a tool should not start doing that until it has been asked to.
 */
final class PlanSettings implements JsonSerializable {

	/** The move happens and the broken links are shown (§15.3). */
	public const MODE_WARN = 'warn';

	/** The dependents follow, in the same request (§15.6). */
	public const MODE_CASCADE = 'cascade';

	public const MODES = [self::MODE_WARN, self::MODE_CASCADE];

	/** The key this module's settings sit under in the shared JSON. */
	private const KEY = 'plan';

	/**
	 * @param list<int> $workingDays ISO weekdays, 1 = Monday
	 */
	public function __construct(
		public readonly string $mode = self::MODE_WARN,
		public readonly array $workingDays = WorkingDays::DEFAULT_DAYS,
	) {
	}

	public function cascades(): bool {
		return $this->mode === self::MODE_CASCADE;
	}

	/**
	 * The settings a stored JSON blob describes. Anything unreadable gives the
	 * defaults rather than an error: a timeline that will not open because one
	 * character of its settings is wrong is worse than one with the defaults.
	 */
	public static function fromJson(?string $json): self {
		$all = self::decode($json);
		/** @var mixed $mine */
		$mine = $all[self::KEY] ?? null;
		if (!is_array($mine)) {
			return new self();
		}

		/** @var mixed $mode */
		$mode = $mine['mode'] ?? null;
		/** @var mixed $days */
		$days = $mine['workingDays'] ?? null;

		return new self(
			is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::MODE_WARN,
			is_array($days) ? self::weekdays($days) : WorkingDays::DEFAULT_DAYS,
		);
	}

	/**
	 * These settings written back into the project's JSON, keeping whatever
	 * else is in there: the column belongs to every module at once.
	 */
	public function toJson(?string $existing): string {
		$all = self::decode($existing);
		$all[self::KEY] = $this->jsonSerialize();

		return json_encode($all, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array{mode: string, workingDays: list<int>}
	 */
	public function jsonSerialize(): array {
		return [
			'mode' => $this->mode,
			'workingDays' => $this->workingDays,
		];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private static function decode(?string $json): array {
		if ($json === null || trim($json) === '') {
			return [];
		}

		/** @var mixed $decoded */
		$decoded = json_decode($json, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<array-key, mixed> $days
	 * @return list<int>
	 */
	private static function weekdays(array $days): array {
		$valid = [];
		/** @var mixed $day */
		foreach ($days as $day) {
			$number = is_int($day) ? $day : (is_string($day) && ctype_digit($day) ? (int)$day : 0);
			if ($number >= 1 && $number <= 7 && !in_array($number, $valid, true)) {
				$valid[] = $number;
			}
		}
		sort($valid);

		return $valid;
	}
}
