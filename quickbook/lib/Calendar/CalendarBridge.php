<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\SlotUnavailableException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCA\QuickBook\Health\HealthState;
use OCA\QuickBook\Middleware\CalendarLinkMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The only place that touches the Calendar app's internal code.
 *
 * Every call goes through {@see self::call()}, which:
 *  - refuses to run while the integration is marked broken,
 *  - re-verifies the contract once whenever the Calendar version changes,
 *  - on an unexpected error (missing class/method, wrong types, ...) re-checks the
 *    contract: if it fails, the Calendar code changed and the integration is switched off;
 *    if it holds, the error may have been caused by the request itself, so it only
 *    switches off when such errors come from several networks within an hour.
 * Ordinary failures (slot taken, database hiccup) never switch anything off.
 */
class CalendarBridge {
	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IDBConnection $db,
		private HealthState $health,
		private ContractCheck $contract,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether bookings can currently go through this app.
	 */
	public function isUsable(): bool {
		if (!$this->appManager->isEnabledForAnyone('calendar')) {
			$this->health->markBroken(HealthState::SOURCE_CHECK, 'The Calendar app is not enabled');
			return false;
		}

		$versions = $this->health->currentVersions();
		if ($versions !== $this->health->getCheckedVersion()) {
			// Calendar, Nextcloud or this app was updated (or just installed): quick contract check.
			// The background job runs the full self-check soon after.
			$this->appManager->loadApp('calendar');
			$problems = $this->contract->run();
			if ($problems !== []) {
				$this->health->markBroken(HealthState::SOURCE_CHECK, "Not compatible ($versions): " . implode('; ', $problems));
				$this->health->setCheckedVersion($versions);
				return false;
			}
			if (!$this->health->isBroken() || $this->health->getBrokenSource() === HealthState::SOURCE_RUNTIME) {
				$this->health->markWorking($versions);
			} else {
				// Switched off by a failed self-check: only a passing full self-check switches on again
				$this->health->setCheckedVersion($versions);
			}
		}

		return !$this->health->isBroken();
	}

	/**
	 * @throws ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function findSchedule(string $token): Schedule {
		return $this->call('find schedule', function () use ($token): Schedule {
			$service = $this->get(ContractCheck::CONFIG_SERVICE);
			try {
				$config = $service->findByToken($token);
			} catch (\Exception $e) {
				if (is_a($e, ContractCheck::CLIENT_EXCEPTION)) {
					throw new ScheduleNotFoundException('No schedule for token', 0, $e);
				}
				throw $e;
			}
			return $this->toSchedule($config);
		});
	}

	/**
	 * Free slots according to the Calendar's own rules (availability, buffers,
	 * daily limit, conflicts, minimum notice, future limit).
	 *
	 * @return list<array{start: int, end: int}>
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function getAvailableSlots(Schedule $schedule, int $start, int $end): array {
		return $this->call('get available slots', function () use ($schedule, $start, $end): array {
			$intervals = $this->get(ContractCheck::BOOKING_SERVICE)->getAvailableSlots($schedule->config, $start, $end);
			return $this->intervalsToArray($intervals);
		});
	}

	/**
	 * Book a slot and confirm it right away. The Calendar app writes the event,
	 * creates a Talk room if configured, and sends the booking information emails.
	 *
	 * @throws SlotUnavailableException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function bookConfirmed(Schedule $schedule, int $start, int $end, string $timezone, string $displayName, string $email, string $description): void {
		$this->call('book appointment', function () use ($schedule, $start, $end, $timezone, $displayName, $email, $description): void {
			$mapper = $this->get(ContractCheck::BOOKING_MAPPER);
			$service = $this->get(ContractCheck::BOOKING_SERVICE);

			$booking = $this->newBooking($schedule, $start, $end, $timezone, $displayName, $email, $description === '' ? null : $description);
			$booking->setCreatedAt($this->timeFactory->getTime());
			$mapper->insert($booking);

			try {
				$service->confirmBooking($booking, $schedule->config);
			} catch (\Throwable $e) {
				try {
					$mapper->delete($booking);
				} catch (\Throwable $deleteError) {
					// Unconfirmed bookings are removed by the Calendar's cleanup job after a day
					$this->logger->warning('Could not remove failed booking', ['app' => Application::APP_ID, 'exception' => $deleteError]);
				}
				if (is_a($e, ContractCheck::NO_SLOT_EXCEPTION)) {
					throw new SlotUnavailableException('Slot is not available any more', 0, $e);
				}
				throw $e;
			}
		});
	}

	/**
	 * Whether one-time schedules can be created and edited. Bookings do not depend on this.
	 */
	public function isOneTimeUsable(): bool {
		if (!$this->isUsable()) {
			return false;
		}
		try {
			$problems = $this->contract->runOneTime();
		} catch (\Throwable $e) {
			$problems = [get_class($e) . ': ' . $e->getMessage()];
		}
		if ($problems !== []) {
			$this->logger->warning('Quick Booking one-time schedules are switched off, the Calendar code changed: ' . implode('; ', $problems), ['app' => Application::APP_ID]);
			return false;
		}
		return true;
	}

