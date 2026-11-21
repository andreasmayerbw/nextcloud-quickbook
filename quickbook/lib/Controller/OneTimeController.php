<?php

declare(strict_types=1);

namespace OCA\QuickBook\Controller;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\ValidationException;
use OCA\QuickBook\OneTime\OneTimeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * JSON endpoints behind the "One-time appointment schedule" form in the Calendar app.
 * Each user only sees and changes their own schedules.
 */
class OneTimeController extends Controller {
	public function __construct(
		IRequest $request,
		private OneTimeService $oneTime,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/one-time')]
	public function index(): JSONResponse {
		return $this->respond(fn (string $userId) => $this->oneTime->listForUser($userId));
	}

	/**
	 * Same limit as creating schedules in the Calendar app
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 1200)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/one-time')]
	public function create(): JSONResponse {
		return $this->respond(fn (string $userId) => ['schedule' => $this->oneTime->create($userId, $this->input())], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 1200)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/one-time/{id}')]
	public function update(int $id): JSONResponse {
		return $this->respond(fn (string $userId) => ['schedule' => $this->oneTime->update($userId, $id, $this->input())]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 1200)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/one-time/{id}')]
	public function destroy(int $id): JSONResponse {
		return $this->respond(function (string $userId) use ($id): array {
			$this->oneTime->delete($userId, $id);
			return [];
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	private function input(): array {
		$input = [];
		foreach ([
			'name', 'description', 'location', 'createTalkRoom',
			'lengthMinutes', 'incrementMinutes', 'calendarUri', 'conflictCalendarUris', 'visibility',
			'timezone', 'days',
			'preparationMinutes', 'followupMinutes', 'timeBeforeNextSlotMinutes', 'dailyMax',
		] as $key) {
			$input[$key] = $this->request->getParam($key);
		}
		return $input;
	}

	/**
	 * @param callable(string): array<string, mixed> $action
	 */
	private function respond(callable $action, int $status = Http::STATUS_OK): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_logged_in'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse($action($user->getUID()), $status);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'invalid', 'field' => $e->getField(), 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (ScheduleNotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (CalendarUnavailableException) {
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			$this->logger->error('Quick Booking one-time schedule request failed', ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => 'temporary'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
