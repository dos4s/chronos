<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCA\Chronos\AppInfo\Application;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;

/**
 * Gets a user's existing task lists into the index the first time they open
 * Chronos, without making them wait for it (§16).
 */
class IndexBootstrap {

	private const IMPORT_QUEUED = 'index_import_queued';

	public function __construct(
		private TaskMapper $tasks,
		private IJobList $jobList,
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * Queues the import once per user. Recording that it has been queued means
	 * a user whose lists are genuinely empty is not asked about again on every
	 * page load.
	 */
	public function ensureImported(string $userId): void {
		if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::IMPORT_QUEUED)) {
			return;
		}

		if (!$this->tasks->hasAnyForUser($userId)) {
			$this->jobList->add(ImportTasksJob::class, ['userId' => $userId]);
		}

		$this->userConfig->setValueBool($userId, Application::APP_ID, self::IMPORT_QUEUED, true);
	}
}
