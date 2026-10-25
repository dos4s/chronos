<?php

declare(strict_types=1);

namespace OCA\Chronos\Time\Service;

use OCA\Chronos\Core\Error\ConflictException;
use OCA\Chronos\Core\Error\ForbiddenException;
use OCA\Chronos\Core\Error\NotFoundException;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Support\UnixSeconds;
use OCA\Chronos\Time\Db\Entry;
use OCA\Chronos\Time\Db\EntryMapper;
use OCA\Chronos\Time\Db\Pause;
use OCA\Chronos\Time\Db\PauseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use Throwable;

/**
 * Every rule about sessions and pauses lives here (§12.2).
 *
 * The controllers, the quick links and the dashboard widget all go through this
 * one service, so a pause finalised by a quick check-out is recorded exactly
 * like one finalised in the UI (D2, D3).
 *
 * @psalm-import-type PauseShape from PauseNormalizer
 * @psalm-type SessionShape = array<string, mixed>
 */
class SessionService {

	private const MAX_NOTE_LENGTH = 500;
	private const MAX_PROJECT_LENGTH = 255;
	private const MAX_TASK_UID_LENGTH = 255;

	public function __construct(
		private IDBConnection $db,
		private EntryMapper $entryMapper,
		private PauseMapper $pauseMapper,
		private PauseNormalizer $normalizer,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return SessionShape|null
	 */
	public function getActive(string $userId): ?array {
		$entry = $this->entryMapper->findActive($userId);
		if ($entry === null) {
			return null;
		}
		return $this->serialize($entry, $this->pauseMapper->findForEntry($entry->getId()));
	}

	/**
	 * @return list<SessionShape>
	 */
	public function listSessions(string $userId, int $limit, int $offset): array {
		$entries = $this->entryMapper->findAllForUser($userId, $limit, $offset);
		return $this->serializeMany($entries);
	}

	/**
	 * @throws ConflictException if the user is already checked in
	 * @return SessionShape
	 */
	public function checkIn(
		string $userId,
		?string $note = null,
		?string $projectUri = null,
		?string $projectName = null,
		?string $taskUid = null,
		?string $taskSummary = null,
	): array {
		$what = $this->normalizeWhat($note, $projectUri, $projectName, $taskUid, $taskSummary);

		$entry = $this->transactional(function () use ($userId, $what): Entry {
			// Fast path: a clear error instead of a constraint violation.
			if ($this->entryMapper->findActive($userId) !== null) {
				throw new ConflictException('already_checked_in');
			}

			return $this->openEntry($userId, $what);
		});

		return $this->serialize($entry, []);
	}

	/**
	 * Moves the clock onto a task: ends whatever is running and starts the new
	 * session in the same transaction (§12.2).
	 *
	 * This exists because *check in* is the wrong verb for the thing a person
	 * does twenty times a day. Pressing play on a task while another one is
	 * running is not a mistake to report back as `already_checked_in` — it is
	 * the whole gesture, and doing it as two calls from the browser would
	 * leave a gap where nothing is running, or two things are.
	 *
	 * @return SessionShape
	 */
	public function switchTo(
		string $userId,
		?string $note = null,
		?string $projectUri = null,
		?string $projectName = null,
		?string $taskUid = null,
		?string $taskSummary = null,
	): array {
		$what = $this->normalizeWhat($note, $projectUri, $projectName, $taskUid, $taskSummary);

		$entry = $this->transactional(function () use ($userId, $what): Entry {
			$running = $this->entryMapper->findActive($userId);
			if ($running !== null) {
				$this->finishEntry($running);
			}

			return $this->openEntry($userId, $what);
		});

		return $this->serialize($entry, []);
	}

	/**
	 * What each task has cost so far, by uid (§16, M5).
	 *
	 * @return list<array{taskUid: string, seconds: int, sessionCount: int, lastAt: int}>
	 */
	public function taskTotals(string $userId): array {
		return $this->entryMapper->totalsByTask($userId, $this->timeFactory->getTime());
	}

	/**
	 * @throws ConflictException
	 * @return SessionShape
	 */
	public function pause(string $userId): array {
		return $this->transactional(function () use ($userId): array {
			$entry = $this->requireActive($userId);
			if ($entry->getPausedSinceAt() !== null) {
				throw new ConflictException('already_paused');
			}

			$shapes = $this->toShapes($this->pauseMapper->findForEntry($entry->getId()));
			$shapes[] = ['start' => $this->timeFactory->getTime(), 'end' => null];

			return $this->replacePauses($entry, $shapes);
		});
	}

	/**
	 * @throws ConflictException
	 * @return SessionShape
	 */
	public function resume(string $userId): array {
		return $this->transactional(function () use ($userId): array {
			$entry = $this->requireActive($userId);
			$pausedSince = $entry->getPausedSinceAt();
			if ($pausedSince === null) {
				throw new ConflictException('not_paused');
			}

			$now = $this->timeFactory->getTime();
			// Close whatever is open. If the open pause has no row — a session
			// from before pauses were rows — take it from paused_since_at.
			$shapes = [];
			$closedAny = false;
			foreach ($this->toShapes($this->pauseMapper->findForEntry($entry->getId())) as $shape) {
				if ($shape['end'] === null) {
					$shapes[] = ['start' => $shape['start'], 'end' => $now];
					$closedAny = true;
					continue;
				}
				$shapes[] = $shape;
			}
			if (!$closedAny) {
				$shapes[] = ['start' => $pausedSince, 'end' => $now];
			}

			return $this->replacePauses($entry, $shapes);
		});
	}

	/**
	 * Ends the session, finalising an open pause on the way (D2).
	 *
	 * @throws ConflictException
	 * @return SessionShape
	 */
	public function checkOut(string $userId): array {
		return $this->transactional(function () use ($userId): array {
			return $this->finishEntry($this->requireActive($userId));
		});
	}

	/**
	 * Edits a session from the session editor.
	 *
	 * @param list<PauseShape>|null $pauses null leaves the existing pauses alone
	 * @throws NotFoundException|ForbiddenException|ValidationFailedException|ConflictException
	 * @return SessionShape
	 */
	public function updateSession(
		string $userId,
		int $id,
		int $startAt,
		?int $endAt,
		?array $pauses,
		?string $note,
		?string $projectUri = null,
		?string $projectName = null,
		?string $taskUid = null,
		?string $taskSummary = null,
	): array {
		UnixSeconds::assert($startAt);
		UnixSeconds::assert($endAt);
		if ($endAt !== null && $endAt < $startAt) {
			throw new ValidationFailedException('invalid_times');
		}

		$what = $this->normalizeWhat($note, $projectUri, $projectName, $taskUid, $taskSummary);
		$note = $what['note'];
		$projectUri = $what['projectUri'];
		$projectName = $what['projectName'];

		if ($pauses !== null) {
			foreach ($pauses as $pause) {
				UnixSeconds::assert($pause['start']);
				UnixSeconds::assert($pause['end'] ?? null);
			}
		}

		return $this->transactional(function () use (
			$userId, $id, $startAt, $endAt, $pauses, $note, $projectUri, $projectName, $what
		): array {
			$entry = $this->requireOwned($userId, $id);
			$shapes = $pauses ?? $this->toShapes($this->pauseMapper->findForEntry($entry->getId()));

			$entry->setStartAt($startAt);
			$entry->setEndAt($endAt);
			$entry->setNote($note);
			if ($projectUri !== null || $projectName !== null) {
				$entry->setProjectUri($projectUri);
				$entry->setProjectName($projectName);
			}
			// Only when the editor sent one: an edit that says nothing about
			// the task leaves the task it was worked on alone.
			if ($what['taskUid'] !== null) {
				$entry->setTaskUid($what['taskUid']);
				$entry->setTaskSummary($what['taskSummary']);
			}

			if ($endAt === null) {
				// Reopening a session: only one may run at a time.
				$running = $this->entryMapper->findActive($userId);
				if ($running !== null && $running->getId() !== $entry->getId()) {
					throw new ConflictException('already_checked_in');
				}
				$entry->setActiveUserId($userId);
			} else {
				$entry->setActiveUserId(null);
			}

			return $this->replacePauses($entry, $shapes);
		});
	}

	/**
	 * @throws NotFoundException|ForbiddenException
	 */
	public function deleteSession(string $userId, int $id): void {
		$this->transactional(function () use ($userId, $id): bool {
			$entry = $this->requireOwned($userId, $id);
			$this->pauseMapper->deleteForEntry($entry->getId());
			$this->entryMapper->delete($entry);
			return true;
		});
	}

	/**
	 * Quick link: check in unless a session is already running.
	 */
	public function checkInIfIdle(string $userId): void {
		try {
			$this->checkIn($userId);
		} catch (ConflictException) {
			// Already running — a quick link is a no-op, not an error.
		}
	}

	/**
	 * Quick link: check out if a session is running.
	 */
	public function checkOutIfRunning(string $userId): void {
		try {
			$this->checkOut($userId);
		} catch (ConflictException) {
			// Nothing to end.
		}
	}

	/**
	 * Validates and trims everything that says *what* a session is about, so
	 * check-in, switch and edit all agree on the limits (D13).
	 *
	 * @throws ValidationFailedException
	 * @return array{note: ?string, projectUri: ?string, projectName: ?string, taskUid: ?string, taskSummary: ?string}
	 */
	private function normalizeWhat(
		?string $note,
		?string $projectUri,
		?string $projectName,
		?string $taskUid,
		?string $taskSummary,
	): array {
		return [
			'note' => $this->normalizeText($note, self::MAX_NOTE_LENGTH, 'note'),
			'projectUri' => $this->normalizeText($projectUri, self::MAX_PROJECT_LENGTH, 'projectUri'),
			'projectName' => $this->normalizeText($projectName, self::MAX_PROJECT_LENGTH, 'projectName'),
			'taskUid' => $this->normalizeText($taskUid, self::MAX_TASK_UID_LENGTH, 'taskUid'),
			'taskSummary' => $this->normalizeText($taskSummary, self::MAX_PROJECT_LENGTH, 'taskSummary'),
		];
	}

	/**
	 * Starts a session. The caller is inside a transaction and has already
	 * decided that it is allowed to.
	 *
	 * @param array{note: ?string, projectUri: ?string, projectName: ?string, taskUid: ?string, taskSummary: ?string} $what
	 * @throws ConflictException
	 */
	private function openEntry(string $userId, array $what): Entry {
		$entry = new Entry();
		$entry->setUserId($userId);
		$entry->setActiveUserId($userId);
		$entry->setStartAt($this->timeFactory->getTime());
		$entry->setPausedSeconds(0);
		$entry->setNote($what['note']);
		$entry->setProjectUri($what['projectUri']);
		$entry->setProjectName($what['projectName']);
		$entry->setTaskUid($what['taskUid']);
		$entry->setTaskSummary($what['taskSummary']);

		try {
			return $this->entryMapper->insert($entry);
		} catch (DbException $e) {
			// Two check-ins at once: the unique index over active_user_id
			// lets exactly one of them through (D5).
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new ConflictException('already_checked_in', $e->getMessage(), $e);
			}
			throw $e;
		}
	}

