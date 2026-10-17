<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Board\Service\BoardService;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The kanban board: one project's columns, and the drops onto them (§15).
 *
 * Both routes belong to the `kanban` module, so both ask first whether it is
 * switched on for this user (§13). A board is always read for one project —
 * never for all of them — because a board is one screen (§16).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class BoardController extends Controller {

	public function __construct(
		IRequest $request,
		private BoardService $board,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The project reference contains slashes, so it travels as a parameter
	 * rather than as part of the path.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/board')]
	public function index(string $project): JSONResponse {
		return new JSONResponse($this->board->board($this->user(), $project)->jsonSerialize());
	}

	/**
	 * One drop: the cards that were dragged, the column they landed in, and
	 * the two cards they landed between (§15.6).
	 *
	 * @param list<mixed> $cards
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/board/move')]
	public function move(
		string $project,
		string $column,
		array $cards = [],
		?string $afterUid = null,
		?string $beforeUid = null,
		?string $comment = null,
	): JSONResponse {
		return new JSONResponse(
			$this->board->move(
				$this->user(),
				$project,
				$this->readCards($cards),
				$column,
				$this->uid($afterUid),
				$this->uid($beforeUid),
				$comment,
			)->jsonSerialize(),
		);
	}

	/**
	 * The cards of a drop, as the request sent them: a UID and the etag the
	 * client's view of that card was based on (§15.6).
	 *
	 * @param list<mixed> $cards
	 * @return list<array{uid: string, etag?: string|null}>
	 * @throws ValidationFailedException
	 */
	private function readCards(array $cards): array {
		$read = [];
		foreach ($cards as $card) {
			if (!is_array($card)) {
				throw new ValidationFailedException('invalid_cards');
			}

			/** @var mixed $uid */
			$uid = $card['uid'] ?? null;
			if (!is_string($uid)) {
				throw new ValidationFailedException('invalid_cards');
			}

			/** @var mixed $etag */
			$etag = $card['etag'] ?? null;
			$read[] = [
				'uid' => $uid,
				'etag' => is_string($etag) ? $etag : null,
			];
		}

		return $read;
	}

	private function uid(?string $uid): ?string {
		$uid = trim($uid ?? '');

		return $uid === '' ? null : $uid;
	}

	private function user(): string {
		$userId = $this->currentUser->getUid();
		$this->toggles->requireEnabled(FeatureToggles::MODULE_KANBAN, $userId);

		return $userId;
	}
}
