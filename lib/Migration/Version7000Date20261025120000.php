<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The task a session was worked on (§12.2, M5).
 *
 * Until now a session named a project and nothing else, so the half of the app
 * that plans the work and the half that times it never met: you planned a task
 * on the board, then walked to the timer and retyped what you were doing into a
 * free-text note. Nothing could answer what a task had cost.
 *
 * The uid is the task's, which is what everything else in the app joins on
 * (§10.1), and it is deliberately not a foreign key: a session is a record of
 * what somebody did, and it has to survive the task being deleted, moved to
 * another calendar or edited outside Chronos. `task_summary` is the name as it
 * read when the clock was running, kept for the same reason — a session from
 * March should still say what it was for after the task is renamed in April.
 */
class Version7000Date20261025120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chronos_entries')) {
			return null;
		}

		$table = $schema->getTable('chronos_entries');
		$changed = false;

		if (!$table->hasColumn('task_uid')) {
			$table->addColumn('task_uid', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}
		if (!$table->hasColumn('task_summary')) {
			$table->addColumn('task_summary', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}

		// What a task has cost is read per user and per task, which is the
		// only query this column is asked for.
		if (!$table->hasIndex('ch_entries_task_idx')) {
			$table->addIndex(['user_id', 'task_uid'], 'ch_entries_task_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
