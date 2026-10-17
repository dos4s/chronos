<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Core\Task;

use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Task\StandardStatuses;
use OCA\Chronos\Core\Task\StatusColumn;
use OCA\Chronos\Core\Task\StatusColumns;
use PHPUnit\Framework\TestCase;

/**
 * Which column a card belongs in, and which moves are allowed (§12.1).
 *
 * This is the one answer the board works from, whether it came from a workflow
 * or from the four standard statuses, so the rules it follows are the same on
 * an instance with the workflow module and on one without.
 */
class StatusColumnsTest extends TestCase {

	public function testACardSitsInTheColumnOfItsWorkflowStatus(): void {
		$columns = $this->workflow();
		$card = $this->card(statusId: 2, vtodoStatus: 'NEEDS-ACTION');

		// The workflow status wins over the standard one: the Chronos tables
		// are the authority on it (§7).
		self::assertSame('doing', $columns->columnFor($card)?->key);
	}

	public function testACardWithoutAWorkflowStatusFallsBackToItsStandardOne(): void {
		$columns = $this->workflow();

		self::assertSame('doing', $columns->columnFor($this->card(null, 'IN-PROCESS'))?->key);
		self::assertSame('todo', $columns->columnFor($this->card(null, 'NEEDS-ACTION'))?->key);
	}

	/**
	 * A completed card prefers a final column and an open one the initial
	 * column — the same preference the workflow uses when it reads a status
	 * another client wrote, so the board and the index agree.
	 */
	public function testAFinishedCardPrefersAFinalColumn(): void {
		$columns = new StatusColumns([
			new StatusColumn(1, 'solved', 'Solved', null, 'COMPLETED', false, true),
			new StatusColumn(2, 'closed', 'Closed', null, 'COMPLETED', false, true),
			new StatusColumn(3, 'archived', 'Archived', null, 'COMPLETED', false, false),
		]);

		self::assertSame('solved', $columns->columnFor($this->card(null, 'COMPLETED'))?->key);
	}

	/**
	 * Most clients write no `STATUS` at all until a task is started or
	 * finished — 40 of 229 tasks on the first real instance (D31) — and those
	 * cards belong at the start of the board, not nowhere.
	 */
	public function testACardWithNoStatusAtAllStartsInTheInitialColumn(): void {
		$columns = $this->workflow();

		self::assertSame('todo', $columns->columnFor($this->card(null, null))?->key);
	}

	/**
	 * A status id from a workflow this project no longer uses means nothing;
	 * the standard status the card carries still does.
	 */
	public function testACardWithAStatusFromAnotherWorkflowFallsBack(): void {
		$columns = $this->workflow();

		self::assertSame('doing', $columns->columnFor($this->card(999, 'IN-PROCESS'))?->key);
	}

	public function testWithNoRulesEveryMoveIsAllowed(): void {
		$columns = $this->workflow();

		self::assertTrue($columns->allows('todo', 'done'));
		self::assertTrue($columns->allows('done', 'todo'));
	}

	public function testWithRulesOnlyTheMovesWithARuleAreAllowed(): void {
		$columns = new StatusColumns($this->workflow()->columns, [
			['from' => 'todo', 'to' => 'doing', 'requiresComment' => false],
			['from' => 'doing', 'to' => 'done', 'requiresComment' => true],
		]);

		self::assertTrue($columns->allows('todo', 'doing'));
		self::assertFalse($columns->allows('todo', 'done'));
		// A card dropped where it already is has not moved at all.
		self::assertTrue($columns->allows('done', 'done'));
		self::assertTrue($columns->rule('doing', 'done')['requiresComment'] ?? false);
		self::assertNull($columns->rule('todo', 'done'));
	}

	public function testTheStandardFourAreAColumnEachAndNoneOfThemIsAWorkflowStatus(): void {
		$columns = new StatusColumns(StandardStatuses::columns());

		self::assertCount(4, $columns->columns);
		self::assertSame(
			['needs-action', 'in-process', 'completed', 'cancelled'],
			array_map(static fn (StatusColumn $column): string => $column->key, $columns->columns),
		);
		self::assertNull($columns->workflowId);
		foreach ($columns->columns as $column) {
			self::assertNull($column->statusId);
		}

		// A key must still be a key: `NEEDS-ACTION` would not pass the pattern
		// a workflow status key is checked against.
		foreach ($columns->columns as $column) {
			self::assertSame(1, preg_match('/^[a-z0-9_-]{1,64}$/', $column->key));
		}
	}

	public function testWithoutAWorkflowACardSitsInTheColumnOfItsStandardStatus(): void {
		$columns = new StatusColumns(StandardStatuses::columns());

		self::assertSame('completed', $columns->columnFor($this->card(null, 'COMPLETED'))?->key);
		self::assertSame('needs-action', $columns->columnFor($this->card(null, null))?->key);
		// A status id cannot mean anything without a workflow, so it is
		// ignored rather than leaving the card off the board.
		self::assertSame('in-process', $columns->columnFor($this->card(7, 'IN-PROCESS'))?->key);
	}

	public function testABoardWithNoColumnsPlacesNothing(): void {
		$columns = new StatusColumns([]);

		self::assertNull($columns->columnFor($this->card(null, 'NEEDS-ACTION')));
		self::assertNull($columns->initial());
	}

	private function workflow(): StatusColumns {
		return new StatusColumns([
			new StatusColumn(1, 'todo', 'To do', null, 'NEEDS-ACTION', true, false),
			new StatusColumn(2, 'doing', 'Doing', null, 'IN-PROCESS', false, false, 3),
			new StatusColumn(3, 'done', 'Done', null, 'COMPLETED', false, true),
		], [], 42);
	}

	private function card(?int $statusId, ?string $vtodoStatus): Task {
		$task = new Task();
		$task->setUid('card');
		$task->setStatusId($statusId);
		$task->setVtodoStatus($vtodoStatus);

		return $task;
	}
}
