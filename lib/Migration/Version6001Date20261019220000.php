<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The standard status on a history row (§10.3, §12.9).
 *
 * History was built for the workflow: every row named the workflow status a
 * task came from and went to, and `StatusHistoryListener` wrote nothing when
 * that status had not moved — which is every change on a project without a
 * workflow, and so, on a real instance, nearly every change there is.
 *
 * Flow measurement needs the other status: the `STATUS` of the VTODO itself,
 * which exists whether or not a workflow does. These two columns record it
 * beside the workflow's, so a board can answer how long a card has been where
 * it is. They are nullable because a row written before this migration has no
 * answer, and a guessed one would be worse than none: time in a state cannot be
 * worked out afterwards, which is why this lands before anything measures it.
 */
class Version6001Date20261019220000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chronos_status_history')) {
			return null;
		}

		$table = $schema->getTable('chronos_status_history');
		$changed = false;

		// `NEEDS-ACTION`, `IN-PROCESS`, `COMPLETED` or `CANCELLED` — RFC 5545's
		// four, which is the longest of them plus room.
		foreach (['from_standard', 'to_standard'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::STRING, ['notnull' => false, 'length' => 16]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