	/**
	 * Ends a running session, finalising an open pause on the way (D2). The
	 * caller is inside a transaction.
	 *
	 * @return SessionShape
	 */
	private function finishEntry(Entry $entry): array {
		$now = max($this->timeFactory->getTime(), $entry->getStartAt());

		$shapes = $this->toShapes($this->pauseMapper->findForEntry($entry->getId()));
		$pausedSince = $entry->getPausedSinceAt();
		if ($pausedSince !== null && $this->normalizer->openStart($shapes) === null) {
			$shapes[] = ['start' => $pausedSince, 'end' => $now];
		}

		$entry->setEndAt($now);
		$entry->setActiveUserId(null);

		return $this->replacePauses($entry, $shapes);
	}

	/**
	 * Writes the normalised pauses and keeps the session's cached totals in
	 * step with them, so `paused_seconds` is always the sum of the rows.
	 *
	 * @param list<PauseShape> $shapes
	 * @return SessionShape
	 */
	private function replacePauses(Entry $entry, array $shapes): array {
		$normalized = $this->normalizer->normalize($shapes, $entry->getStartAt(), $entry->getEndAt());

		$this->pauseMapper->deleteForEntry($entry->getId());
		$rows = [];
		foreach ($normalized as $shape) {
			$pause = new Pause();
			$pause->setEntryId($entry->getId());
			$pause->setStartAt($shape['start']);
			$pause->setEndAt($shape['end']);
			$rows[] = $this->pauseMapper->insert($pause);
		}

		$entry->setPausedSeconds($this->normalizer->closedSeconds($normalized));
		$entry->setPausedSinceAt($this->normalizer->openStart($normalized));
		$this->entryMapper->update($entry);

		return $this->serialize($entry, $rows);
	}

