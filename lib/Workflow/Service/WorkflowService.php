<?php

declare(strict_types=1);

namespace OCA\Chronos\Workflow\Service;

use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Index\TaskMapper;
use OCA\Chronos\Projects\Db\ProjectMapper;
use OCA\Chronos\Projects\Service\ProjectService;
use OCA\Chronos\Workflow\Db\Status;
use OCA\Chronos\Workflow\Db\StatusChangeMapper;
use OCA\Chronos\Workflow\Db\StatusMapper;
use OCA\Chronos\Workflow\Db\Transition;
use OCA\Chronos\Workflow\Db\TransitionMapper;
use OCA\Chronos\Workflow\Db\Workflow;
use OCA\Chronos\Workflow\Db\WorkflowMapper;
use OCA\Chronos\Workflow\WorkflowTemplates;
use OCA\Chronos\Workflow\WorkflowView;
use OCP\IDBConnection;
use Throwable;

/**
 * Workflows, statuses and transitions (§12.1).
 *
 * A workflow is created from a template and then belongs to the user: editing
 * it changes their copy and nothing else. Statuses keep their key and their
 * mapping to a standard `STATUS` for life — both are what other clients and
 * the index read — while names, colours, limits and flags are theirs to
 * change.
 */
class WorkflowService {

	private const MAX_NAME = 255;

