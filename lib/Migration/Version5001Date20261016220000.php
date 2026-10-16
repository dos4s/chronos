<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Gives `chronos_statuses.vtodo_status` a default (D30).
 *
 * `QBMapper` builds its insert from the fields an entity reports as changed,
 * and `Entity::setter()` reports no change when the value given is the one the
 * property already holds. A NOT NULL column with no database default then
 * refuses the row — which is what happened to every status whose standard
 * status was `NEEDS-ACTION`, the entity's own default.
 *
 * The entity no longer defaults to a real value, so this is the second half of
 * the fix: the column itself is now forgiving, as `chronos_tasks.type` and
 * `chronos_status_history.source` already were.
 */
class Version5001Date20261016220000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chronos_statuses')) {
			return null;
		}

		$table = $schema->getTable('chronos_statuses');
		if (!$table->hasColumn('vtodo_status')) {
			return null;
		}

		$column = $table->getColumn('vtodo_status');
		if ($column->getDefault() === 'NEEDS-ACTION') {
			return null;
		}

		$column->setDefault('NEEDS-ACTION');

		return $schema;
	}
}
