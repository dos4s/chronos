<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Time\Service;

use OCA\Chronos\Core\Error\ConflictException;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Time\Db\Entry;
use OCA\Chronos\Time\Db\EntryMapper;
use OCA\Chronos\Time\Db\Pause;
use OCA\Chronos\Time\Db\PauseMapper;
use OCA\Chronos\Time\Service\PauseNormalizer;
use OCA\Chronos\Time\Service\SessionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SessionServiceTest extends TestCase {

	private const USER = 'alice';
	private const NOW = 1_760_000_000;

	private IDBConnection&MockObject $db;
	private EntryMapper&MockObject $entryMapper;
	private PauseMapper&MockObject $pauseMapper;
	private ITimeFactory&MockObject $timeFactory;
	private SessionService $service;

	/** @var list<Pause> */
	private array $insertedPauses = [];
	private ?Entry $updatedEntry = null;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->entryMapper = $this->createMock(EntryMapper::class);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(self::NOW);

		$this->entryMapper->method('insert')->willReturnCallback(
			function (Entry $entry): Entry {
				$entry->setId(42);
				return $entry;
			},
		);
		$this->entryMapper->method('update')->willReturnCallback(
			function (Entry $entry): Entry {
				$this->updatedEntry = $entry;
				return $entry;
			},
		);
		$this->pauseMapper->method('insert')->willReturnCallback(
			function (Pause $pause): Pause {
				$pause->setId(count($this->insertedPauses) + 1);
				$this->insertedPauses[] = $pause;
				return $pause;
			},
		);

		$this->service = new SessionService(
			$this->db,
			$this->entryMapper,
			$this->pauseMapper,
			new PauseNormalizer(),
			$this->timeFactory,
		);
	}

	public function testCheckInStartsASessionNow(): void {
		$this->entryMapper->method('findActive')->willReturn(null);

		$session = $this->service->checkIn(self::USER, ' writing docs ', 'principals/users/alice/work', 'Work');

		self::assertSame(self::NOW, $session['startAt']);
		self::assertNull($session['endAt']);
		self::assertSame(0, $session['pausedSeconds']);
		self::assertSame('writing docs', $session['note']);
		self::assertSame('Work', $session['projectName']);
		self::assertSame([], $session['pauses']);
	}

	public function testCheckInWhileRunningIsRejected(): void {
		$this->entryMapper->method('findActive')->willReturn($this->runningEntry());
		$this->db->expects(self::once())->method('rollBack');

		$this->expectException(ConflictException::class);
		$this->service->checkIn(self::USER);
	}

	/**
	 * Two check-ins racing each other: the unique index rejects the second,
	 * which has to read as a conflict and not as a server error (D5).
	 */
	public function testConcurrentCheckInBecomesAConflict(): void {
		$this->entryMapper->method('findActive')->willReturn(null);
		$this->entryMapper = $this->createMock(EntryMapper::class);
		$this->entryMapper->method('findActive')->willReturn(null);
		// The OCP stub's getReason() always returns null, so the reason has to
		// come from a mock for the test to describe a real unique violation.
		$violation = $this->createMock(DbException::class);
		$violation->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->entryMapper->method('insert')->willThrowException($violation);

		$service = new SessionService(
			$this->db,
			$this->entryMapper,
			$this->pauseMapper,
			new PauseNormalizer(),
			$this->timeFactory,
		);

		$this->expectException(ConflictException::class);
		$service->checkIn(self::USER);
	}

	public function testCheckInMarksTheSessionActiveForTheUser(): void {
		$this->entryMapper->method('findActive')->willReturn(null);
		$inserted = null;
		$this->entryMapper = $this->createMock(EntryMapper::class);
		$this->entryMapper->method('findActive')->willReturn(null);
		$this->entryMapper->method('insert')->willReturnCallback(
			function (Entry $entry) use (&$inserted): Entry {
				$entry->setId(42);
				$inserted = $entry;
				return $entry;
			},
		);
		$service = new SessionService(
			$this->db,
			$this->entryMapper,
			$this->pauseMapper,
			new PauseNormalizer(),
			$this->timeFactory,
		);

		$service->checkIn(self::USER);

		self::assertInstanceOf(Entry::class, $inserted);
		self::assertSame(self::USER, $inserted->getActiveUserId());
	}

	public function testPauseRecordsAnOpenPause(): void {
		$this->entryMapper->method('findActive')->willReturn($this->runningEntry());
		$this->pauseMapper->method('findForEntry')->willReturn([]);

		$session = $this->service->pause(self::USER);

		self::assertSame(self::NOW, $session['pausedSinceAt']);
		self::assertSame(0, $session['pausedSeconds']);
		self::assertCount(1, $session['pauses']);
		self::assertSame(self::NOW, $session['pauses'][0]['startAt']);
		self::assertNull($session['pauses'][0]['endAt']);
	}

	public function testPauseWhileAlreadyPausedIsRejected(): void {
		$entry = $this->runningEntry();
		$entry->setPausedSinceAt(self::NOW - 60);
		$this->entryMapper->method('findActive')->willReturn($entry);

		$this->expectException(ConflictException::class);
		$this->service->pause(self::USER);
	}

	public function testPauseWithoutASessionIsRejected(): void {
		$this->entryMapper->method('findActive')->willReturn(null);

		try {
			$this->service->pause(self::USER);
			$this->fail('expected a conflict');
		} catch (ConflictException $e) {
			self::assertSame('no_active_session', $e->getErrorCode());
		}
	}

	public function testResumeClosesTheOpenPauseAndCountsIt(): void {
		$entry = $this->runningEntry();
		$entry->setPausedSinceAt(self::NOW - 300);
		$this->entryMapper->method('findActive')->willReturn($entry);
		$this->pauseMapper->method('findForEntry')->willReturn([
			$this->pause(self::NOW - 300, null),
		]);

		$session = $this->service->resume(self::USER);

		self::assertNull($session['pausedSinceAt']);
		self::assertSame(300, $session['pausedSeconds']);
		self::assertSame(self::NOW, $session['pauses'][0]['endAt']);
	}

	public function testResumeWithoutAPauseRowStillClosesThePause(): void {
		$entry = $this->runningEntry();
		$entry->setPausedSinceAt(self::NOW - 120);
		$this->entryMapper->method('findActive')->willReturn($entry);
		$this->pauseMapper->method('findForEntry')->willReturn([]);

		$session = $this->service->resume(self::USER);

		self::assertSame(120, $session['pausedSeconds']);
		self::assertNull($session['pausedSinceAt']);
	}

	public function testResumeWhenNotPausedIsRejected(): void {
		$this->entryMapper->method('findActive')->willReturn($this->runningEntry());

		try {
			$this->service->resume(self::USER);
			$this->fail('expected a conflict');
		} catch (ConflictException $e) {
			self::assertSame('not_paused', $e->getErrorCode());
		}
	}

	public function testCheckOutEndsTheSession(): void {
		$this->entryMapper->method('findActive')->willReturn($this->runningEntry());
		$this->pauseMapper->method('findForEntry')->willReturn([]);

		$session = $this->service->checkOut(self::USER);

		self::assertSame(self::NOW, $session['endAt']);
		self::assertInstanceOf(Entry::class, $this->updatedEntry);
		self::assertNull($this->updatedEntry->getActiveUserId());
	}

	/**
	 * The bug a quick check-out used to have: the pause was added to the total
	 * but never recorded, so the editor showed a session that disagreed with
	 * its own duration (D2).
	 */
	public function testCheckOutWhilePausedRecordsThePause(): void {
		$entry = $this->runningEntry();
		$entry->setPausedSinceAt(self::NOW - 600);
		$this->entryMapper->method('findActive')->willReturn($entry);
		$this->pauseMapper->method('findForEntry')->willReturn([
			$this->pause(self::NOW - 600, null),
		]);

		$session = $this->service->checkOut(self::USER);

		self::assertSame(600, $session['pausedSeconds']);
		self::assertNull($session['pausedSinceAt']);
		self::assertCount(1, $session['pauses']);
		self::assertSame(self::NOW - 600, $session['pauses'][0]['startAt']);
		self::assertSame(self::NOW, $session['pauses'][0]['endAt']);
	}

	public function testQuickCheckOutWithoutASessionDoesNothing(): void {
		$this->entryMapper->method('findActive')->willReturn(null);

		$this->service->checkOutIfRunning(self::USER);

		self::assertNull($this->updatedEntry);
	}

	public function testQuickCheckInWhileRunningDoesNothing(): void {
		$this->entryMapper->method('findActive')->willReturn($this->runningEntry());

		$this->service->checkInIfIdle(self::USER);

		self::assertSame([], $this->insertedPauses);
	}

	public function testUpdateRejectsMillisecondTimestamps(): void {
		try {
			$this->service->updateSession(self::USER, 1, 1_760_000_000_000, null, null, null);
			$this->fail('expected a validation failure');
		} catch (ValidationFailedException $e) {
			self::assertSame('timestamp_unit', $e->getErrorCode());
		}
	}

	public function testUpdateRejectsAnEndBeforeTheStart(): void {
		try {
			$this->service->updateSession(self::USER, 1, 2000, 1000, null, null);
			$this->fail('expected a validation failure');
		} catch (ValidationFailedException $e) {
			self::assertSame('invalid_times', $e->getErrorCode());
		}
	}

	public function testUpdateRejectsAnotherUsersSession(): void {
		$entry = $this->runningEntry();
		$entry->setUserId('bob');
		$entry->setEndAt(self::NOW);
		$this->entryMapper->method('find')->willReturn($entry);

		$this->expectException(ForbiddenException::class);
		$this->service->updateSession(self::USER, 42, 1000, 2000, null, null);
	}

	public function testUpdateOfAMissingSessionIsNotFound(): void {
		$this->entryMapper->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(NotFoundException::class);
		$this->service->updateSession(self::USER, 42, 1000, 2000, null, null);
	}

	public function testUpdateNormalizesEditedPauses(): void {
		$entry = $this->runningEntry();
		$entry->setEndAt(self::NOW);
		$this->entryMapper->method('find')->willReturn($entry);
		$this->entryMapper->method('findActive')->willReturn(null);

		$session = $this->service->updateSession(
			self::USER,
			42,
			1000,
			3000,
			[
				['start' => 1500, 'end' => 2000],
				['start' => 1900, 'end' => 2100],
				['start' => 500, 'end' => 900],
			],
			'edited',
		);

		self::assertCount(1, $session['pauses']);
		self::assertSame(1500, $session['pauses'][0]['startAt']);
		self::assertSame(2100, $session['pauses'][0]['endAt']);
		self::assertSame(600, $session['pausedSeconds']);
	}

	private function runningEntry(): Entry {
		$entry = new Entry();
		$entry->setId(42);
		$entry->setUserId(self::USER);
		$entry->setActiveUserId(self::USER);
		$entry->setStartAt(self::NOW - 3600);
		$entry->setPausedSeconds(0);
		return $entry;
	}

	private function pause(int $startAt, ?int $endAt): Pause {
		$pause = new Pause();
		$pause->setId(1);
		$pause->setEntryId(42);
		$pause->setStartAt($startAt);
		$pause->setEndAt($endAt);
		return $pause;
	}
}