	/**
	 * A schedule of this user, by id.
	 *
	 * @throws ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function findScheduleForUser(int $id, string $userId): Schedule {
		return $this->callOneTime('find schedule', function () use ($id, $userId): Schedule {
			try {
				$config = $this->get(ContractCheck::CONFIG_SERVICE)->findByIdAndUser($id, $userId);
			} catch (\Exception $e) {
				if (is_a($e, ContractCheck::CLIENT_EXCEPTION)) {
					throw new ScheduleNotFoundException('No schedule with this id for this user', 0, $e);
				}
				throw $e;
			}
			return $this->toSchedule($config);
		});
	}

	/**
	 * All schedules of this user (weekly and one-time).
	 *
	 * @return list<Schedule>
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function findSchedulesForUser(string $userId): array {
		return $this->callOneTime('list schedules', function () use ($userId): array {
			$configs = $this->get(ContractCheck::CONFIG_SERVICE)->getAllAppointmentConfigurations($userId);
			if (!is_array($configs)) {
				throw new IncompatibleCalendarException('getAllAppointmentConfigurations() returned ' . get_debug_type($configs));
			}
			return array_values(array_map(fn ($config) => $this->toSchedule($config), $configs));
		});
	}

	/**
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function deleteSchedule(Schedule $schedule): void {
		$this->callOneTime('delete schedule', function () use ($schedule): void {
			$this->get(ContractCheck::CONFIG_SERVICE)->delete($schedule->id, $schedule->userId);
		});
	}

	/**
	 * New schedule with the Calendar's defaults for everything the draft does not set.
	 *
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function createSchedule(string $userId, ScheduleDraft $draft): Schedule {
		return $this->callOneTime('create schedule', function () use ($userId, $draft): Schedule {
			$config = $this->get(ContractCheck::CONFIG_SERVICE)->create(
				$draft->name,
				$draft->description,
				$draft->location === '' ? null : $draft->location,
				$draft->visibility,
				$userId,
				$draft->targetCalendarUri,
				$draft->availability,
				$draft->lengthSeconds,
				$draft->incrementSeconds,
				$draft->preparationSeconds,
				$draft->followupSeconds,
				$draft->timeBeforeNextSlotSeconds,
				$draft->dailyMax,
				$draft->conflictCalendarUris, // the target calendar is always checked too
				$draft->start,
				$draft->end,
				null, // no limit how far ahead
				$draft->createTalkRoom,
			);
			return $this->toSchedule($config);
		});
	}

	/**
	 * Writes the draft into an existing schedule. Settings the draft does not cover stay as they are.
	 *
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function updateSchedule(Schedule $schedule, ScheduleDraft $draft): Schedule {
		return $this->callOneTime('update schedule', function () use ($schedule, $draft): Schedule {
			$config = clone $schedule->config;
			$config->setName($draft->name);
			$config->setDescription($draft->description);
			$config->setLocation($draft->location === '' ? null : $draft->location);
			$config->setLength($draft->lengthSeconds);
			$config->setIncrement($draft->incrementSeconds);
			$config->setPreparationDuration($draft->preparationSeconds);
			$config->setFollowupDuration($draft->followupSeconds);
			$config->setTimeBeforeNextSlot($draft->timeBeforeNextSlotSeconds);
			$config->setDailyMax($draft->dailyMax);
			$config->setTargetCalendarUri($draft->targetCalendarUri);
			$config->setAvailabilityAsArray($draft->availability);
			$config->setStart($draft->start);
			$config->setEnd($draft->end);
			$config->setCreateTalkRoom($draft->createTalkRoom);
			$config->setVisibility($draft->visibility);
			$config->setCalendarFreeBusyUrisAsArray($draft->conflictCalendarUris);
			return $this->toSchedule($this->get(ContractCheck::CONFIG_SERVICE)->update($config));
		});
	}

	/**
	 * Puts back the weekly rules and date window of a one-time schedule, e.g. after it was
	 * saved in the Calendar's own editor (which does not know the date window and clears it).
	 *
	 * @param array{timezoneId: string, slots: array<string, list<array{start: int, end: int}>>} $availability
	 * @throws CalendarUnavailableException|TemporaryFailureException
	 */
	public function setDateWindow(Schedule $schedule, array $availability, int $start, int $end): Schedule {
		return $this->callOneTime('restore date window', function () use ($schedule, $availability, $start, $end): Schedule {
			$config = clone $schedule->config;
			$config->setAvailabilityAsArray($availability);
			$config->setStart($start);
			$config->setEnd($end);
			return $this->toSchedule($this->get(ContractCheck::CONFIG_SERVICE)->update($config));
		});
	}

