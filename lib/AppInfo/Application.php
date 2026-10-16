<?php

declare(strict_types=1);

namespace OCA\Chronos\AppInfo;

use OCA\Chronos\Core\Event\TaskAssignedEvent;
use OCA\Chronos\Core\Event\TaskStatusChangedEvent;
use OCA\Chronos\Core\Index\CalendarObjectListener;
use OCA\Chronos\Core\Middleware\ApiErrorMiddleware;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Task\NoWorkflow;
use OCA\Chronos\Core\Task\StatusGateway;
use OCA\Chronos\Dashboard\ChronosWidget;
use OCA\Chronos\Workflow\Listener\StatusHistoryListener;
use OCA\Chronos\Workflow\Service\WorkflowStatusGateway;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'chronos';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(ChronosWidget::class);
		// Turns typed Chronos exceptions into HTTP responses in one place (§14.1).
		$context->registerMiddleware(ApiErrorMiddleware::class);

		// Which statuses a task can be in is the workflow module's to answer,
		// and the task layer asks through this one interface either way (§5).
		// Resolved when it is first needed, not while registering, so reading
		// the toggle never happens before the container is ready.
		$context->registerService(StatusGateway::class, static function (ContainerInterface $c): StatusGateway {
			/** @var FeatureToggles $toggles */
			$toggles = $c->get(FeatureToggles::class);
			/** @var StatusGateway $gateway */
			$gateway = $toggles->isAvailable(FeatureToggles::MODULE_WORKFLOW)
				? $c->get(WorkflowStatusGateway::class)
				: $c->get(NoWorkflow::class);

			return $gateway;
		});

		// Status history, which cannot be reconstructed later and is therefore
		// written from the first release that has statuses (§10.3). The
		// listener checks the module itself: registration happens before the
		// container can read config.
		$context->registerEventListener(TaskStatusChangedEvent::class, StatusHistoryListener::class);
		$context->registerEventListener(TaskAssignedEvent::class, StatusHistoryListener::class);

		// Whoever writes a task — this app, the Tasks app, a phone over DAVx5 —
		// the index follows (§11.2). These events are public API since
		// Nextcloud 32, the minimum, so no adapter is needed (AD10).
		foreach ([
			CalendarObjectCreatedEvent::class,
			CalendarObjectUpdatedEvent::class,
			CalendarObjectDeletedEvent::class,
			CalendarObjectMovedEvent::class,
			CalendarObjectMovedToTrashEvent::class,
			CalendarObjectRestoredEvent::class,
		] as $event) {
			$context->registerEventListener($event, CalendarObjectListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
