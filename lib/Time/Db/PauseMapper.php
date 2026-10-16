<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Pause>
 */
class PauseMapper extends QBMapper {

	/** Keep IN () lists well inside every backend's parameter limits. */
	private const CHUNK = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chronos_pauses', Pause::class);
	}

	/**
	 * @return list<Pause>
	 */
	public function findForEntry(int $entryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entry_id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
			->orderBy('start_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Pauses for many sessions at once, grouped by session id, so a list view
	 * costs one query instead of one per row.
	 *
	 * @param list<int> $entryIds
	 * @return array<int, list<Pause>>
	 */
	public function findForEntries(array $entryIds): array {
		if ($entryIds === []) {
			return [];
		}

		$grouped = [];
		foreach (array_chunk(array_values(array_unique($entryIds)), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('entry_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('start_at', 'ASC');
			foreach ($this->findEntities($qb) as $pause) {
				$grouped[$pause->getEntryId()][] = $pause;
			}
		}
		return $grouped;
	}

	public function deleteForEntry(int $entryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('entry_id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
