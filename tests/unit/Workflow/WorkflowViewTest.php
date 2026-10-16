<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Workflow;

use OCA\Chronos\Workflow\Db\Status;
use OCA\Chronos\Workflow\Db\Transition;
use OCA\Chronos\Workflow\Db\Workflow;
use OCA\Chronos\Workflow\WorkflowTemplates;
use OCA\Chronos\Workflow\WorkflowView;
use PHPUnit\Framework\TestCase;

class WorkflowViewTest extends TestCase {

	public function testTheInitialStatusIsTheOneMarkedInitial(): void {
		$view = $this->support();

		self::assertSame('new', $view->initialStatus()?->getStatusKey());
	}

	public function testWithoutAnInitialFlagTheFirstStatusStartsATask(): void {
		$view = new WorkflowView($this->workflow(), [
			$this->makeStatus(1, 'one', 'NEEDS-ACTION'),
			$this->makeStatus(2, 'two', 'IN-PROCESS'),
		], []);

		self::assertSame('one', $view->initialStatus()?->getStatusKey());
	}

	public function testACompletedTaskLandsInAFinalStatus(): void {
		// Support maps both "Solved" and "Closed" to COMPLETED; ticking a task
		// off should solve it, not close it, and both are final.
		self::assertSame('solved', $this->support()->statusForVtodo('COMPLETED')?->getStatusKey());
	}

	public function testAnOpenTaskLandsInTheInitialStatus(): void {
		self::assertSame('new', $this->support()->statusForVtodo('NEEDS-ACTION')?->getStatusKey());
	}

	public function testAStandardStatusWithSeveralNonFinalCandidatesTakesTheFirst(): void {
		self::assertSame('in_progress', $this->support()->statusForVtodo('IN-PROCESS')?->getStatusKey());
	}

	public function testAStandardStatusNoStatusMapsToHasNoAnswer(): void {
		$view = new WorkflowView($this->workflow(), [$this->makeStatus(1, 'one', 'NEEDS-ACTION')], []);

		self::assertNull($view->statusForVtodo('CANCELLED'));
	}

	public function testWithNoRulesEveryMoveIsAllowed(): void {
		$view = $this->support();

		self::assertTrue($view->allows(1, 8));
		self::assertTrue($view->allows(8, 1));
	}

	public function testWithRulesOnlyThoseMovesAreAllowed(): void {
		$view = new WorkflowView(
			$this->workflow(),
			[
				$this->makeStatus(1, 'todo', 'NEEDS-ACTION'),
				$this->makeStatus(2, 'doing', 'IN-PROCESS'),
				$this->makeStatus(3, 'done', 'COMPLETED'),
			],
			[$this->transition(1, 2), $this->transition(2, 3, true)],
		);

		self::assertTrue($view->allows(1, 2));
		self::assertTrue($view->allows(2, 3));
		self::assertFalse($view->allows(1, 3));
		// Staying put is never a transition.
		self::assertTrue($view->allows(2, 2));
		self::assertTrue($view->transition(2, 3)?->getRequiresComment() ?? false);
		self::assertNull($view->transition(1, 3));
	}

	public function testItFindsAStatusByKeyAndById(): void {
		$view = $this->support();

		self::assertSame(3, $view->statusByKey('in_progress')?->getId());
		self::assertSame('in_progress', $view->statusById(3)?->getStatusKey());
		self::assertNull($view->statusByKey('nonexistent'));
		self::assertNull($view->statusById(null));
	}

	/**
	 * The Support template as §12.1 sets it out, with ids 1..8 in order.
	 */
	private function support(): WorkflowView {
		$template = WorkflowTemplates::get(WorkflowTemplates::SUPPORT);
		self::assertNotNull($template);

		$statuses = [];
		$id = 1;
		foreach ($template['statuses'] as $definition) {
			$status = $this->makeStatus($id, $definition['key'], $definition['vtodo']);
			$status->setIsInitial($definition['initial'] ?? false);
			$status->setIsFinal($definition['final'] ?? false);
			$statuses[] = $status;
			$id++;
		}

		return new WorkflowView($this->workflow(), $statuses, []);
	}

	private function workflow(): Workflow {
		$workflow = new Workflow();
		$workflow->setId(1);
		$workflow->setUserId('alice');
		$workflow->setName('A workflow');

		return $workflow;
	}

	private function makeStatus(int $id, string $key, string $vtodo): Status {
		$status = new Status();
		$status->setId($id);
		$status->setWorkflowId(1);
		$status->setStatusKey($key);
		$status->setName(ucfirst($key));
		$status->setVtodoStatus($vtodo);
		$status->setSort($id);

		return $status;
	}

	private function transition(int $from, int $to, bool $requiresComment = false): Transition {
		$transition = new Transition();
		$transition->setWorkflowId(1);
		$transition->setFromStatusId($from);
		$transition->setToStatusId($to);
		$transition->setRequiresComment($requiresComment);

		return $transition;
	}
}
