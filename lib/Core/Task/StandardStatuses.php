<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Task;

/**
 * The four statuses iCalendar itself has (§7), as board columns.
 *
 * Without the workflow module the kanban uses these (§12.1), so a project is
 * a working board before anybody has built a workflow for it. They are also
 * what a workflow status maps onto, which is why the keys here are the
 * standard values lowercased: a column key is a column key, whichever side it
 * came from, and `NEEDS-ACTION` would not pass a workflow key's pattern.
 *
 * The names are English here and translated in the client, which knows these
 * four keys; a workflow's statuses are named by the user and are not
 * translatable at all.
 */
final class StandardStatuses {

	public const TODO = 'needs-action';
	public const IN_PROGRESS = 'in-process';
	public const DONE = 'completed';
	public const CANCELLED = 'cancelled';

	/**
	 * @return list<StatusColumn>
	 */
	public static function columns(): array {
		return [
			new StatusColumn(null, self::TODO, 'To do', null, 'NEEDS-ACTION', true, false),
			new StatusColumn(null, self::IN_PROGRESS, 'In progress', null, 'IN-PROCESS', false, false),
			new StatusColumn(null, self::DONE, 'Done', null, 'COMPLETED', false, true),
			new StatusColumn(null, self::CANCELLED, 'Cancelled', null, 'CANCELLED', false, true),
		];
	}
}
