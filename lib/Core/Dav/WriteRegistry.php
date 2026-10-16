<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

/**
 * Remembers the writes Chronos has just made, so the listener can tell its own
 * echo from a change another client made (§11.1).
 *
 * Nextcloud dispatches the calendar-object event inside the same request that
 * wrote the object, so holding this in memory for the request is enough. A
 * write from another client is never in here, and is therefore recorded with
 * source `external`.
 */
class WriteRegistry {

	/** @var array<string, string> "calendarId:objectUri" → etag */
	private array $expected = [];

	public function expect(int $calendarId, string $objectUri, string $etag): void {
		$this->expected[$this->key($calendarId, $objectUri)] = $etag;
	}

	/**
	 * True when this exact write is one Chronos just made. The entry is
	 * consumed, so a later change to the same object is not mistaken for it.
	 */
	public function claim(int $calendarId, string $objectUri, string $etag): bool {
		$key = $this->key($calendarId, $objectUri);
		if (($this->expected[$key] ?? null) !== $etag) {
			return false;
		}

		unset($this->expected[$key]);
		return true;
	}

	private function key(int $calendarId, string $objectUri): string {
		return $calendarId . ':' . $objectUri;
	}
}
