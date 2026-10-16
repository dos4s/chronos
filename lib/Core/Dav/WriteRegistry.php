<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Dav;

/**
 * Remembers the writes Chronos is making, so the listener can tell its own
 * echo from a change another client made (§11.1).
 *
 * **Announced before the write, not after.** `CalDavBackend` dispatches the
 * calendar-object event from inside the call that writes the object, so
 * anything recorded afterwards arrives too late — which is exactly what made
 * every Chronos write look external (D32). That is also why the etag is not
 * part of the key: it does not exist until the write has happened.
 *
 * Nextcloud dispatches that event in the same request that wrote the object,
 * so holding this in memory for the request is enough. A write from another
 * client is never in here, and is therefore recorded with source `external`.
 */
class WriteRegistry {

	/** @var array<string, int> "calendarId:objectUri" → writes in flight */
	private array $expected = [];

	/**
	 * Chronos is about to write this object. Counted, because one request may
	 * write the same object more than once and each write has its own event.
	 */
	public function expect(int $calendarId, string $objectUri): void {
		$key = $this->key($calendarId, $objectUri);
		$this->expected[$key] = ($this->expected[$key] ?? 0) + 1;
	}

	/**
	 * The write never happened — it threw — so nothing should claim it. Left
	 * standing, it would make the next change to this object from anywhere
	 * look like Chronos's own.
	 */
	public function forget(int $calendarId, string $objectUri): void {
		$key = $this->key($calendarId, $objectUri);
		$remaining = ($this->expected[$key] ?? 0) - 1;

		if ($remaining > 0) {
			$this->expected[$key] = $remaining;
			return;
		}

		unset($this->expected[$key]);
	}

	/**
	 * True when this change is one Chronos is making. The entry is consumed,
	 * so a later change to the same object is not mistaken for it.
	 */
	public function claim(int $calendarId, string $objectUri): bool {
		$key = $this->key($calendarId, $objectUri);
		if (($this->expected[$key] ?? 0) < 1) {
			return false;
		}

		$this->forget($calendarId, $objectUri);

		return true;
	}

	private function key(int $calendarId, string $objectUri): string {
		return $calendarId . ':' . $objectUri;
	}
}
