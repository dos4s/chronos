<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Time\Request;

use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Time\Request\SessionUpdateRequest;
use PHPUnit\Framework\TestCase;

class SessionUpdateRequestTest extends TestCase {

	public function testParsesAFullBody(): void {
		$request = SessionUpdateRequest::fromParams(
			1000,
			2000,
			[['startAt' => 1200, 'endAt' => 1300]],
			'note',
		);

		self::assertSame(1000, $request->startAt);
		self::assertSame(2000, $request->endAt);
		self::assertSame([['start' => 1200, 'end' => 1300]], $request->pauses);
		self::assertSame('note', $request->note);
	}

	public function testKeepsAnOpenPause(): void {
		$request = SessionUpdateRequest::fromParams(1000, null, [['startAt' => 1200, 'endAt' => null]], null);

		self::assertSame([['start' => 1200, 'end' => null]], $request->pauses);
	}

	public function testTreatsAMissingEndAsOpen(): void {
		$request = SessionUpdateRequest::fromParams(1000, null, [['startAt' => 1200]], null);

		self::assertSame([['start' => 1200, 'end' => null]], $request->pauses);
	}

	public function testDistinguishesNoPausesFromAnEmptyList(): void {
		self::assertNull(SessionUpdateRequest::fromParams(1000, 2000, null, null)->pauses);
		self::assertSame([], SessionUpdateRequest::fromParams(1000, 2000, [], null)->pauses);
	}

	public function testAcceptsNumericStringsFromFormEncodedBodies(): void {
		$request = SessionUpdateRequest::fromParams(1000, 2000, [['startAt' => '1200', 'endAt' => '1300']], null);

		self::assertSame([['start' => 1200, 'end' => 1300]], $request->pauses);
	}

	public function testRejectsAPauseWithoutAStart(): void {
		$this->expectExceptionObject(new ValidationFailedException('invalid_pauses'));
		SessionUpdateRequest::fromParams(1000, 2000, [['endAt' => 1300]], null);
	}

	public function testRejectsANonNumericStart(): void {
		$this->expectExceptionObject(new ValidationFailedException('invalid_pauses'));
		SessionUpdateRequest::fromParams(1000, 2000, [['startAt' => 'soon']], null);
	}

	public function testRejectsANonNumericEnd(): void {
		$this->expectExceptionObject(new ValidationFailedException('invalid_pauses'));
		SessionUpdateRequest::fromParams(1000, 2000, [['startAt' => 1200, 'endAt' => 'later']], null);
	}

	public function testRejectsAPauseThatIsNotAnObject(): void {
		$this->expectExceptionObject(new ValidationFailedException('invalid_pauses'));
		SessionUpdateRequest::fromParams(1000, 2000, ['nonsense'], null);
	}

	public function testRejectsAbsurdlyManyPauses(): void {
		$pauses = array_fill(0, 201, ['startAt' => 1200, 'endAt' => 1300]);

		$this->expectExceptionObject(new ValidationFailedException('too_many_pauses'));
		SessionUpdateRequest::fromParams(1000, 2000, $pauses, null);
	}
}
