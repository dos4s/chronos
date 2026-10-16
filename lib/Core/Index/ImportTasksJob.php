<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCA\Chronos\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a user's existing task lists into the index, once (§16).
 *
 * Queued rather than done in the request that noticed the empty index: a long
 * task list should not make opening the app slow.
 */
class ImportTasksJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private IndexSynchroniser $synchroniser,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$userId = is_array($argument) ? (string)($argument['userId'] ?? '') : '';
		if ($userId === '') {
			return;
		}

		try {
			$totals = $this->synchroniser->syncUser($userId, true);
		} catch (Throwable $e) {
			$this->logger->error('Chronos: importing tasks failed for ' . $userId . ': ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}

		$this->logger->info(sprintf(
			'Chronos: imported %d task(s) from %d list(s) for %s',
			$totals['indexed'],
			$totals['calendars'],
			$userId,
		), ['app' => Application::APP_ID]);
	}
}
