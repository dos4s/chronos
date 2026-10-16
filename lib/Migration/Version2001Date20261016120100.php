<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the millisecond columns now that Version2000 has converted them (§8.7,
 * step 3).
 *
 * `pauses_blob` stays for one release as a safety copy and is dropped in M2.
 */
class Version2001Date20261016120100 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('chronos_entries')) {
			return null;
		}
		$table = $schema->getTable('chronos_entries');

		// Indexes first: dropping a column an index covers is not portable.
		if ($table->hasIndex('ch_entries_user_end_idx')) {
			$table->dropIndex('ch_entries_user_end_idx');
			$changed = true;
		}

		foreach (['start_time', 'end_time', 'paused_at', 'paused_duration'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
				$changed = true;
			}
		}

		// Every row has been converted, so the unit column can be required.
		if ($table->hasColumn('start_at')) {
			$column = $table->getColumn('start_at');
			if (!$column->getNotnull()) {
				$column->setNotnull(true);
				$column->setDefault(null);
				$changed = true;
			}
		}

		if (!$table->hasIndex('ch_entries_user_endat_idx')) {
			$table->addIndex(['user_id', 'end_at'], 'ch_entries_user_endat_idx');
			$changed = true;
		}
		if (!$table->hasIndex('ch_entries_user_start_idx')) {
			$table->addIndex(['user_id', 'start_at'], 'ch_entries_user_start_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