	/**
	 * @throws ConflictException
	 */
	private function requireActive(string $userId): Entry {
		$entry = $this->entryMapper->findActive($userId);
		if ($entry === null) {
			throw new ConflictException('no_active_session');
		}
		return $entry;
	}

	/**
	 * @throws NotFoundException|ForbiddenException
	 */
	private function requireOwned(string $userId, int $id): Entry {
		try {
			$entry = $this->entryMapper->find($id);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException('not_found', $e->getMessage());
		}
		if ($entry->getUserId() !== $userId) {
			throw new ForbiddenException();
		}
		return $entry;
	}

	/**
	 * @param list<Pause> $pauses
	 * @return list<PauseShape>
	 */
	private function toShapes(array $pauses): array {
		return array_map(
			static fn (Pause $pause): array => [
				'start' => $pause->getStartAt(),
				'end' => $pause->getEndAt(),
			],
			$pauses,
		);
	}

	/**
	 * @param list<Pause> $pauses
	 * @return array<string, mixed>
	 */
	private function serialize(Entry $entry, array $pauses): array {
		$payload = $entry->jsonSerialize();
		$payload['pauses'] = array_map(
			static fn (Pause $pause): array => $pause->jsonSerialize(),
			$pauses,
		);
		return $payload;
	}

	/**
	 * @param list<Entry> $entries
	 * @return list<SessionShape>
	 */
	private function serializeMany(array $entries): array {
		$ids = array_map(static fn (Entry $entry): int => $entry->getId(), $entries);
		$grouped = $this->pauseMapper->findForEntries($ids);

		$result = [];
		foreach ($entries as $entry) {
			$result[] = $this->serialize($entry, $grouped[$entry->getId()] ?? []);
		}
		return $result;
	}

	/**
	 * @throws ValidationFailedException
	 */
	private function normalizeText(?string $value, int $maxLength, string $field): ?string {
		if ($value === null) {
			return null;
		}
		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}
		if (mb_strlen($trimmed) > $maxLength) {
			throw new ValidationFailedException($field . '_too_long');
		}
		return $trimmed;
	}

	/**
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	private function transactional(callable $operation) {
		$this->db->beginTransaction();
		try {
			$result = $operation();
			$this->db->commit();
			return $result;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
