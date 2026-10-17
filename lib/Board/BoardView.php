<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use JsonSerializable;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StatusColumn;
use OCA\Chronos\Core\Task\StatusColumns;

/**
 * A project's board: its columns, in order, with the cards in each (§12.1).
 *
 * The columns are the statuses — there is no column model (AD7) — so this is
 * the project, the statuses it can use, the rules it moves by, and the cards
 * arranged by rank. One response, because that is one screen.
 */
final class BoardView implements JsonSerializable {

	/**
	 * @param array<string, list<Task>> $cards column key → its cards, in order
	 */
	public function __construct(
		public readonly CalendarRef $project,
		public readonly StatusColumns $statuses,
		public readonly array $cards,
	) {
	}

	/**
	 * @return list<Task>
	 */
	public function column(string $key): array {
		return $this->cards[$key] ?? [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$columns = [];
		foreach ($this->statuses->columns as $column) {
			$cards = $this->column($column->key);
			$columns[] = $column->jsonSerialize() + [
				'count' => count($cards),
				// The limit is advisory: the board says so, the server does
				// not refuse the drop (§15.3).
				'overWipLimit' => self::isOver($column, count($cards)),
				'cards' => array_map(
					static fn (Task $card): array => $card->jsonSerialize(),
					$cards,
				),
			];
		}

		return [
			'project' => [
				'uri' => $this->project->ref,
				'calendarUri' => $this->project->uri,
				'name' => $this->project->name(),
				'color' => $this->project->color,
				'writable' => $this->project->isWritable,
			],
			'workflowId' => $this->statuses->workflowId,
			'rules' => $this->statuses->rules,
			'columns' => $columns,
		];
	}

	private static function isOver(StatusColumn $column, int $count): bool {
		return $column->wipLimit !== null && $column->wipLimit > 0 && $count > $column->wipLimit;
	}
}
