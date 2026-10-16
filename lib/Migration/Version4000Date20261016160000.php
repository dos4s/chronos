<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Areas and project settings (§10.2).
 *
 * A project is still a task list in CalDAV; these tables only hold what
 * iCalendar has nowhere to put — which area a project belongs to, which
 * workflow it uses, whether it is archived, and the order the user wants.
 * A row exists only for a project the user has actually configured (§6), so
 * an untouched list needs no row at all.
 */
class Version4000Date20261016160000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('chronos_areas')) {
			$table = $schema->createTable('chronos_areas');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('sort', Types::INTEGER, ['notnull' => true, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'sort'], 'ch_areas_user_idx');
			$changed = true;
		}

		if (!$schema->hasTable('chronos_projects')) {
			$table = $schema->createTable('chronos_projects');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);

			// The reference the time sessions already use (§6), so a session
			// and a configured project agree on what project they mean.
			$table->addColumn('project_uri', Types::STRING, ['notnull' => true, 'length' => 255]);

			$table->addColumn('area_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('parent_project_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('workflow_id', Types::BIGINT, ['notnull' => false]);

			// Module settings that are nobody else's business, e.g. the Gantt
			// reschedule mode (§10.2). JSON keeps a module from needing its own
			// column on a table it does not own.
			$table->addColumn('settings', Types::TEXT, ['notnull' => false]);

			$table->addColumn('archived', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('sort', Types::INTEGER, ['notnull' => true, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			// One settings row per project per user: the row is the project's
			// configuration, not a history of it.
			$table->addUniqueIndex(['user_id', 'project_uri'], 'ch_projects_uri_uniq');
			$table->addIndex(['user_id', 'area_id'], 'ch_projects_area_idx');
			$table->addIndex(['parent_project_id'], 'ch_projects_parent_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
