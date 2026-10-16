<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Workflow;

use OCA\Chronos\Core\Dav\ParsedTask;
use OCA\Chronos\Workflow\WorkflowTemplates;
use PHPUnit\Framework\TestCase;

/**
 * The templates are a decision (Q4, §12.1), so they are tested as one: a
 * status that silently changed its mapping would change what tasks already in
 * it mean to every other client.
 */
class WorkflowTemplatesTest extends TestCase {

	public function testTheThreeTemplatesAreThere(): void {
		self::assertSame(
			['support', 'development', 'personal'],
			array_keys(WorkflowTemplates::all()),
		);
	}

	public function testSupportIsTheGlpiShapedOne(): void {
		$support = WorkflowTemplates::get(WorkflowTemplates::SUPPORT);

		self::assertNotNull($support);
		self::assertSame([
			'new',
			'assigned',
			'in_progress',
			'waiting_customer',
			'review',
			'solved',
			'closed',
			'rejected',
		], array_column($support['statuses'], 'key'));

		$byKey = array_column($support['statuses'], null, 'key');
		self::assertSame('NEEDS-ACTION', $byKey['new']['vtodo']);
		self::assertTrue($byKey['new']['initial']);
		self::assertTrue($byKey['assigned']['requiresAssignee']);
		self::assertTrue($byKey['in_progress']['autoTimer']);
		// Waiting for a customer stops the SLA clock but is still in process
		// to every other client.
		self::assertTrue($byKey['waiting_customer']['slaPaused']);
		self::assertSame('IN-PROCESS', $byKey['waiting_customer']['vtodo']);
		self::assertSame('CANCELLED', $byKey['rejected']['vtodo']);
		self::assertTrue($byKey['rejected']['final']);
	}

	public function testDevelopmentAndPersonalKeepTheirShape(): void {
		$development = WorkflowTemplates::get(WorkflowTemplates::DEVELOPMENT);
		$personal = WorkflowTemplates::get(WorkflowTemplates::PERSONAL);

		self::assertNotNull($development);
		self::assertNotNull($personal);
		self::assertSame(
			['backlog', 'ready', 'in_progress', 'review', 'done', 'dropped'],
			array_column($development['statuses'], 'key'),
		);
		self::assertSame(['todo', 'doing', 'done'], array_column($personal['statuses'], 'key'));
	}

	public function testEveryStatusMapsToOneStandardStatus(): void {
		foreach (WorkflowTemplates::all() as $key => $template) {
			foreach ($template['statuses'] as $status) {
				self::assertContains(
					$status['vtodo'],
					ParsedTask::VTODO_STATUSES,
					$key . '/' . $status['key'] . ' maps to no standard status',
				);
			}
		}
	}

	public function testEveryTemplateHasExactlyOneInitialStatusAndAtLeastOneFinalOne(): void {
		foreach (WorkflowTemplates::all() as $key => $template) {
			$initial = array_filter($template['statuses'], static fn (array $s): bool => $s['initial'] ?? false);
			$final = array_filter($template['statuses'], static fn (array $s): bool => $s['final'] ?? false);

			self::assertCount(1, $initial, $key . ' needs exactly one initial status');
			self::assertNotEmpty($final, $key . ' needs a final status');
		}
	}

	public function testTheDescriptionTheApiSendsNamesEveryFlag(): void {
		$described = WorkflowTemplates::describe();
		$support = $described[0];

		self::assertSame('support', $support['key']);
		self::assertSame('Support', $support['name']);
		self::assertSame([
			'key' => 'assigned',
			'name' => 'Assigned',
			'vtodoStatus' => 'NEEDS-ACTION',
			'isInitial' => false,
			'isFinal' => false,
			'slaPaused' => false,
			'autoTimer' => false,
			'requiresAssignee' => true,
		], $support['statuses'][1]);
	}

	public function testAnUnknownTemplateIsNull(): void {
		self::assertNull(WorkflowTemplates::get('kanban-ish'));
	}
}
