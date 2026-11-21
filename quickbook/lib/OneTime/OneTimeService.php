<?php

declare(strict_types=1);

namespace OCA\QuickBook\OneTime;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\Calendar\ContractCheck;
use OCA\QuickBook\Calendar\Schedule;
use OCA\QuickBook\Calendar\ScheduleDraft;
use OCA\QuickBook\Db\OneTimeSchedule;
use OCA\QuickBook\Db\OneTimeScheduleMapper;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCA\QuickBook\Exception\ValidationException;
use OCA\QuickBook\Service\Input;
use OCP\App\IAppManager;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Talk\IBroker;
use Psr\Log\LoggerInterface;

/**
 * One-time appointment schedules: Calendar schedules that only take bookings on chosen dates.
 *
 * The Calendar schedule holds every setting the Calendar's own editor has; this app stores the
 * exact dates and keeps the schedule's weekly rules and date window in line with them.
 */
class OneTimeService {
	private const MAX_NAME_LENGTH = 128;
	private const MAX_LOCATION_LENGTH = 255;
	private const MAX_DESCRIPTION_LENGTH = 2000;
	private const MAX_LENGTH_MINUTES = 24 * 60;
	private const MAX_DAILY = 1000;
	private const VISIBILITIES = [ContractCheck::VISIBILITY_PUBLIC, ContractCheck::VISIBILITY_PRIVATE];
	/** The choices of the Calendar's editor, in minutes */
	private const MINUTE_STEPS = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60];
	private const NOTICE_EXTRA = [2 * 60, 6 * 60, 24 * 60, 2 * 24 * 60, 7 * 24 * 60];

	/** @var array<string, OneTimeDates|null> */
	private array $datesByToken = [];

	public function __construct(
		private CalendarBridge $calendar,
		private OneTimeScheduleMapper $mapper,
		private IManager $calendarManager,
		private IURLGenerator $urlGenerator,
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IBroker $talkBroker,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Exact dates of a one-time schedule, null for a normal (weekly) schedule.
	 *
	 * @throws TemporaryFailureException
	 */
	public function datesForToken(string $token): ?OneTimeDates {
		if (!array_key_exists($token, $this->datesByToken)) {
			try {
				$row = $this->mapper->findByToken($token);
				$this->datesByToken[$token] = $row === null ? null : OneTimeDates::fromStored($row->getTimezone(), $row->getDates());
			} catch (\Throwable $e) {
				$this->logger->error('Quick Booking could not read the dates of a one-time schedule', ['app' => Application::APP_ID, 'exception' => $e]);
				throw new TemporaryFailureException('Could not read one-time dates', 0, $e);
			}
		}
		return $this->datesByToken[$token];
	}

	/**
	 * Whether a booking fits the schedule's dates. Always true for normal schedules.
	 *
	 * @throws TemporaryFailureException
	 */
	public function allowsBooking(string $token, int $start, int $end): bool {
		$dates = $this->datesForToken($token);
		return $dates === null || $dates->contains($start, $end);
	}

	/**
	 * Puts the weekly rules and date window back when they were changed outside Quick Booking
	 * (the Calendar's own editor clears the date window). Best effort: slots are filtered by
	 * the exact dates anyway, this keeps the Calendar's own booking page close to them.
	 */
	public function ensureDateWindow(Schedule $schedule, OneTimeDates $dates): Schedule {
		if ($schedule->start === $dates->windowStart()
			&& $schedule->end === $dates->windowEnd()
			&& $schedule->availability == $dates->availability()) {
			return $schedule;
		}
		try {
			$fixed = $this->calendar->setDateWindow($schedule, $dates->availability(), $dates->windowStart(), $dates->windowEnd());
			$this->logger->info('Quick Booking restored the dates of one-time schedule ' . $schedule->id, ['app' => Application::APP_ID]);
			return $fixed;
		} catch (\Throwable $e) {
			$this->logger->warning('Quick Booking could not restore the dates of one-time schedule ' . $schedule->id, ['app' => Application::APP_ID, 'exception' => $e]);
			return $schedule;
		}
	}

	/**
	 * The user's one-time schedules, the names of their other schedules (the Calendar app lists
	 * those itself) and the choices for the form. Forgets one-time dates of deleted schedules.
	 *
	 * @return array<string, mixed>
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function listForUser(string $userId): array {
		$rowsByToken = [];
		foreach ($this->mapper->findAllForUser($userId) as $row) {
			$rowsByToken[$row->getToken()] = $row;
		}

		$oneTime = [];
		$otherNames = [];
		foreach ($this->calendar->findSchedulesForUser($userId) as $schedule) {
			$row = $rowsByToken[$schedule->token] ?? null;
			unset($rowsByToken[$schedule->token]);
			if ($row === null) {
				$otherNames[] = $schedule->name;
				continue;
			}
			try {
				$dates = OneTimeDates::fromStored($row->getTimezone(), $row->getDates());
			} catch (\UnexpectedValueException $e) {
				$this->logger->error('Quick Booking found invalid dates for one-time schedule ' . $schedule->id, ['app' => Application::APP_ID, 'exception' => $e]);
				continue;
			}
			$oneTime[] = $this->serialize($this->ensureDateWindow($schedule, $dates), $dates);
		}
		// Deleted in the Calendar app
		foreach ($rowsByToken as $row) {
			$this->mapper->delete($row);
		}

		return [
			'schedules' => $oneTime,
			'otherNames' => $otherNames,
			'calendars' => $this->calendars($userId),
			'talk' => $this->canCreateTalkRooms($userId),
			'choices' => [
				'increments' => self::MINUTE_STEPS,
				'buffers' => [0, ...self::MINUTE_STEPS],
				'notice' => [0, ...self::MINUTE_STEPS, ...self::NOTICE_EXTRA],
			],
		];
	}

	/**
	 * @param array<string, mixed> $input form fields
	 * @return array<string, mixed> the new schedule
	 * @throws ValidationException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function create(string $userId, array $input): array {
		[$draft, $dates] = $this->parse($userId, $input, null);
		$schedule = $this->calendar->createSchedule($userId, $draft);

		$row = new OneTimeSchedule();
		$row->setToken($schedule->token);
		$row->setConfigId($schedule->id);
		$row->setUserId($userId);
		$row->setTimezone($dates->timezone->getName());
		$row->setDates($dates->toJson());
		try {
			$this->mapper->insert($row);
		} catch (\Throwable $e) {
			// The Calendar schedule exists with the right window, only dates more than a week apart are not exact
			$this->logger->error('Quick Booking created schedule ' . $schedule->id . ' but could not store its dates', ['app' => Application::APP_ID, 'exception' => $e]);
			throw new TemporaryFailureException('Could not store the dates', 0, $e);
		}
		return $this->serialize($schedule, $dates);
	}

	/**
	 * @param array<string, mixed> $input form fields
	 * @return array<string, mixed> the changed schedule
	 * @throws ValidationException|ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function update(string $userId, int $id, array $input): array {
		[$row, $schedule] = $this->find($userId, $id);
		[$draft, $dates] = $this->parse($userId, $input, $schedule);

		// Dates first: if the Calendar update fails, the next page load puts this window in place
		$row->setTimezone($dates->timezone->getName());
		$row->setDates($dates->toJson());
		$this->mapper->update($row);
		unset($this->datesByToken[$schedule->token]);

		return $this->serialize($this->calendar->updateSchedule($schedule, $draft), $dates);
	}

	/**
	 * Deletes the Calendar schedule and its dates. Existing bookings stay in the calendar.
	 *
	 * @throws ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function delete(string $userId, int $id): void {
		[$row, $schedule] = $this->find($userId, $id);
		$this->calendar->deleteSchedule($schedule);
		$this->mapper->delete($row);
		unset($this->datesByToken[$schedule->token]);
	}

	public function deleteForUser(string $userId): void {
		$this->mapper->deleteForUser($userId);
	}

	/**
	 * @return array{OneTimeSchedule, Schedule}
	 * @throws ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	private function find(string $userId, int $id): array {
		$row = $this->mapper->findByConfigId($id, $userId);
		if ($row === null) {
			throw new ScheduleNotFoundException('Not a one-time schedule of this user');
		}
		$schedule = $this->calendar->findScheduleForUser($id, $userId);
		if ($schedule->token !== $row->getToken()) {
			throw new ScheduleNotFoundException('Not a one-time schedule of this user');
		}
		return [$row, $schedule];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param Schedule|null $current the schedule being changed, null for a new one
	 * @return array{ScheduleDraft, OneTimeDates}
	 * @throws ValidationException
	 */
	private function parse(string $userId, array $input, ?Schedule $current): array {
		$text = static fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
		$choice = static function (string $key, array $allowed, string $message) use ($input): int {
			$value = $input[$key] ?? null;
			if (!is_int($value) || !in_array($value, $allowed, true)) {
				throw new ValidationException($key, $message);
			}
			return $value;
		};

		$name = Input::singleLine($text('name'));
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new ValidationException('name', 'Please enter a name of at most 128 characters');
		}
		$location = Input::singleLine($text('location'));
		if (mb_strlen($location) > self::MAX_LOCATION_LENGTH) {
			throw new ValidationException('location', 'The location is too long');
		}
		$description = Input::multiLine($text('description'));
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new ValidationException('description', 'The description is too long');
		}

		$length = $input['lengthMinutes'] ?? null;
		if (!is_int($length) || $length < 1 || $length > self::MAX_LENGTH_MINUTES) {
			throw new ValidationException('lengthMinutes', 'Please enter the duration in minutes');
		}
		$increment = $choice('incrementMinutes', self::MINUTE_STEPS, 'Please choose the increments');
		$preparation = $choice('preparationMinutes', [0, ...self::MINUTE_STEPS], 'Please choose the buffer before the event');
		$followup = $choice('followupMinutes', [0, ...self::MINUTE_STEPS], 'Please choose the buffer after the event');
		$notice = $choice('timeBeforeNextSlotMinutes', [0, ...self::MINUTE_STEPS, ...self::NOTICE_EXTRA], 'Please choose the minimum time before the next slot');
		$dailyMax = $input['dailyMax'] ?? null;
		if ($dailyMax !== null && (!is_int($dailyMax) || $dailyMax < 1 || $dailyMax > self::MAX_DAILY)) {
			throw new ValidationException('dailyMax', 'Please enter the maximum number of slots per day, or leave it empty');
		}

		$visibility = $text('visibility');
		if (!in_array($visibility, self::VISIBILITIES, true)) {
			throw new ValidationException('visibility', 'Please choose the visibility');
		}
		$calendars = $this->calendars($userId);
		$calendarUri = $text('calendarUri');
		$writable = array_column(array_filter($calendars, static fn (array $c): bool => $c['writable']), 'uri');
		if (!in_array($calendarUri, $writable, true)) {
			throw new ValidationException('calendarUri', 'Please choose a booking calendar');
		}
		$conflicts = $input['conflictCalendarUris'] ?? [];
		if (!is_array($conflicts) || !array_is_list($conflicts)) {
			throw new ValidationException('conflictCalendarUris', 'Please choose the calendars to check for conflicts');
		}
		$known = array_column($calendars, 'uri');
		foreach ($conflicts as $uri) {
			if (!is_string($uri) || !in_array($uri, $known, true)) {
				throw new ValidationException('conflictCalendarUris', 'Please choose the calendars to check for conflicts');
			}
		}
		// The booking calendar is always checked
		$conflicts = array_values(array_diff(array_unique($conflicts), [$calendarUri]));

		$dates = OneTimeDates::fromInput($text('timezone'), $input['days'] ?? null);
		foreach ($dates->ranges() as $range) {
			if ($range['end'] - $range['start'] < $length * 60) {
				throw new ValidationException('days', 'Each time range must fit at least one appointment');
			}
		}

		// Without Talk the form has no checkbox: keep what was set before
		$createTalkRoom = $this->canCreateTalkRooms($userId)
			? ($input['createTalkRoom'] ?? null) === true
			: ($current?->createTalkRoom ?? false);

		return [
			new ScheduleDraft(
				name: $name,
				description: $description,
				location: $location,
				lengthSeconds: $length * 60,
				targetCalendarUri: $calendarUri,
				availability: $dates->availability(),
				start: $dates->windowStart(),
				end: $dates->windowEnd(),
				createTalkRoom: $createTalkRoom,
				visibility: $visibility,
				conflictCalendarUris: $conflicts,
				incrementSeconds: $increment * 60,
				preparationSeconds: $preparation * 60,
				followupSeconds: $followup * 60,
				timeBeforeNextSlotSeconds: $notice * 60,
				dailyMax: $dailyMax,
			),
			$dates,
		];
	}

	/**
	 * Same rule as the Calendar's "Create a Talk room" option: Talk is installed and enabled
	 * for the user, and Talk's settings let them use it and start conversations.
	 */
	private function canCreateTalkRooms(string $userId): bool {
		try {
			$user = $this->userManager->get($userId);
			if ($user === null || !$this->talkBroker->hasBackend() || !$this->appManager->isEnabledForUser('spreed', $user)) {
				return false;
			}
			$groups = $this->groupManager->getUserGroupIds($user);
			foreach (['start_conversations', 'allowed_groups'] as $key) {
				$allowed = json_decode($this->appConfig->getValueString('spreed', $key, '[]'), true);
				if (is_array($allowed) && $allowed !== [] && array_intersect($allowed, $groups) === []) {
					return false;
				}
			}
			return true;
		} catch (\Throwable $e) {
			$this->logger->info('Quick Booking could not find out whether Talk rooms can be created', ['app' => Application::APP_ID, 'exception' => $e]);
			return false;
		}
	}

	/**
	 * The user's calendars, as the Calendar's editor offers them: writable ones (own or shared
	 * with them) to book into, all of them to check for conflicts.
	 *
	 * @return list<array{uri: string, name: string, color: string, writable: bool}>
	 */
	private function calendars(string $userId): array {
		$result = [];
		foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $userId) as $calendar) {
			if ($calendar->isDeleted()) {
				continue;
			}
			$writable = $calendar instanceof ICreateFromString
				&& (!$calendar instanceof ICalendarIsWritable || $calendar->isWritable());
			$result[] = [
				'uri' => $calendar->getUri(),
				'name' => $calendar->getDisplayName() ?? $calendar->getUri(),
				'color' => $calendar->getDisplayColor() ?? '',
				'writable' => $writable,
			];
		}
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize(Schedule $schedule, OneTimeDates $dates): array {
		return [
			'id' => $schedule->id,
			'token' => $schedule->token,
			'name' => $schedule->name,
			'description' => $schedule->description,
			'location' => $schedule->location,
			'lengthMinutes' => intdiv($schedule->lengthSeconds, 60),
			'incrementMinutes' => intdiv($schedule->incrementSeconds, 60),
			'preparationMinutes' => intdiv($schedule->preparationSeconds, 60),
			'followupMinutes' => intdiv($schedule->followupSeconds, 60),
			'timeBeforeNextSlotMinutes' => intdiv($schedule->timeBeforeNextSlot, 60),
			'dailyMax' => $schedule->dailyMax,
			'calendarUri' => $schedule->targetCalendarUri,
			'conflictCalendarUris' => $schedule->conflictCalendarUris,
			'visibility' => $schedule->visibility,
			'createTalkRoom' => $schedule->createTalkRoom,
			'timezone' => $dates->timezone->getName(),
			'days' => $dates->days,
			// The Calendar's own link: shared as usual, Quick Booking takes it over while it works
			'bookingUrl' => $this->urlGenerator->linkToRouteAbsolute(ContractCheck::FALLBACK_ROUTE, ['token' => $schedule->token]),
		];
	}
}