	public function __construct(
		private WorkflowMapper $workflows,
		private StatusMapper $statuses,
		private TransitionMapper $transitions,
		private StatusChangeMapper $history,
		private ProjectMapper $projects,
		private ProjectService $projectService,
		private TaskMapper $tasks,
		private CalDavGateway $calendars,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function templates(): array {
		return WorkflowTemplates::describe();
	}

	/**
	 * Every workflow the user has, with its statuses and transitions, in two
	 * queries rather than two per workflow.
	 *
	 * @return list<WorkflowView>
	 */
	public function list(string $userId): array {
		$workflows = $this->workflows->findAll($userId);
		if ($workflows === []) {
			return [];
		}

		$ids = array_map(static fn (Workflow $workflow): int => $workflow->getId(), $workflows);
		$statuses = $this->groupByWorkflow($this->statuses->findForWorkflows($ids));
		$transitions = $this->groupByWorkflow($this->transitions->findForWorkflows($ids));

		$views = [];
		foreach ($workflows as $workflow) {
			$id = $workflow->getId();
			$views[] = new WorkflowView(
				$workflow,
				$statuses[$id] ?? [],
				$transitions[$id] ?? [],
			);
		}

		return $views;
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $id): WorkflowView {
		$workflow = $this->workflows->find($userId, $id);
		if ($workflow === null) {
			throw new NotFoundException('workflow_not_found');
		}

		return $this->view($workflow);
	}

	public function find(string $userId, int $id): ?WorkflowView {
		$workflow = $this->workflows->find($userId, $id);
		return $workflow === null ? null : $this->view($workflow);
	}

	/**
	 * The workflow a project uses, if it uses one.
	 */
	public function forProject(string $userId, string $projectRef): ?WorkflowView {
		$project = $this->projects->findByUri($userId, $projectRef);
		$workflowId = $project?->getWorkflowId();
		if ($workflowId === null) {
			return null;
		}

		return $this->find($userId, $workflowId);
	}

	/**
	 * @throws ValidationFailedException
	 */
	public function createFromTemplate(string $userId, string $template, ?string $name): WorkflowView {
		$definition = WorkflowTemplates::get($template);
		if ($definition === null) {
			throw new ValidationFailedException('unknown_template');
		}

		$workflow = new Workflow();
		$workflow->setUserId($userId);
		$workflow->setName($this->validName($name ?? $definition['name']));
		$workflow->setIsTemplate(false);

		$this->db->beginTransaction();
		try {
			$workflow = $this->workflows->insert($workflow);

			$sort = 0;
			foreach ($definition['statuses'] as $status) {
				$row = new Status();
				$row->setWorkflowId($workflow->getId());
				$row->setStatusKey($status['key']);
				$row->setName($status['name']);
				$row->setVtodoStatus($status['vtodo']);
				$row->setSort($sort);
				$row->setIsInitial($status['initial'] ?? false);
				$row->setIsFinal($status['final'] ?? false);
				$row->setSlaPaused($status['slaPaused'] ?? false);
				$row->setAutoTimer($status['autoTimer'] ?? false);
				$row->setRequiresAssignee($status['requiresAssignee'] ?? false);
				$this->statuses->insert($row);
				$sort++;
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// A template leaves every transition allowed: the statuses are in the
		// order work normally takes, and a rule nobody asked for would only
		// get in the way (§10.3).
		return $this->view($workflow);
	}

	/**
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function rename(string $userId, int $id, ?string $name): WorkflowView {
		$workflow = $this->workflows->find($userId, $id);
		if ($workflow === null) {
			throw new NotFoundException('workflow_not_found');
		}

		$workflow->setName($this->validName($name));

		return $this->view($this->workflows->update($workflow));
	}

	/**
	 * Deleting a workflow detaches the projects that used it — their tasks
	 * keep the standard `STATUS`, which never depended on it — and keeps the
	 * history, which is the one thing here that cannot be rebuilt. The history
	 * rows lose the status ids and keep the keys (§10.3).
	 *
	 * @throws NotFoundException
	 */
	public function delete(string $userId, int $id): void {
		$workflow = $this->workflows->find($userId, $id);
		if ($workflow === null) {
			throw new NotFoundException('workflow_not_found');
		}

		$statusIds = array_map(
			static fn (Status $status): int => $status->getId(),
			$this->statuses->findForWorkflow($id),
		);

		$this->db->beginTransaction();
		try {
			$this->history->forgetStatuses($statusIds);
			$this->transitions->deleteForWorkflow($id);
			$this->statuses->deleteForWorkflow($id);
			$this->projects->clearWorkflow($userId, $id);
			$this->workflows->delete($workflow);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * What a user may change about a status: how it reads and how it behaves.
	 * Not its key, and not which standard `STATUS` it maps to — the index and
	 * every other client read those, and changing one would quietly change
	 * what the tasks already in that status mean.
	 *
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function updateStatus(
		string $userId,
		int $workflowId,
		int $statusId,
		?string $name,
		?string $color,
		?int $wipLimit,
		?bool $autoTimer,
		?bool $requiresAssignee,
		?bool $slaPaused,
	): WorkflowView {
		$view = $this->get($userId, $workflowId);
		$status = $view->statusById($statusId);
		if ($status === null) {
			throw new NotFoundException('status_not_found');
		}

		if ($name !== null) {
			$status->setName($this->validName($name));
		}
		$status->setColor($this->validColor($color));
		$status->setWipLimit($wipLimit === null ? null : max(0, $wipLimit));
		if ($autoTimer !== null) {
			$status->setAutoTimer($autoTimer);
		}
		if ($requiresAssignee !== null) {
			$status->setRequiresAssignee($requiresAssignee);
		}
		if ($slaPaused !== null) {
			$status->setSlaPaused($slaPaused);
		}

		$this->statuses->update($status);

		return $this->get($userId, $workflowId);
	}

	/**
	 * Replaces a workflow's transition rules. An empty list means every move
	 * is allowed again (§10.3).
	 *
	 * @param list<array{from: int, to: int, requiresComment?: bool}> $rules
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function setTransitions(string $userId, int $workflowId, array $rules): WorkflowView {
		$view = $this->get($userId, $workflowId);

		$seen = [];
		$rows = [];
		foreach ($rules as $rule) {
			$from = $view->statusById($rule['from'] ?? null);
			$to = $view->statusById($rule['to'] ?? null);
			if ($from === null || $to === null) {
				throw new ValidationFailedException('unknown_status');
			}
			if ($from->getId() === $to->getId()) {
				// A status always allows staying in itself; a row saying so
				// would only be a rule that can never fail.
				continue;
			}

			$pair = $from->getId() . ':' . $to->getId();
			if (isset($seen[$pair])) {
				continue;
			}
			$seen[$pair] = true;

			$row = new Transition();
			$row->setWorkflowId($workflowId);
			$row->setFromStatusId($from->getId());
			$row->setToStatusId($to->getId());
			$row->setRequiresComment($rule['requiresComment'] ?? false);
			$rows[] = $row;
		}

		$this->db->beginTransaction();
		try {
			$this->transitions->deleteForWorkflow($workflowId);
			foreach ($rows as $row) {
				$this->transitions->insert($row);
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $this->get($userId, $workflowId);
	}

	/**
	 * Gives a project a workflow, or takes it away.
	 *
	 * The tasks that are already in the project get the status that matches
	 * the standard status they have, so attaching a workflow does not leave a
	 * list of tasks with no status until something re-reads them. Taking the
	 * workflow away forgets those statuses; the standard status, which every
	 * other client reads, never depended on it.
	 *
	 * @throws NotFoundException|ValidationFailedException
	 */
	public function assignToProject(string $userId, string $projectRef, ?int $workflowId): ?WorkflowView {
		$project = $this->projectService->requireRow($userId, $projectRef);
		$calendar = $this->calendars->findTaskCalendarByRef($userId, $projectRef);
		if ($calendar === null) {
			throw new NotFoundException('project_not_found');
		}

		$view = null;
		if ($workflowId !== null) {
			$view = $this->find($userId, $workflowId);
			if ($view === null) {
				throw new ValidationFailedException('unknown_workflow');
			}
		}

		$project->setWorkflowId($workflowId);
		$this->projects->update($project);

		if ($view === null) {
			$this->tasks->clearStatuses($calendar->id);
			return null;
		}

		foreach (['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'] as $vtodoStatus) {
			$status = $view->statusForVtodo($vtodoStatus);
			if ($status !== null) {
				$this->tasks->fillStatusWhereUnset($calendar->id, $vtodoStatus, $status->getId());
			}
		}

		return $view;
	}

	/**
	 * A task's status history, newest first (§10.3).
	 *
	 * @return list<\OCA\Chronos\Workflow\Db\StatusChange>
	 */
	public function historyFor(string $taskUid, int $limit = 100): array {
		return $this->history->findForTask($taskUid, $limit);
	}

	private function view(Workflow $workflow): WorkflowView {
		$id = $workflow->getId();

		return new WorkflowView(
			$workflow,
			$this->statuses->findForWorkflow($id),
			$this->transitions->findForWorkflow($id),
		);
	}

	/**
	 * @template T of Status|Transition
	 * @param list<T> $rows
	 * @return array<int, list<T>>
	 */
	private function groupByWorkflow(array $rows): array {
		$grouped = [];
		foreach ($rows as $row) {
			$grouped[$row->getWorkflowId()][] = $row;
		}

		return $grouped;
	}

	/**
	 * @throws ValidationFailedException
	 */
	private function validName(?string $name): string {
		$name = trim($name ?? '');
		if ($name === '') {
			throw new ValidationFailedException('name_required');
		}
		if (mb_strlen($name) > self::MAX_NAME) {
			throw new ValidationFailedException('name_too_long');
		}

		return $name;
	}

	/**
	 * @throws ValidationFailedException
	 */
	private function validColor(?string $color): ?string {
		$color = trim($color ?? '');
		if ($color === '') {
			return null;
		}
		if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
			throw new ValidationFailedException('invalid_color');
		}

		return strtolower($color);
	}
}
