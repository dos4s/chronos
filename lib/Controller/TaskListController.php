<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Chronos\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class TaskListController extends Controller {

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private ?CalDavBackend $calDavBackend = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/tasklists')]
	public function index(): JSONResponse {
		if ($this->calDavBackend === null) {
			return new JSONResponse([]);
		}

		$userId = $this->getUserId();
		$principalUri = 'principals/users/' . $userId;

		try {
			$calendars = $this->calDavBackend->getCalendarsForUser($principalUri);
		} catch (Throwable $e) {
			$this->logger->warning('TimeTracker: could not list task lists: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return new JSONResponse([]);
		}

		$result = [];
		foreach ($calendars as $cal) {
			if (!$this->supportsVTODO($cal)) {
				continue;
			}
			$uri = $this->buildCalendarRef($cal, $principalUri);
			$result[] = [
				'uri' => $uri,
				'name' => $cal['{DAV:}displayname'] ?? ($cal['uri'] ?? 'Untitled'),
				'color' => $cal['{http://apple.com/ns/ical/}calendar-color'] ?? null,
			];
		}

		usort($result, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
		return new JSONResponse($result);
	}

	private function supportsVTODO(array $cal): bool {
		$key = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		$components = $cal[$key] ?? null;
		if ($components === null) {
			return true; // unknown → be permissive
		}
		if (is_object($components) && method_exists($components, 'getValue')) {
			$values = $components->getValue();
			return is_array($values) && in_array('VTODO', $values, true);
		}
		if (is_array($components)) {
			return in_array('VTODO', $components, true);
		}
		return false;
	}

	private function buildCalendarRef(array $cal, string $principalUri): string {
		// Stable reference format: "<principalUri>/<calendar-uri>"
		$calendarUri = (string) ($cal['uri'] ?? $cal['id'] ?? '');
		return $principalUri . '/' . $calendarUri;
	}

	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('No authenticated user');
		}
		return $user->getUID();
	}
}
