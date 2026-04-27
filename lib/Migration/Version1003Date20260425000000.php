<?php
declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1003Date20260425000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chronos_entries')) {
			$table = $schema->getTable('chronos_entries');
			if (!$table->hasColumn('pauses_blob')) {
				$table->addColumn('pauses_blob', 'text', [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
