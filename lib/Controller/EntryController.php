<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Db\Entry;
use OCA\Chronos\Db\EntryMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class EntryController extends Controller {

	public function __construct(
		IRequest $request,
		private EntryMapper $mapper,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/entries/active')]
	public function active(): JSONResponse {
		$userId = $this->getUserId();
		try {
			return new JSONResponse($this->mapper->findActive($userId));
		} catch (DoesNotExistException) {
			return new JSONResponse(null);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/check-in')]
	public function checkIn(
		?string $note = null,
		?string $projectUri = null,
		?string $projectName = null,
	): JSONResponse {
		$userId = $this->getUserId();

		// Fallback to raw request params in case JSON body parsing differs per NC version
		$note = $this->stringParam($note, 'note');
		$projectUri = $this->stringParam($projectUri, 'projectUri');
		$projectName = $this->stringParam($projectName, 'projectName');

		try {
			$this->mapper->findActive($userId);
			return new JSONResponse(
				['error' => 'already_checked_in'],
				Http::STATUS_CONFLICT,
			);
		} catch (DoesNotExistException) {
			// no active entry — proceed
		}

		$entry = new Entry();
		$entry->setUserId($userId);
		$entry->setStartTime($this->nowMs());
		$entry->setPausedDuration(0);
		$entry->setNote($this->normalize($note));
		$entry->setProjectUri($this->normalize($projectUri));
		$entry->setProjectName($this->normalize($projectName));
		return new JSONResponse($this->mapper->insert($entry), Http::STATUS_CREATED);
	}

	private function stringParam(?string $current, string $key): ?string {
		if ($current !== null && $current !== '') {
			return $current;
		}
		$raw = $this->request->getParam($key);
		return is_string($raw) ? $raw : $current;
	}

	private function normalize(?string $value): ?string {
		if ($value === null) {
			return null;
		}
		$trimmed = trim($value);
		return $trimmed === '' ? null : $trimmed;
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/pause')]
	public function pause(): JSONResponse {
		$userId = $this->getUserId();
		try {
			$entry = $this->mapper->findActive($userId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'no_active_entry'], Http::STATUS_CONFLICT);
		}

		if ($entry->getPausedAt() !== null) {
			return new JSONResponse(['error' => 'already_paused'], Http::STATUS_CONFLICT);
		}

		$entry->setPausedAt($this->nowMs());
		return new JSONResponse($this->mapper->update($entry));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/resume')]
	public function resume(): JSONResponse {
		$userId = $this->getUserId();
		try {
			$entry = $this->mapper->findActive($userId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'no_active_entry'], Http::STATUS_CONFLICT);
		}

		$pausedAt = $entry->getPausedAt();
		if ($pausedAt === null) {
			return new JSONResponse(['error' => 'not_paused'], Http::STATUS_CONFLICT);
		}

		$now = $this->nowMs();
		$duration = $now - $pausedAt;

		$pauses = [];
		if ($entry->getPausesBlob() !== null) {
			$parsed = json_decode($entry->getPausesBlob(), true);
			if (is_array($parsed)) {
				$pauses = $parsed;
			}
		}
		$pauses[] = [
			'start' => $pausedAt,
			'end' => $now,
		];
		
		$entry->setPausesBlob(json_encode($pauses));
		$entry->setPausedDuration($entry->getPausedDuration() + $duration);
		$entry->setPausedAt(null);
		return new JSONResponse($this->mapper->update($entry));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/check-out')]
	public function checkOut(): JSONResponse {
		$userId = $this->getUserId();
		try {
			$entry = $this->mapper->findActive($userId);
		} catch (DoesNotExistException) {
			return new JSONResponse(
				['error' => 'no_active_entry'],
				Http::STATUS_CONFLICT,
			);
		}

		$now = $this->nowMs();

		// auto-finalize any ongoing pause
		$pausedAt = $entry->getPausedAt();
		if ($pausedAt !== null) {
			$duration = $now - $pausedAt;
			$pauses = [];
			if ($entry->getPausesBlob() !== null) {
				$parsed = json_decode($entry->getPausesBlob(), true);
				if (is_array($parsed)) {
					$pauses = $parsed;
				}
			}
			$pauses[] = [
				'start' => $pausedAt,
				'end' => $now,
			];
			$entry->setPausesBlob(json_encode($pauses));
			$entry->setPausedDuration($entry->getPausedDuration() + $duration);
			$entry->setPausedAt(null);
		}

		$entry->setEndTime($now);
		return new JSONResponse($this->mapper->update($entry));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/entries')]
	public function index(int $limit = 100, int $offset = 0): JSONResponse {
		$userId = $this->getUserId();
		$limit = max(1, min($limit, 500));
		$offset = max(0, $offset);
		return new JSONResponse($this->mapper->findAllForUser($userId, $limit, $offset));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/entries/{id}')]
	public function update(
		int $id,
		int $startTime,
		?int $endTime = null,
		?string $pausesBlob = null,
		?string $note = null
	): JSONResponse {
		$userId = $this->getUserId();
		try {
			$entry = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($entry->getUserId() !== $userId) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($endTime !== null && $startTime > $endTime) {
			return new JSONResponse(['error' => 'invalid_times'], Http::STATUS_BAD_REQUEST);
		}

		// Calculate the physical total paused duration automatically based on pausesBlob provided
		$pausedDuration = 0;
		if ($pausesBlob !== null) {
			$parsed = json_decode($pausesBlob, true);
			if (is_array($parsed)) {
				foreach ($parsed as $pause) {
					if (isset($pause['start'], $pause['end']) && $pause['start'] <= $pause['end']) {
						// Hard constraint to prevent visual glitches: pauses MUST be within session bounds
						$start = max($startTime, $pause['start']);
						$end = $endTime !== null ? min($endTime, $pause['end']) : $pause['end'];
						if ($start < $end) {
							$pausedDuration += ($end - $start);
						}
					}
				}
			}
		}

		$entry->setStartTime($startTime);
		$entry->setEndTime($endTime);
		$entry->setPausesBlob($pausesBlob);
		$entry->setPausedDuration($pausedDuration);
		$entry->setNote($this->normalize($note));

		return new JSONResponse($this->mapper->update($entry));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/entries/{id}')]
	public function destroy(int $id): JSONResponse {
		$userId = $this->getUserId();
		try {
			$entry = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		if ($entry->getUserId() !== $userId) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		$this->mapper->delete($entry);
		return new JSONResponse(['ok' => true]);
	}

	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('No authenticated user');
		}
		return $user->getUID();
	}

	private function nowMs(): int {
		return (int) (microtime(true) * 1000);
	}
}
