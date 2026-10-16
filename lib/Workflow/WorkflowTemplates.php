<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow;

/**
 * The workflows Chronos offers to start from (§12.1, Q4).
 *
 * Templates live in code rather than in the database: they are the same on
 * every instance, and a user who edits one is editing their own copy. Each
 * status maps to exactly one standard `STATUS`, so a task stays meaningful in
 * every other CalDAV client.
 */
final class WorkflowTemplates {

	public const SUPPORT = 'support';
	public const DEVELOPMENT = 'development';
	public const PERSONAL = 'personal';

	/**
	 * @return array<string, array{name: string, statuses: list<array{key: string, name: string, vtodo: string, initial?: bool, final?: bool, slaPaused?: bool, autoTimer?: bool, requiresAssignee?: bool}>}>
	 */
	public static function all(): array {
		return [
			// The GLPI-shaped one: a ticket arrives, gets an owner, waits on
			// somebody, and ends up solved, closed or rejected.
			self::SUPPORT => [
				'name' => 'Support',
				'statuses' => [
					['key' => 'new', 'name' => 'New', 'vtodo' => 'NEEDS-ACTION', 'initial' => true],
					['key' => 'assigned', 'name' => 'Assigned', 'vtodo' => 'NEEDS-ACTION', 'requiresAssignee' => true],
					['key' => 'in_progress', 'name' => 'In progress', 'vtodo' => 'IN-PROCESS', 'autoTimer' => true],
					['key' => 'waiting_customer', 'name' => 'Waiting for customer', 'vtodo' => 'IN-PROCESS', 'slaPaused' => true],
					['key' => 'review', 'name' => 'Review', 'vtodo' => 'IN-PROCESS'],
					['key' => 'solved', 'name' => 'Solved', 'vtodo' => 'COMPLETED', 'final' => true],
					['key' => 'closed', 'name' => 'Closed', 'vtodo' => 'COMPLETED', 'final' => true],
					['key' => 'rejected', 'name' => 'Rejected', 'vtodo' => 'CANCELLED', 'final' => true],
				],
			],
			self::DEVELOPMENT => [
				'name' => 'Development',
				'statuses' => [
					['key' => 'backlog', 'name' => 'Backlog', 'vtodo' => 'NEEDS-ACTION', 'initial' => true],
					['key' => 'ready', 'name' => 'Ready', 'vtodo' => 'NEEDS-ACTION'],
					['key' => 'in_progress', 'name' => 'In progress', 'vtodo' => 'IN-PROCESS', 'autoTimer' => true],
					['key' => 'review', 'name' => 'Review', 'vtodo' => 'IN-PROCESS'],
					['key' => 'done', 'name' => 'Done', 'vtodo' => 'COMPLETED', 'final' => true],
					['key' => 'dropped', 'name' => 'Dropped', 'vtodo' => 'CANCELLED', 'final' => true],
				],
			],
			self::PERSONAL => [
				'name' => 'Personal',
				'statuses' => [
					['key' => 'todo', 'name' => 'To do', 'vtodo' => 'NEEDS-ACTION', 'initial' => true],
					['key' => 'doing', 'name' => 'Doing', 'vtodo' => 'IN-PROCESS', 'autoTimer' => true],
					['key' => 'done', 'name' => 'Done', 'vtodo' => 'COMPLETED', 'final' => true],
				],
			],
		];
	}

	/**
	 * @return array{name: string, statuses: list<array{key: string, name: string, vtodo: string, initial?: bool, final?: bool, slaPaused?: bool, autoTimer?: bool, requiresAssignee?: bool}>}|null
	 */
	public static function get(string $key): ?array {
		return self::all()[$key] ?? null;
	}

	/**
	 * The templates as the API reports them, so the UI can offer them without
	 * knowing their shape.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function describe(): array {
		$described = [];
		foreach (self::all() as $key => $template) {
			$described[] = [
				'key' => $key,
				'name' => $template['name'],
				'statuses' => array_map(
					static fn (array $status): array => [
						'key' => $status['key'],
						'name' => $status['name'],
						'vtodoStatus' => $status['vtodo'],
						'isInitial' => $status['initial'] ?? false,
						'isFinal' => $status['final'] ?? false,
						'slaPaused' => $status['slaPaused'] ?? false,
						'autoTimer' => $status['autoTimer'] ?? false,
						'requiresAssignee' => $status['requiresAssignee'] ?? false,
					],
					$template['statuses'],
				),
			];
		}

		return $described;
	}
}
