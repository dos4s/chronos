<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Dav;

use OCA\Chronos\Core\Dav\WriteRegistry;
use PHPUnit\Framework\TestCase;

class WriteRegistryTest extends TestCase {

	private WriteRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new WriteRegistry();
	}

	public function testRecognisesItsOwnWrite(): void {
		$this->registry->expect(7, 'task.ics');

		self::assertTrue($this->registry->claim(7, 'task.ics'));
	}

	public function testDoesNotRecogniseSomeoneElsesWrite(): void {
		self::assertFalse($this->registry->claim(7, 'task.ics'));
	}

	/**
	 * A later change to the same task is a different change, even though the
	 * object is the same (§11.1).
	 */
	public function testAWriteIsOnlyClaimedOnce(): void {
		$this->registry->expect(7, 'task.ics');

		self::assertTrue($this->registry->claim(7, 'task.ics'));
		self::assertFalse($this->registry->claim(7, 'task.ics'));
	}

	/**
	 * One request can write the same task twice — create it, then move it —
	 * and each write has an event of its own to claim.
	 */
	public function testTwoWritesToOneObjectAreTwoClaims(): void {
		$this->registry->expect(7, 'task.ics');
		$this->registry->expect(7, 'task.ics');

		self::assertTrue($this->registry->claim(7, 'task.ics'));
		self::assertTrue($this->registry->claim(7, 'task.ics'));
		self::assertFalse($this->registry->claim(7, 'task.ics'));
	}

	/**
	 * A write that threw never reached the backend, so no event will arrive to
	 * claim it. Left standing, it would make the next change from anywhere
	 * look like Chronos's own (D32).
	 */
	public function testAWriteThatFailedIsNotClaimedLater(): void {
		$this->registry->expect(7, 'task.ics');
		$this->registry->forget(7, 'task.ics');

		self::assertFalse($this->registry->claim(7, 'task.ics'));
	}

	public function testForgettingOneOfTwoWritesLeavesTheOther(): void {
		$this->registry->expect(7, 'task.ics');
		$this->registry->expect(7, 'task.ics');
		$this->registry->forget(7, 'task.ics');

		self::assertTrue($this->registry->claim(7, 'task.ics'));
		self::assertFalse($this->registry->claim(7, 'task.ics'));
	}

	public function testObjectsAreKeptApart(): void {
		$this->registry->expect(7, 'one.ics');
		$this->registry->expect(8, 'one.ics');

		self::assertFalse($this->registry->claim(7, 'two.ics'));
		self::assertTrue($this->registry->claim(8, 'one.ics'));
		self::assertTrue($this->registry->claim(7, 'one.ics'));
	}
}
