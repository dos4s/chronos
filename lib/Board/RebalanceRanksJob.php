<?php

declare(strict_types=1);

namespace OCA\Chronos\Board;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Board\Db\BoardRankMapper;
use OCA\Chronos\Board\Service\BoardService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps board ranks short (§15.5).
 *
 * A drop that would write a long rank already rebalances its own column on the
 * spot, so this is the sweeper rather than the mechanism: it catches the
 * columns left long by a Chronos that did not do that yet, by a restore, or by
 * ranks written straight into the table.
 *
 * It touches nothing but `chronos_board_ranks`: an order is Chronos's own view
 * of a list, so rewriting one never writes to CalDAV and never wakes a phone.
 *
 * @psalm-suppress UnusedClass registered as a background job in appinfo/info.xml
 */
class RebalanceRanksJob extends TimedJob {

	private const INTERVAL = 86400;

	/** Columns per run, so one pathological instance cannot hold the queue. */
	private const BATCH = 50;

	public function __construct(
		ITimeFactory $time,
		private BoardRankMapper $ranks,
		private BoardService $board,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$columns = $this->ranks->findLongColumns(Rank::MAX_LENGTH, self::BATCH);
		} catch (Throwable $e) {
			$this->logger->error('Chronos: looking for long board ranks failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}

		foreach ($columns as $column) {
			try {
				$rewritten = $this->board->rebalance($column['calendarId'], $column['columnKey']);
				$this->logger->debug(
					'Chronos: rebalanced ' . $rewritten . ' board ranks in ' . $column['columnKey'],
					['app' => Application::APP_ID],
				);
			} catch (Throwable $e) {
				// One column that cannot be rewritten must not stop the rest;
				// its cards are still in the right order, just verbosely.
				$this->logger->error('Chronos: rebalancing board ranks failed: ' . $e->getMessage(), [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
			}
		}
	}
}
