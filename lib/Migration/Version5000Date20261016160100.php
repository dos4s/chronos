<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Workflows, statuses, transitions and status history (§10.3).
 *
 * A workflow status always maps to exactly one standard `STATUS`, so a task in
 * "Waiting for customer" is still `IN-PROCESS` to every other CalDAV client
 * (§12.1). Kanban columns are these statuses; there is no separate column
 * model.
 *
 * Status history is created now, before SLA exists, because time in a state
 * cannot be reconstructed afterwards.
 */
class Version5000Date20261016160100 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('chronos_workflows')) {
			$table = $schema->createTable('chronos_workflows');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// A template is a workflow kept to copy from rather than to use.
			$table->addColumn('is_template', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'ch_workflows_user_idx');
			$changed = true;
		}

		if (!$schema->hasTable('chronos_statuses')) {
			$table = $schema->createTable('chronos_statuses');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('workflow_id', Types::BIGINT, ['notnull' => true]);
			// `key` is reserved in MySQL, so the column says what kind of key.
			$table->addColumn('status_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('sort', Types::INTEGER, ['notnull' => true, 'default' => 0]);

			// The standard status every other client sees (§7).
			$table->addColumn('vtodo_status', Types::STRING, ['notnull' => true, 'length' => 16]);

			$table->addColumn('is_initial', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('is_final', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('sla_paused', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('auto_timer', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('requires_assignee', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('wip_limit', Types::INTEGER, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			// The key is how a VTODO names a status (`X-CHRONOS-STATUS`), so it
			// has to be unique within its workflow.
			$table->addUniqueIndex(['workflow_id', 'status_key'], 'ch_statuses_key_uniq');
			$table->addIndex(['workflow_id', 'sort'], 'ch_statuses_sort_idx');
			$changed = true;
		}

		if (!$schema->hasTable('chronos_transitions')) {
			$table = $schema->createTable('chronos_transitions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('workflow_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('from_status_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('to_status_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('requires_comment', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

			$table->setPrimaryKey(['id']);
			// No rows for a workflow means every transition is allowed (§10.3).
			$table->addUniqueIndex(['workflow_id', 'from_status_id', 'to_status_id'], 'ch_trans_uniq');
			$changed = true;
		}

		if (!$schema->hasTable('chronos_status_history')) {
			$table = $schema->createTable('chronos_status_history');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('task_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('from_status_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('to_status_id', Types::BIGINT, ['notnull' => false]);

			// The keys as they were at the time. The ids alone would stop
			// meaning anything the moment a workflow or a status is deleted,
			// and history is the one thing here that cannot be rebuilt.
			$table->addColumn('from_key', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('to_key', Types::STRING, ['notnull' => false, 'length' => 64]);

			$table->addColumn('from_assignee', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('to_assignee', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('changed_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			// `chronos` or `external`: which side of CalDAV the change came from.
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'chronos']);
			$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
			$table->addColumn('changed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['task_uid', 'changed_at'], 'ch_hist_task_idx');
			$table->addIndex(['to_status_id'], 'ch_hist_status_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
