<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260417220000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chronos_entries')) {
			return null;
		}

		$table = $schema->getTable('chronos_entries');
		$changed = false;

		if (!$table->hasColumn('paused_at')) {
			$table->addColumn('paused_at', Types::BIGINT, [
				'notnull' => false,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('paused_duration')) {
			$table->addColumn('paused_duration', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
