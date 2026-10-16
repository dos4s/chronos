<?php

declare(strict_types=1);

namespace OCA\Chronos\AppInfo;

use OCA\Chronos\Core\Index\CalendarObjectListener;
use OCA\Chronos\Core\Middleware\ApiErrorMiddleware;
use OCA\Chronos\Dashboard\ChronosWidget;
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
