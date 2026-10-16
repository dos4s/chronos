<?php

declare(strict_types=1);

namespace OCA\Chronos\Migration;

use Closure;
use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Support\UnixSeconds;
use OCA\Chronos\Time\Service\PauseNormalizer;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Milliseconds → seconds, and pauses out of their JSON blob into rows (§8.7).
 *
 * Adds the new columns and the `chronos_pauses` table, then converts the data.
 * The old columns are dropped by the next migration, once this one has run.
 *
 * Idempotent: only rows whose `start_at` is still NULL are converted, so
 * running it again is a no-op.
 */
class Version2000Date20261016120000 extends SimpleMigrationStep {

	/** Rows converted per transaction. */
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $db,
		private PauseNormalizer $normalizer,
		private LoggerInterface $logger,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('chronos_entries')) {
			return null;
		}
		$table = $schema->getTable('chronos_entries');

		// Nullable for now: "start_at IS NULL" is what marks a row as not yet
		// converted. The next migration makes it NOT NULL.
		if (!$table->hasColumn('start_at')) {
			$table->addColumn('start_at', Types::BIGINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('end_at')) {
			$table->addColumn('end_at', Types::BIGINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('paused_since_at')) {
			$table->addColumn('paused_since_at', Types::BIGINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('paused_seconds')) {
			$table->addColumn('paused_seconds', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$changed = true;
		}
		// Holds the owner's uid while the session runs and NULL afterwards.
		// The unique index below is what stops two simultaneous check-ins (D5);
		// every database in N1 allows repeated NULLs in a unique index.
		if (!$table->hasColumn('active_user_id')) {
			$table->addColumn('active_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$table->hasIndex('ch_entries_active_uniq')) {
			$table->addUniqueIndex(['active_user_id'], 'ch_entries_active_uniq');
			$changed = true;
		}

		if (!$schema->hasTable('chronos_pauses')) {
			$pauses = $schema->createTable('chronos_pauses');
			$pauses->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$pauses->addColumn('entry_id', Types::BIGINT, ['notnull' => true]);
			$pauses->addColumn('start_at', Types::BIGINT, ['notnull' => true]);
			$pauses->addColumn('end_at', Types::BIGINT, ['notnull' => false]);
			$pauses->setPrimaryKey(['id']);
			$pauses->addIndex(['entry_id'], 'ch_pauses_entry_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$converted = $this->convertEntries($output);
		$this->resolveActiveSessions($output);

		$output->info(sprintf('Chronos: converted %d session(s) to seconds', $converted));
	}

	/**
	 * Converts every row that still has no `start_at`.
	 */
	private function convertEntries(IOutput $output): int {
		$total = 0;

		while (true) {
			$rows = $this->fetchUnconverted();
			if ($rows === []) {
				break;
			}

			$this->db->beginTransaction();
			try {
				foreach ($rows as $row) {
					$this->convertRow($row);
					$total++;
				}
				$this->db->commit();
			} catch (Throwable $e) {
				$this->db->rollBack();
				$this->logger->error('Chronos: seconds migration failed', [
					'app' => Application::APP_ID,
					'exception' => $e,
				]);
				throw $e;
			}

			if (count($rows) < self::BATCH) {
				break;
			}
			$output->info(sprintf('Chronos: %d session(s) converted…', $total));
		}

		return $total;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchUnconverted(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'start_time', 'end_time', 'paused_at', 'paused_duration', 'pauses_blob')
			->from('chronos_entries')
			->where($qb->expr()->isNull('start_at'))
			->orderBy('id', 'ASC')
			->setMaxResults(self::BATCH);

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function convertRow(array $row): void {
		$id = (int)$row['id'];
		$startAt = $this->toSeconds($row['start_time']) ?? 0;
		$endAt = $this->toSeconds($row['end_time'] ?? null);
		$pausedSinceAt = $this->toSeconds($row['paused_at'] ?? null);

		if (is_numeric($row['start_time']) && abs((int)$row['start_time']) < UnixSeconds::MS_THRESHOLD) {
			$this->logger->warning('Chronos: session ' . $id . ' was already in seconds, copied unchanged', [
				'app' => Application::APP_ID,
			]);
		}

		// An end before the start would be corrupt data; keep the session open
		// rather than invent an end.
		if ($endAt !== null && $endAt < $startAt) {
			$this->logger->warning('Chronos: session ' . $id . ' ends before it starts, end dropped', [
				'app' => Application::APP_ID,
			]);
			$endAt = null;
		}

		$shapes = $this->parseBlob($id, is_string($row['pauses_blob'] ?? null) ? (string)$row['pauses_blob'] : null);
		if ($pausedSinceAt !== null) {
			$shapes[] = ['start' => $pausedSinceAt, 'end' => null];
		}

		// Overlapping, out-of-bounds and duplicated pauses are resolved by the
		// same rules the app uses from now on, and the total is recomputed from
		// the result instead of dividing the old one (§8.7).
		$normalized = $this->normalizer->normalize($shapes, $startAt, $endAt);

		// The old total and the old pause list could disagree: a quick
		// check-out added to the total without recording a pause (D2), and
		// sessions older than the blob column have a total and no pauses at
		// all. Recomputing from the pauses alone would turn that time into
		// worked time, so whatever the pauses do not account for is added back
		// as one (§8.7).
		$legacyPaused = $this->durationSeconds($row['paused_duration'] ?? null);
		$accounted = $this->normalizer->closedSeconds($normalized);
		if ($legacyPaused > $accounted) {
			$normalized = $this->normalizer->withLegacyTotal($normalized, $legacyPaused, $startAt, $endAt);
			$this->logger->warning(sprintf(
				'Chronos: session %d recorded %d seconds of pause that no pause described; '
					. 'restored as a pause at the end of the session, since when it happened was never recorded',
				$id,
				$legacyPaused - $accounted,
			), ['app' => Application::APP_ID]);
		}

		$this->deletePauses($id);
		foreach ($normalized as $pause) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('chronos_pauses')
				->values([
					'entry_id' => $insert->createNamedParameter($id, IQueryBuilder::PARAM_INT),
					'start_at' => $insert->createNamedParameter($pause['start'], IQueryBuilder::PARAM_INT),
					'end_at' => $pause['end'] === null
						? $insert->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
						: $insert->createNamedParameter($pause['end'], IQueryBuilder::PARAM_INT),
				]);
			$insert->executeStatement();
		}

		$update = $this->db->getQueryBuilder();
		$update->update('chronos_entries')
			->set('start_at', $update->createNamedParameter($startAt, IQueryBuilder::PARAM_INT))
			->set('end_at', $endAt === null
				? $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $update->createNamedParameter($endAt, IQueryBuilder::PARAM_INT))
			->set('paused_seconds', $update->createNamedParameter(
				$this->normalizer->closedSeconds($normalized),
				IQueryBuilder::PARAM_INT,
			))
			->set('paused_since_at', ($open = $this->normalizer->openStart($normalized)) === null
				? $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $update->createNamedParameter($open, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$update->executeStatement();
	}

	/**
	 * One active session per user, and its `active_user_id` marker set.
	 *
	 * Before this release two simultaneous check-ins could both stay open
	 * (D5). A superseded session is closed when the next one started, which is
	 * the last moment it can have been running.
	 */
	private function resolveActiveSessions(IOutput $output): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'start_at')
			->from('chronos_entries')
			->where($qb->expr()->isNull('end_at'))
			->orderBy('user_id', 'ASC')
			->addOrderBy('start_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		/** @var array<string, list<array{id: int, startAt: int}>> $byUser */
		$byUser = [];
		foreach ($rows as $row) {
			$byUser[(string)$row['user_id']][] = [
				'id' => (int)$row['id'],
				'startAt' => (int)$row['start_at'],
			];
		}

		$closed = 0;
		foreach ($byUser as $userId => $sessions) {
			$last = count($sessions) - 1;
			foreach ($sessions as $index => $session) {
				if ($index === $last) {
					$this->markActive($session['id'], $userId);
					continue;
				}

				$endAt = max($session['startAt'], $sessions[$index + 1]['startAt']);
				$this->closeSupersededSession($session['id'], $endAt);
				$closed++;
			}
		}

		if ($closed > 0) {
			$output->warning(sprintf(
				'Chronos: closed %d session(s) that had been left running alongside a newer one',
				$closed,
			));
		}
	}

	private function markActive(int $id, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chronos_entries')
			->set('active_user_id', $qb->createNamedParameter($userId))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('end_at'));
		$qb->executeStatement();
	}

	private function closeSupersededSession(int $id, int $endAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('chronos_entries')
			->set('end_at', $qb->createNamedParameter($endAt, IQueryBuilder::PARAM_INT))
			->set('active_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('paused_since_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		// Any pause left open belongs inside the session that just ended.
		$pauses = $this->db->getQueryBuilder();
		$pauses->update('chronos_pauses')
			->set('end_at', $pauses->createNamedParameter($endAt, IQueryBuilder::PARAM_INT))
			->where($pauses->expr()->eq('entry_id', $pauses->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($pauses->expr()->isNull('end_at'));
		$pauses->executeStatement();

		$recount = $this->db->getQueryBuilder();
		$recount->select('start_at', 'end_at')
			->from('chronos_pauses')
			->where($recount->expr()->eq('entry_id', $recount->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$cursor = $recount->executeQuery();
		/** @var list<array<string, mixed>> $pauseRows */
		$pauseRows = $cursor->fetchAll();
		$cursor->closeCursor();

		$total = 0;
		foreach ($pauseRows as $pauseRow) {
			if ($pauseRow['end_at'] !== null) {
				$total += (int)$pauseRow['end_at'] - (int)$pauseRow['start_at'];
			}
		}

		$totals = $this->db->getQueryBuilder();
		$totals->update('chronos_entries')
			->set('paused_seconds', $totals->createNamedParameter($total, IQueryBuilder::PARAM_INT))
			->where($totals->expr()->eq('id', $totals->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$totals->executeStatement();
	}

	private function deletePauses(int $entryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chronos_pauses')
			->where($qb->expr()->eq('entry_id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @return list<array{start: int, end: int|null}>
	 */
	private function parseBlob(int $entryId, ?string $blob): array {
		if ($blob === null || trim($blob) === '') {
			return [];
		}

		$parsed = json_decode($blob, true);
		if (!is_array($parsed)) {
			$this->logger->warning('Chronos: session ' . $entryId . ' had an unreadable pauses_blob, pauses dropped', [
				'app' => Application::APP_ID,
			]);
			return [];
		}

		$shapes = [];
		foreach ($parsed as $item) {
			if (!is_array($item) || !isset($item['start'])) {
				continue;
			}
			$start = $this->toSeconds($item['start']);
			if ($start === null) {
				continue;
			}
			$shapes[] = [
				'start' => $start,
				'end' => $this->toSeconds($item['end'] ?? null),
			];
		}

		return $shapes;
	}

	/**
	 * A duration, as opposed to a point in time: `paused_duration` was always
	 * written in milliseconds, so it is divided rather than being guessed at
	 * by magnitude the way a timestamp is.
	 */
	private function durationSeconds(mixed $value): int {
		if ($value === null || is_bool($value) || !is_numeric($value)) {
			return 0;
		}

		return max(0, intdiv((int)$value, 1000));
	}

	private function toSeconds(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_bool($value) || !is_numeric($value)) {
			return null;
		}
		return UnixSeconds::fromLegacy((int)$value);
	}
}
