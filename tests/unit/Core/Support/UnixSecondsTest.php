<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Support;

use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Support\UnixSeconds;
use PHPUnit\Framework\TestCase;

class UnixSecondsTest extends TestCase {

	public function testAcceptsSeconds(): void {
		UnixSeconds::assert(1760000000);
		UnixSeconds::assert(0);
		UnixSeconds::assert(null);
		$this->addToAssertionCount(3);
	}

	public function testRejectsMilliseconds(): void {
		$this->expectException(ValidationFailedException::class);
		UnixSeconds::assert(1760000000000);
	}

	public function testRejectedMillisecondsReportTheUnit(): void {
		try {
			UnixSeconds::assert(1760000000000);
			$this->fail('expected a validation failure');
		} catch (ValidationFailedException $e) {
			self::assertSame('timestamp_unit', $e->getErrorCode());
		}
	}

	public function testConvertsLegacyMilliseconds(): void {
		self::assertSame(1760000000, UnixSeconds::fromLegacy(1760000000123));
	}

	public function testLeavesLegacySecondsAlone(): void {
		self::assertSame(1760000000, UnixSeconds::fromLegacy(1760000000));
	}
}