	/**
	 * Schedule token and times of a booking from the Calendar's "before appointment booked" event.
	 * Works while the integration is switched off, so the Calendar's own page is covered too.
	 *
	 * @return array{token: string, start: int, end: int}|null null when it is not that event or it looks different
	 */
	public function bookingFromEvent(object $event): ?array {
		try {
			if (!is_a($event, ContractCheck::BOOKED_EVENT)) {
				return null;
			}
			$booking = $event->getBooking();
			$token = $event->getConfig()->getToken();
			$start = $booking->getStart();
			$end = $booking->getEnd();
			if (!is_string($token) || !is_numeric($start) || !is_numeric($end)) {
				return null;
			}
			return ['token' => $token, 'start' => (int)$start, 'end' => (int)$end];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The error that makes the Calendar answer "this slot is not available any more".
	 */
	public function slotUnavailableError(string $message): \Throwable {
		$class = ContractCheck::NO_SLOT_EXCEPTION;
		try {
			if (class_exists($class) && is_subclass_of($class, \Throwable::class)) {
				return new $class($message);
			}
		} catch (\Throwable) {
		}
		return new SlotUnavailableException($message);
	}

	/**
	 * URL that always shows the Calendar's own booking page (with email confirmation).
	 * The marker stops {@see CalendarLinkMiddleware} from redirecting it back to Quick Booking.
	 */
	public function fallbackUrl(string $token): ?string {
		try {
			if (!$this->appManager->isEnabledForAnyone('calendar')) {
				return null;
			}
			return $this->urlGenerator->linkToRouteAbsolute(ContractCheck::FALLBACK_ROUTE, ['token' => $token, CalendarLinkMiddleware::FALLBACK_PARAMETER => 'fallback']);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Full self-check against the real Calendar code. Never changes the health state itself.
	 *
	 * @return list<array{name: string, status: string, detail: string}>
	 */
	public function selfTest(): array {
		$results = [];
		// The id and detail id are for the report in the reader's language, see HealthText
		$add = static function (string $id, string $name, string $status, string $detail = '', string $detailId = '', array $detailArgs = []) use (&$results): void {
			$results[] = ['id' => $id, 'name' => $name, 'status' => $status, 'detail' => $detail, 'detailId' => $detailId, 'detailArgs' => $detailArgs];
		};
		$describe = static fn (\Throwable $e): string => get_class($e) . ': ' . $e->getMessage();

		if (!$this->appManager->isEnabledForAnyone('calendar')) {
			$add('calendar_enabled', 'Calendar app enabled', 'fail', 'The Calendar app is not enabled', 'calendar_disabled');
			return $results;
		}
		$add('calendar_enabled', 'Calendar app enabled', 'pass', $this->health->currentCalendarVersion());

		try {
			$this->appManager->loadApp('calendar');
			$problems = $this->contract->run();
		} catch (\Throwable $e) {
			$problems = [$describe($e)];
		}
		if ($problems !== []) {
			$add('contract', 'Calendar code contract', 'fail', implode('; ', $problems));
			return $results;
		}
		$add('contract', 'Calendar code contract', 'pass', 'all classes, methods and fields found', 'contract_holds');

		try {
			$oneTimeProblems = $this->contract->runOneTime();
		} catch (\Throwable $e) {
			$oneTimeProblems = [$describe($e)];
		}
		// Not fatal: bookings work without it, only creating and editing one-time schedules is off
		if ($oneTimeProblems === []) {
			$add('one_time', 'One-time schedules', 'pass', 'can be created and edited', 'one_time_usable');
		} else {
			$add('one_time', 'One-time schedules', 'warn', implode('; ', $oneTimeProblems));
		}

		try {
			$bookingService = $this->get(ContractCheck::BOOKING_SERVICE);
			$configService = $this->get(ContractCheck::CONFIG_SERVICE);
			$mapper = $this->get(ContractCheck::BOOKING_MAPPER);
			$add('services', 'Calendar services', 'pass', 'booking service, schedule service and booking storage load', 'services_load');
		} catch (\Throwable $e) {
			$add('services', 'Calendar services', 'fail', $describe($e));
			return $results;
		}

		$fallback = null;
		try {
			$fallback = $this->urlGenerator->linkToRoute(ContractCheck::FALLBACK_ROUTE, ['token' => 'selftest']);
		} catch (\Throwable $e) {
		}
		// Not fatal: only the fallback for visitors is affected
		$add('fallback', 'Standard booking page (fallback)', $fallback !== null ? 'pass' : 'warn', $fallback ?? 'route ' . ContractCheck::FALLBACK_ROUTE . ' not found');

		$token = $this->findAnyScheduleToken();
		if ($token === null) {
			$add('slots', 'Slot lookup', 'skip', 'no appointment schedule exists yet', 'no_schedule');
			$add('storage', 'Booking storage', 'skip', 'no appointment schedule exists yet', 'no_schedule');
			return $results;
		}

		try {
			$schedule = $this->toSchedule($configService->findByToken($token));
			$now = $this->timeFactory->getTime();
			$slots = $this->intervalsToArray($bookingService->getAvailableSlots($schedule->config, $now, $now + 7 * 86400));
			$add('slots', 'Slot lookup', 'pass', sprintf('schedule "%s", free slots in the next 7 days: %d', $schedule->name, count($slots)), 'free_slots', [$schedule->name, (string)count($slots)]);
		} catch (\Throwable $e) {
			$add('slots', 'Slot lookup', 'fail', $describe($e));
			return $results;
		}

		try {
			$booking = $this->newBooking($schedule, $now, $now + 60, 'UTC', 'Quick Booking self-check', 'selfcheck@example.invalid', null);
			// createdAt 0 makes the Calendar's cleanup job remove it if the delete below fails
			$booking->setCreatedAt(0);
			$mapper->insert($booking);
			$mapper->delete($booking);
			$add('storage', 'Booking storage', 'pass', 'test booking stored and removed', 'storage_works');
		} catch (\Throwable $e) {
			$add('storage', 'Booking storage', 'fail', $describe($e));
		}

		return $results;
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	private function call(string $name, callable $operation): mixed {
		if (!$this->isUsable()) {
			throw new CalendarUnavailableException('The Calendar integration is switched off');
		}

		try {
			$this->appManager->loadApp('calendar');
			return $operation();
		} catch (ScheduleNotFoundException|SlotUnavailableException $e) {
			throw $e;
		} catch (\Error|\ReflectionException|\BadFunctionCallException|ContainerExceptionInterface|IncompatibleCalendarException $e) {
			$reason = sprintf('Could not %s: %s: %s', $name, get_class($e), $e->getMessage());

			try {
				$problems = $this->contract->run();
			} catch (\Throwable $checkError) {
				$problems = [get_class($checkError) . ': ' . $checkError->getMessage()];
			}
			if ($problems !== []) {
				// The Calendar code changed: the self-check will switch back on once it is fixed
				$this->health->markBroken(HealthState::SOURCE_CHECK, $reason . ' (Calendar not compatible: ' . implode('; ', $problems) . ')', $e);
				throw new CalendarUnavailableException('The Calendar integration broke', 0, $e);
			}

			// Code is intact: could be this request's data. Only a pattern across networks counts.
			$networks = $this->health->recordUnexplainedFailure($this->clientNetwork());
			$this->logger->error("Quick Booking could not $name (unexpected error, $networks network(s) affected in the last hour)", ['app' => Application::APP_ID, 'exception' => $e]);
			if ($networks >= HealthState::RUNTIME_FAILURE_NETWORKS) {
				$this->health->markBroken(HealthState::SOURCE_RUNTIME, $reason . " (bookings from $networks different networks failed within an hour)", $e);
				throw new CalendarUnavailableException('The Calendar integration broke', 0, $e);
			}
			throw new TemporaryFailureException("Could not $name", 0, $e);
		} catch (\Throwable $e) {
			$this->logger->error("Quick Booking could not $name", ['app' => Application::APP_ID, 'exception' => $e]);
			throw new TemporaryFailureException("Could not $name", 0, $e);
		}
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	private function callOneTime(string $name, callable $operation): mixed {
		if (!$this->isOneTimeUsable()) {
			throw new CalendarUnavailableException('One-time schedules are switched off');
		}
		return $this->call($name, $operation);
	}

	/**
	 * Visitor's network (IPv4 /24, IPv6 /64) as a short hash, so failures from one
	 * attacker count once without storing IP addresses.
	 */
	private function clientNetwork(): string {
		$ip = $this->request->getRemoteAddress();
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return substr(hash('sha256', $ip), 0, 16);
		}
		$prefix = strlen($packed) === 4 ? substr($packed, 0, 3) : substr($packed, 0, 8);
		return substr(hash('sha256', $prefix), 0, 16);
	}

	private function get(string $class): object {
		$service = $this->container->get($class);
		if (!is_a($service, $class)) {
			throw new IncompatibleCalendarException("Container returned " . get_debug_type($service) . " for $class");
		}
		return $service;
	}

	private function newBooking(Schedule $schedule, int $start, int $end, string $timezone, string $displayName, string $email, ?string $description): object {
		$class = ContractCheck::BOOKING;
		$booking = new $class();
		$booking->setApptConfigId($schedule->id);
		$booking->setToken($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
		$booking->setDisplayName($displayName);
		$booking->setDescription($description);
		$booking->setEmail($email);
		$booking->setStart($start);
		$booking->setEnd($end);
		$booking->setTimezone($timezone);
		$booking->setConfirmed(false);
		return $booking;
	}

	private function toSchedule(mixed $config): Schedule {
		if (!is_a($config, ContractCheck::CONFIG)) {
			throw new IncompatibleCalendarException('Expected an appointment config, got ' . get_debug_type($config));
		}
		try {
			$availability = $config->getAvailability() === null ? [] : $config->getAvailabilityAsArray();
		} catch (\JsonException) {
			$availability = [];
		}
		return new Schedule(
			id: (int)$config->getId(),
			token: (string)$config->getToken(),
			userId: (string)$config->getUserId(),
			name: (string)$config->getName(),
			description: (string)($config->getDescription() ?? ''),
			location: (string)($config->getLocation() ?? ''),
			lengthSeconds: (int)$config->getLength(),
			start: $config->getStart() === null ? null : (int)$config->getStart(),
			end: $config->getEnd() === null ? null : (int)$config->getEnd(),
			futureLimit: $config->getFutureLimit() === null ? null : (int)$config->getFutureLimit(),
			timeBeforeNextSlot: (int)($config->getTimeBeforeNextSlot() ?? 0),
			targetCalendarUri: (string)$config->getTargetCalendarUri(),
			createTalkRoom: (bool)$this->optional(static fn () => $config->getCreateTalkRoom(), false),
			visibility: (string)$this->optional(static fn () => $config->getVisibility(), ContractCheck::VISIBILITY_PUBLIC),
			conflictCalendarUris: array_values(array_filter(
				(array)$this->optional(static fn () => $config->getCalendarFreebusyUrisAsArray(), []),
				'is_string',
			)),
			incrementSeconds: (int)$this->optional(static fn () => $config->getIncrement(), (int)$config->getLength()),
			preparationSeconds: (int)$this->optional(static fn () => $config->getPreparationDuration(), 0),
			followupSeconds: (int)$this->optional(static fn () => $config->getFollowupDuration(), 0),
			dailyMax: self::intOrNull($this->optional(static fn () => $config->getDailyMax(), null)),
			availability: is_array($availability) ? $availability : [],
			config: $config,
		);
	}

	private static function intOrNull(mixed $value): ?int {
		return is_numeric($value) ? (int)$value : null;
	}

	/**
	 * Settings only one-time schedules need: when the Calendar drops one, bookings must still work.
	 */
	private function optional(callable $read, mixed $default): mixed {
		try {
			return $read() ?? $default;
		} catch (\Throwable) {
			return $default;
		}
	}

	/**
	 * @return list<array{start: int, end: int}>
	 */
	private function intervalsToArray(mixed $intervals): array {
		if (!is_array($intervals)) {
			throw new IncompatibleCalendarException('getAvailableSlots() returned ' . get_debug_type($intervals));
		}
		$slots = [];
		foreach ($intervals as $interval) {
			if (!is_a($interval, ContractCheck::INTERVAL)) {
				throw new IncompatibleCalendarException('getAvailableSlots() returned a ' . get_debug_type($interval));
			}
			$start = $interval->getStart();
			$end = $interval->getEnd();
			if (!is_int($start) || !is_int($end) || $end <= $start) {
				throw new IncompatibleCalendarException('getAvailableSlots() returned an invalid interval');
			}
			$slots[] = ['start' => $start, 'end' => $end];
		}
		usort($slots, static fn (array $a, array $b) => $a['start'] <=> $b['start']);
		return $slots;
	}

	private function findAnyScheduleToken(): ?string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('token')->from('calendar_appt_configs')->setMaxResults(1);
			$result = $qb->executeQuery();
			$token = $result->fetchOne();
			$result->closeCursor();
			return is_string($token) ? $token : null;
		} catch (\Throwable $e) {
			$this->logger->info('Quick Booking self-check could not look up a schedule', ['app' => Application::APP_ID, 'exception' => $e]);
			return null;
		}
	}
}
