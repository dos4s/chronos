<?php

declare(strict_types=1);

namespace OCA\Chronos\AppInfo;

use OCA\Chronos\Dashboard\ChronosWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'chronos';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(ChronosWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
