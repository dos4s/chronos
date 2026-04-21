<?php

declare(strict_types=1);

namespace OCA\Chronos\Dashboard;

use OCA\Chronos\AppInfo\Application;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

class ChronosWidget implements IWidget, IIconWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		return $this->l10n->t('Time Tracker');
	}

	public function getOrder(): int {
		return 50;
	}

	public function getIconClass(): string {
		return 'icon-chronos';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		);
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-dashboard');
	}
}
