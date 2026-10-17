<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Card order on a board (§10.3, §15.5).
 *
 * A card dropped between two others gets a rank between theirs, so a move
 * writes one row rather than renumbering the column.
 *
 * Two names differ from the ones §10.3 first wrote down, both for reasons the
 * database gave:
 *
 * - **`calendar_id`, not `project_id`.** A row in `chronos_projects` exists
 *   only once somebody has configured that project, while every task list is a
 *   board; the calendar is what a task row already carries, and what a project
 *   ultimately is (§6).
 * - **`card_rank`, not `rank`.** `RANK` is a reserved word in MySQL 8, the
 *   same trap `status_key` avoided in M2.
 *
 * `column_key` is the column the rank was given in — a workflow status key, or
 * one of the four standard statuses. A card whose status has changed somewhere
 * else since carries a rank that belongs to a column it has left, and the
 * board ignores it rather than ordering the card by it.
 */
class Version6000Date20261017093000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('chronos_board_ranks')) {
			return null;
		}

		$table = $schema->createTable('chronos_board_ranks');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('calendar_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('task_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('column_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		$table->addColumn('card_rank', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);

		$table->setPrimaryKey(['id']);
		// A card has one position, so one row: a second would be two answers
		// to where it sits.
		$table->addUniqueIndex(['calendar_id', 'task_uid'], 'ch_ranks_task_uniq');
		// How a column is read: its cards in order.
		$table->addIndex(['calendar_id', 'column_key', 'card_rank'], 'ch_ranks_column_idx');

		return $schema;
	}
}
