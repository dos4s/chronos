<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The task index (§10.1).
 *
 * `chronos_tasks` is a rebuildable lookup of the fields Chronos queries; the
 * VTODOs in CalDAV remain the source of truth, and the reconcile job repairs
 * the index if the two ever disagree.
 *
 * Also drops `pauses_blob`, kept one release as a safety copy after the
 * seconds migration (§8.7, step 4).
 */
class Version3000Date20261016140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('chronos_tasks')) {
			$table = $schema->createTable('chronos_tasks');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);

			// Where the VTODO lives.
			$table->addColumn('calendar_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('calendar_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('object_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);

			// What the VTODO says.
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'task']);
			$table->addColumn('parent_uid', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('summary', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('vtodo_status', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('priority', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('percent', Types::SMALLINT, ['notnull' => false]);
			$table->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 64]);

			// Dates, per §8: seconds, with the kind of date preserved.
			$table->addColumn('start_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('due_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('start_is_date', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('due_is_date', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('completed_at', Types::BIGINT, ['notnull' => false]);

			// Filled by the modules that own them, once those ship.
			$table->addColumn('status_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('sprint_id', Types::BIGINT, ['notnull' => false]);

			// A soft delete keeps history that points at this task intact.
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);

			$table->setPrimaryKey(['id']);

			// CalDAV guarantees one object per URI in a calendar, which makes
			// this the key the listener looks a task up by. The UID is indexed
			// but not unique: a client could write a duplicate UID, and that
			// should not stop the index from recording it.
			$table->addUniqueIndex(['calendar_id', 'object_uri'], 'ch_tasks_obj_uniq');
			$table->addIndex(['calendar_id', 'uid'], 'ch_tasks_cal_uid_idx');
			$table->addIndex(['user_id', 'calendar_uri'], 'ch_tasks_user_cal_idx');
			$table->addIndex(['user_id', 'due_at'], 'ch_tasks_user_due_idx');
			$table->addIndex(['user_id', 'start_at'], 'ch_tasks_user_start_idx');
			$table->addIndex(['user_id', 'status_id'], 'ch_tasks_user_stat_idx');
			$table->addIndex(['parent_uid'], 'ch_tasks_parent_idx');
			$changed = true;
		}

		if (!$schema->hasTable('chronos_task_links')) {
			$table = $schema->createTable('chronos_task_links');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('calendar_id', Types::BIGINT, ['notnull' => true]);

			// The task whose VTODO carried the property. Re-indexing that task
			// replaces exactly its own rows, so a removed RELATED-TO really
			// disappears.
			$table->addColumn('source_uid', Types::STRING, ['notnull' => true, 'length' => 255]);

			// Normalised direction, per RFC 9253 (§7).
			$table->addColumn('predecessor_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('successor_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('reltype', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('gap_seconds', Types::BIGINT, ['notnull' => true, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['calendar_id', 'source_uid'], 'ch_links_source_idx');
			$table->addIndex(['user_id', 'predecessor_uid'], 'ch_links_pred_idx');
			$table->addIndex(['user_id', 'successor_uid'], 'ch_links_succ_idx');
			$changed = true;
		}

		if ($schema->hasTable('chronos_entries')) {
			$entries = $schema->getTable('chronos_entries');
			if ($entries->hasColumn('pauses_blob')) {
				// The pause rows have been the source of truth for a release.
				$entries->dropColumn('pauses_blob');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
