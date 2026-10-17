<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Planning;

use OCA\Chronos\Planning\PlanSettings;
use OCA\Chronos\Planning\WorkingDays;
use PHPUnit\Framework\TestCase;

/**
 * A project's timeline settings, in the JSON column every module shares
 * (§10.2).
 */
class PlanSettingsTest extends TestCase {

	/**
	 * A cascade rewrites tasks the user did not touch, so it is not what a
	 * project does until somebody asks for it.
	 */
	public function testAProjectThatHasSaidNothingWarnsAndWorksWeekdays(): void {
		$settings = PlanSettings::fromJson(null);

		self::assertSame(PlanSettings::MODE_WARN, $settings->mode);
		self::assertSame(WorkingDays::DEFAULT_DAYS, $settings->workingDays);
		self::assertFalse($settings->cascades());
	}

	public function testItReadsBackWhatItWrote(): void {
		$written = (new PlanSettings(PlanSettings::MODE_CASCADE, [1, 3, 5]))->toJson(null);

		$settings = PlanSettings::fromJson($written);

		self::assertTrue($settings->cascades());
		self::assertSame([1, 3, 5], $settings->workingDays);
	}

	/** The column belongs to every module at once. */
	public function testWritingKeepsWhatAnotherModuleHadStoredThere(): void {
		$existing = '{"sla":{"policy":4}}';

		$written = (new PlanSettings(PlanSettings::MODE_CASCADE, []))->toJson($existing);

		self::assertStringContainsString('"sla"', $written);
		self::assertStringContainsString('"policy":4', $written);
		self::assertSame(PlanSettings::MODE_CASCADE, PlanSettings::fromJson($written)->mode);
	}

	/**
	 * A timeline that will not open because one character of its settings is
	 * wrong is worse than one with the defaults.
	 */
	public function testNonsenseGivesTheDefaultsRatherThanAnError(): void {
		foreach (['', 'not json', '[]', '{"plan":"yes"}', '{"plan":{"mode":"sideways"}}'] as $json) {
			self::assertSame(PlanSettings::MODE_WARN, PlanSettings::fromJson($json)->mode, $json);
		}
	}

	public function testWeekdaysThatAreNotWeekdaysAreDropped(): void {
		$settings = PlanSettings::fromJson('{"plan":{"mode":"warn","workingDays":[0,"3",9,1,1]}}');

		self::assertSame([1, 3], $settings->workingDays);
	}

	/** An empty list is a project that works every day, and is stored as one. */
	public function testAnEmptyWorkingWeekSurvivesARoundTrip(): void {
		$written = (new PlanSettings(PlanSettings::MODE_WARN, []))->toJson(null);

		self::assertSame([], PlanSettings::fromJson($written)->workingDays);
	}
}
