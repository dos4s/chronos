<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Time\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Time\Db\Entry;
use OCA\Chronos\Time\Db\EntryMapper;
use OCA\Chronos\Time\Db\Pause;
use OCA\Chronos\Time\Db\PauseMapper;
use OCA\Chronos\Time\Service\AnalyticsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AnalyticsServiceTest extends TestCase {

	private const USER = 'alice';
	private const TZ = 'Europe/Madrid';

	private EntryMapper&MockObject $entryMapper;
	private PauseMapper&MockObject $pauseMapper;
	private ITimeFactory&MockObject $timeFactory;
	private AnalyticsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->entryMapper = $this->createMock(EntryMapper::class);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$this->pauseMapper->method('findForEntries')->willReturn([]);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));

		$this->service = new AnalyticsService(
			$this->entryMapper,
			$this->pauseMapper,
			$this->timeFactory,
			$dateTimeZone,
		);
	}

	public function testRejectsAnEmptyRange(): void {
		$this->expectExceptionObject(new ValidationFailedException('invalid_range'));
		$this->service->summary(self::USER, 2000, 2000, null);
	}

	public function testRejectsAnInvertedRange(): void {
		$this->expectExceptionObject(new ValidationFailedException('invalid_range'));
		$this->service->summary(self::USER, 3000, 2000, null);
	}

	public function testRejectsMillisecondTimestamps(): void {
		$this->expectExceptionObject(new ValidationFailedException('timestamp_unit'));
		$this->service->summary(self::USER, 1_760_000_000_000, 1_760_000_600_000, null);
	}

	public function testRejectsAnAbsurdlyLongRange(): void {
		$this->expectExceptionObject(new ValidationFailedException('range_too_large'));
		$this->service->summary(self::USER, 0, 10 * 366 * 86400, null);
	}

	public function testAddsUpSessionsAcrossTheWholeRange(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-12 09:00'), $this->at('2026-10-12 11:00'), 'work', 'Work'),
			$this->entry(2, $this->at('2026-10-13 09:00'), $this->at('2026-10-13 09:30'), 'work', 'Work'),
		]);

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-14 00:00'),
			self::TZ,
		);

		self::assertSame(9000, $summary['totalSeconds']);
		self::assertSame(2, $summary['sessionCount']);
		self::assertSame(self::TZ, $summary['timezone']);
		self::assertSame(
			[['date' => '2026-10-12', 'seconds' => 7200], ['date' => '2026-10-13', 'seconds' => 1800]],
			array_map(
				static fn (array $day): array => ['date' => $day['date'], 'seconds' => $day['seconds']],
				$summary['days'],
			),
		);
	}

	public function testExcludesPausedTime(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-12 09:00'), $this->at('2026-10-12 11:00'), 'work', 'Work'),
		]);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$this->pauseMapper->method('findForEntries')->willReturn([
			1 => [$this->pause($this->at('2026-10-12 10:00'), $this->at('2026-10-12 10:30'))],
		]);
		$service = new AnalyticsService(
			$this->entryMapper,
			$this->pauseMapper,
			$this->timeFactory,
			$this->zone(),
		);

		$summary = $service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-13 00:00'),
			self::TZ,
		);

		self::assertSame(5400, $summary['totalSeconds']);
	}

	public function testClipsSessionsToTheRequestedRange(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-11 23:00'), $this->at('2026-10-12 01:00'), 'work', 'Work'),
		]);

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-13 00:00'),
			self::TZ,
		);

		self::assertSame(3600, $summary['totalSeconds']);
	}

	public function testSplitsANightShiftAtLocalMidnight(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-12 23:00'), $this->at('2026-10-13 01:00'), 'work', 'Work'),
		]);

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-14 00:00'),
			self::TZ,
		);

		self::assertSame(
			[['date' => '2026-10-12', 'seconds' => 3600], ['date' => '2026-10-13', 'seconds' => 3600]],
			array_map(
				static fn (array $day): array => ['date' => $day['date'], 'seconds' => $day['seconds']],
				$summary['days'],
			),
		);
	}

	public function testBucketsByTheRequestedTimeZoneNotUtc(): void {
		// 23:30 UTC is already the next day in Madrid (UTC+2 in October).
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(
				1,
				(new DateTimeImmutable('2026-10-12 23:30', new DateTimeZone('UTC')))->getTimestamp(),
				(new DateTimeImmutable('2026-10-12 23:45', new DateTimeZone('UTC')))->getTimestamp(),
				'work',
				'Work',
			),
		]);

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-15 00:00'),
			self::TZ,
		);

		self::assertSame('2026-10-13', $summary['days'][0]['date']);
	}

	public function testCountsARunningSessionUpToNow(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-12 09:00'), null, 'work', 'Work'),
		]);
		$this->timeFactory->method('getTime')->willReturn($this->at('2026-10-12 09:45'));

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-13 00:00'),
			self::TZ,
		);

		self::assertSame(2700, $summary['totalSeconds']);
	}

	public function testGroupsByProjectAndSortsByTime(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-12 09:00'), $this->at('2026-10-12 09:30'), 'admin', 'Admin'),
			$this->entry(2, $this->at('2026-10-12 10:00'), $this->at('2026-10-12 12:00'), 'work', 'Work'),
		]);

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-13 00:00'),
			self::TZ,
		);

		self::assertSame('work', $summary['projects'][0]['projectUri']);
		self::assertSame(7200, $summary['projects'][0]['seconds']);
		self::assertSame('admin', $summary['projects'][1]['projectUri']);
		self::assertSame(1800, $summary['projects'][1]['seconds']);
	}

	public function testReportsSessionsWithoutAProject(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([
			$this->entry(1, $this->at('2026-10-12 09:00'), $this->at('2026-10-12 09:30'), null, null),
		]);

		$summary = $this->service->summary(
			self::USER,
			$this->at('2026-10-12 00:00'),
			$this->at('2026-10-13 00:00'),
			self::TZ,
		);

		self::assertNull($summary['projects'][0]['projectUri']);
		self::assertNull($summary['days'][0]['projects'][0]['projectUri']);
	}

	public function testFallsBackToTheUsersZoneForAnUnusableOne(): void {
		$this->entryMapper->method('findOverlapping')->willReturn([]);

		$summary = $this->service->summary(self::USER, 1000, 2000, 'Mars/Olympus_Mons');

		self::assertSame('UTC', $summary['timezone']);
	}

	private function zone(): IDateTimeZone {
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));
		return $zone;
	}

	private function at(string $local): int {
		return (new DateTimeImmutable($local, new DateTimeZone(self::TZ)))->getTimestamp();
	}

	private function entry(int $id, int $startAt, ?int $endAt, ?string $uri, ?string $name): Entry {
		$entry = new Entry();
		$entry->setId($id);
		$entry->setUserId(self::USER);
		$entry->setStartAt($startAt);
		$entry->setEndAt($endAt);
		$entry->setPausedSeconds(0);
		$entry->setProjectUri($uri);
		$entry->setProjectName($name);
		return $entry;
	}

	private function pause(int $startAt, ?int $endAt): Pause {
		$pause = new Pause();
		$pause->setId(1);
		$pause->setEntryId(1);
		$pause->setStartAt($startAt);
		$pause->setEndAt($endAt);
		return $pause;
	}
}
