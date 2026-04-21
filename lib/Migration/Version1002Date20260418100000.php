<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1002Date20260418100000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chronos_entries')) {
			return null;
		}

		$table = $schema->getTable('chronos_entries');
		$changed = false;

		if (!$table->hasColumn('project_uri')) {
			$table->addColumn('project_uri', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('project_name')) {
			$table->addColumn('project_name', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		if ($changed && !$this->hasIndex($table, 'ch_entries_project_idx')) {
			$table->addIndex(['user_id', 'project_uri'], 'ch_entries_project_idx');
		}

		return $changed ? $schema : null;
	}

	private function hasIndex(\Doctrine\DBAL\Schema\Table $table, string $name): bool {
		foreach ($table->getIndexes() as $index) {
			if ($index->getName() === $name) {
				return true;
			}
		}
		return false;
	}
}
