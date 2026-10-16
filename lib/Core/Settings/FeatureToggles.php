<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Settings;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Feature toggles (§13).
 *
 * Two levels: the admin decides which modules exist on the instance, each user
 * decides which of those they want to see. A module is effective only when both
 * say yes. `core` and `time` are always on.
 */
class FeatureToggles {

	public const MODULE_PROJECTS = 'projects';
	public const MODULE_WORKFLOW = 'workflow';
	public const MODULE_KANBAN = 'kanban';
	public const MODULE_FIELDS = 'fields';
	public const MODULE_SLA = 'sla';
	public const MODULE_GANTT = 'gantt';
	public const MODULE_ROADMAP = 'roadmap';
	public const MODULE_SPRINTS = 'sprints';
	public const MODULE_MATRIX = 'matrix';

	/** Modules that cannot be switched off. */
	public const ALWAYS_ON = ['core', 'time'];

	/** Optional modules, in navigation order. */
	public const OPTIONAL = [
		self::MODULE_PROJECTS,
		self::MODULE_WORKFLOW,
		self::MODULE_KANBAN,
		self::MODULE_FIELDS,
		self::MODULE_SLA,
		self::MODULE_GANTT,
		self::MODULE_ROADMAP,
		self::MODULE_SPRINTS,
		self::MODULE_MATRIX,
	];

	/**
	 * Modules each module needs in order to work (§13). Every optional module
	 * is listed, so a lookup is always valid.
	 *
	 * @var array<string, list<string>>
	 */
	private const REQUIRES = [
		self::MODULE_PROJECTS => [],
		self::MODULE_WORKFLOW => [],
		self::MODULE_KANBAN => [],
		self::MODULE_FIELDS => [],
		self::MODULE_SLA => [self::MODULE_WORKFLOW],
		self::MODULE_GANTT => [],
		self::MODULE_ROADMAP => [self::MODULE_GANTT],
		self::MODULE_SPRINTS => [self::MODULE_GANTT],
		self::MODULE_MATRIX => [],
	];

	/**
	 * Which modules actually ship code yet. A module stays unavailable until
	 * its milestone lands, so a half-built module can never be switched on by
	 * accident; each milestone flips its own flag.
	 *
	 * @var array<string, bool>
	 */
	private const IMPLEMENTED = [
		self::MODULE_PROJECTS => false,
		self::MODULE_WORKFLOW => false,
		self::MODULE_KANBAN => false,
		self::MODULE_FIELDS => false,
		self::MODULE_SLA => false,
		self::MODULE_GANTT => false,
		self::MODULE_ROADMAP => false,
		self::MODULE_SPRINTS => false,
		self::MODULE_MATRIX => false,
	];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	/**
	 * Admin level: is the module available on this instance at all?
	 */
	public function isAvailable(string $module): bool {
		if (in_array($module, self::ALWAYS_ON, true)) {
			return true;
		}
		$this->assertKnown($module);
		// psalm: every flag below is still false, so it reads this as dead code.
		// The suppressions go away as soon as the first module ships.
		/** @psalm-suppress RedundantCondition */
		if (self::IMPLEMENTED[$module] !== true) {
			return false;
		}
		return $this->appConfig->getValueBool(Application::APP_ID, $this->adminKey($module));
	}

	/**
	 * User level: does this user want to see the module?
	 *
	 * Defaults to on, so enabling a module instance-wide shows it without every
	 * user having to opt in.
	 */
	public function isEnabledForUser(string $module, string $userId): bool {
		if (in_array($module, self::ALWAYS_ON, true)) {
			return true;
		}
		if (!$this->isAvailable($module)) {
			return false;
		}
		return $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			$this->userKey($module),
			'1',
		) === '1';
	}

	/**
	 * @return array<string, bool> every optional module → availability
	 */
	public function getAdminState(): array {
		$state = [];
		foreach (self::OPTIONAL as $module) {
			$state[$module] = $this->isAvailable($module);
		}
		return $state;
	}

	/**
	 * @return array<string, bool> every module → effective state for this user
	 */
	public function getUserState(string $userId): array {
		$state = [];
		foreach (self::ALWAYS_ON as $module) {
			$state[$module] = true;
		}
		foreach (self::OPTIONAL as $module) {
			$state[$module] = $this->isEnabledForUser($module, $userId);
		}
		return $state;
	}

	/**
	 * Enabling a module enables what it requires; disabling one also disables
	 * the modules that require it (§13).
	 *
	 * @throws ValidationFailedException
	 */
	public function setAvailable(string $module, bool $enabled): void {
		$this->assertKnown($module);
		/** @psalm-suppress RedundantCondition until the first optional module ships */
		if ($enabled && self::IMPLEMENTED[$module] !== true) {
			throw new ValidationFailedException('module_not_implemented');
		}

		$this->appConfig->setValueBool(Application::APP_ID, $this->adminKey($module), $enabled);

		if ($enabled) {
			foreach (self::REQUIRES[$module] as $required) {
				$this->setAvailable($required, true);
			}
			return;
		}

		foreach (self::REQUIRES as $dependant => $requirements) {
			if (in_array($module, $requirements, true) && $this->isAvailable($dependant)) {
				$this->setAvailable($dependant, false);
			}
		}
	}

	/**
	 * @throws ValidationFailedException
	 */
	public function setEnabledForUser(string $module, string $userId, bool $enabled): void {
		$this->assertKnown($module);
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			$this->userKey($module),
			$enabled ? '1' : '0',
		);
	}

	/**
	 * Only the modules that actually require something, for the UI.
	 *
	 * @return array<string, list<string>>
	 */
	public function getRequirements(): array {
		return array_filter(self::REQUIRES, static fn (array $required): bool => $required !== []);
	}

	/**
	 * @throws ValidationFailedException
	 */
	private function assertKnown(string $module): void {
		if (!in_array($module, self::OPTIONAL, true)) {
			throw new ValidationFailedException('unknown_module');
		}
	}

	private function adminKey(string $module): string {
		return 'module_' . $module;
	}

	private function userKey(string $module): string {
		return 'show_' . $module;
	}
}
