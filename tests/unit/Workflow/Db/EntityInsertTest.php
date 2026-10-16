<?php

declare(strict_types=1);

namespace OCA\Chronos\Tests\Unit\Workflow\Db;

use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\Db\Project;
use OCA\Chronos\Workflow\Db\Status;
use OCA\Chronos\Workflow\Db\StatusChange;
use OCA\Chronos\Workflow\Db\Transition;
use OCA\Chronos\Workflow\Db\Workflow;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * The trap behind D30, as a unit test.
 *
 * `QBMapper` builds its insert from the fields an entity reports as changed,
 * and `Entity::setter()` reports no change when the value given is the one the
 * property already holds. So a property whose default is also a real value is
 * silently left out of the insert, and a NOT NULL column without a database
 * default refuses the row — on the database, never in a mock.
 *
 * Each case below writes the value most likely to collide with the entity's
 * own default and asserts the field would be sent.
 */
class EntityInsertTest extends TestCase {

	public function testAStatusSendsItsStandardStatusEvenWhenItIsTheCommonOne(): void {
		$status = new Status();
		$status->setWorkflowId(1);
		$status->setStatusKey('new');
		$status->setName('New');
		// The standard status of every initial status, and what broke the
		// first live insert.
		$status->setVtodoStatus('NEEDS-ACTION');
		$status->setSort(0);

		self::assertSent($status, ['workflowId', 'statusKey', 'name', 'vtodoStatus']);
	}

	public function testAStatusWithAnyOtherStandardStatusAlsoSendsIt(): void {
		$status = new Status();
		$status->setVtodoStatus('COMPLETED');

		self::assertSent($status, ['vtodoStatus']);
	}

	public function testAWorkflowSendsItsOwnerAndName(): void {
		$workflow = new Workflow();
		$workflow->setUserId('alice');
		$workflow->setName('Support');

		self::assertSent($workflow, ['userId', 'name']);
	}

	public function testATransitionSendsBothEndsEvenAtIdOne(): void {
		$transition = new Transition();
		$transition->setWorkflowId(1);
		$transition->setFromStatusId(1);
		$transition->setToStatusId(2);

		self::assertSent($transition, ['workflowId', 'fromStatusId', 'toStatusId']);
	}

	public function testAHistoryRowSendsTheTaskItIsAbout(): void {
		$change = new StatusChange();
		$change->setTaskUid('task-1');
		$change->setChangedAt(1_760_000_000);

		self::assertSent($change, ['taskUid', 'changedAt']);
	}

	public function testAnAreaSendsItsOwnerAndName(): void {
		$area = new Area();
		$area->setUserId('alice');
		$area->setName('Work');

		self::assertSent($area, ['userId', 'name']);
	}

	public function testAProjectSendsItsOwnerAndReference(): void {
		$project = new Project();
		$project->setUserId('alice');
		$project->setProjectUri('principals/users/alice/work');

		self::assertSent($project, ['userId', 'projectUri']);
	}

	/**
	 * @param list<string> $fields
	 */
	private static function assertSent(Entity $entity, array $fields): void {
		$updated = $entity->getUpdatedFields();

		foreach ($fields as $field) {
			self::assertArrayHasKey(
				$field,
				$updated,
				$field . ' would be left out of the insert, so a NOT NULL column without a default would refuse the row',
			);
		}
	}
}
