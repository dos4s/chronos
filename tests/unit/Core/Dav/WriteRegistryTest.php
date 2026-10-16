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
		$this->registry->expect(7, 'task.ics', '"abc"');

		self::assertTrue($this->registry->claim(7, 'task.ics', '"abc"'));
	}

	public function testDoesNotRecogniseSomeoneElsesWrite(): void {
		self::assertFalse($this->registry->claim(7, 'task.ics', '"abc"'));
	}

	/**
	 * A later change to the same task is a different change, even though the
	 * object is the same (§11.1).
	 */
	public function testAWriteIsOnlyClaimedOnce(): void {
		$this->registry->expect(7, 'task.ics', '"abc"');

		self::assertTrue($this->registry->claim(7, 'task.ics', '"abc"'));
		self::assertFalse($this->registry->claim(7, 'task.ics', '"abc"'));
	}

	public function testADifferentEtagIsADifferentWrite(): void {
		$this->registry->expect(7, 'task.ics', '"abc"');

		self::assertFalse($this->registry->claim(7, 'task.ics', '"def"'));
	}

	public function testObjectsAreKeptApart(): void {
		$this->registry->expect(7, 'one.ics', '"abc"');
		$this->registry->expect(8, 'one.ics', '"abc"');

		self::assertFalse($this->registry->claim(7, 'two.ics', '"abc"'));
		self::assertTrue($this->registry->claim(8, 'one.ics', '"abc"'));
		self::assertTrue($this->registry->claim(7, 'one.ics', '"abc"'));
	}
}
