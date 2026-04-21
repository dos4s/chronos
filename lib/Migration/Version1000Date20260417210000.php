<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260417210000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('chronos_entries')) {
			$table = $schema->createTable('chronos_entries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('start_time', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('end_time', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('note', Types::STRING, [
				'notnull' => false,
				'length' => 500,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'ch_entries_user_idx');
			$table->addIndex(['user_id', 'end_time'], 'ch_entries_user_end_idx');
		}

		return $schema;
	}
}
