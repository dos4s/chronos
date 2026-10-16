<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Index;

use OCA\Chronos\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks the index against CalDAV and reports drift (§11.4).
 *
 * Events can be missed — a failed request, an app disabled for a while, a
 * restore from a backup — so the index is never trusted to be right by itself.
 * Comparing etags makes the usual case cheap: nothing is parsed unless an etag
 * differs, and this is also what imports a user's lists the first time.
 *
 * @psalm-suppress UnusedClass registered as a background job in appinfo/info.xml
 */
class ReconcileJob extends TimedJob {

	private const INTERVAL = 3600;

	public function __construct(
		ITimeFactory $time,
		private IndexSynchroniser $synchroniser,
		private IUserManager $userManager,
		private TaskMapper $tasks,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
		// Nothing here has to happen at a particular moment.
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$drift = 0;
		$users = 0;

		$this->userManager->callForSeenUsers(function (IUser $user) use (&$drift, &$users): void {
			$userId = $user->getUID();

			// Only users Chronos already knows: a user who has never opened it
			// has nothing to reconcile, and indexing their lists uninvited
			// would be work nobody asked for.
			if (!$this->tasks->hasAnyForUser($userId)) {
				return;
			}

			try {
				$totals = $this->synchroniser->syncUser($userId);
			} catch (Throwable $e) {
				$this->logger->error('Chronos: reconcile failed for ' . $userId . ': ' . $e->getMessage(), [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
				return;
			}

			$users++;
			$drift += $totals['indexed'] + $totals['removed'];
		});

		if ($drift > 0) {
			$this->logger->info(sprintf(
				'Chronos: reconcile repaired %d task(s) across %d user(s)',
				$drift,
				$users,
			), ['app' => Application::APP_ID]);
		}
	}
}
