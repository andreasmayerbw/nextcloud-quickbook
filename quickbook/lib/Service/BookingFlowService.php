<?php

declare(strict_types=1);

namespace OCA\QuickBook\Service;

use DateTimeImmutable;
use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\Calendar\Schedule;
use OCA\QuickBook\Exception\CalendarUnavailableException;
use OCA\QuickBook\Exception\EmailLimitException;
use OCA\QuickBook\Exception\ScheduleNotFoundException;
use OCA\QuickBook\Exception\SlotUnavailableException;
use OCA\QuickBook\Exception\TemporaryFailureException;
use OCA\QuickBook\Exception\ValidationException;
use OCA\QuickBook\OneTime\OneTimeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Mail\IMailer;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * The booking flow behind the public page: slot listing and immediate booking.
 */
class BookingFlowService {
	private const MAX_NAME_LENGTH = 128;
	/** Size of the Calendar's column for it */
	private const MAX_EMAIL_LENGTH = 128;
	private const MAX_DESCRIPTION_LENGTH = 2000;
	/** How far ahead bookings are possible when the schedule sets no limit */
	private const DEFAULT_HORIZON = 2 * 365 * 86400;
	/** Bookings per email address, changeable with occ config:app:set quickbook email_limit / email_period */
	private const EMAIL_LIMIT = 3;
	private const EMAIL_PERIOD = 86400;

	public function __construct(
		private CalendarBridge $calendar,
		private ILockingProvider $lockingProvider,
		private IMailer $mailer,
		private ITimeFactory $timeFactory,
		private ILimiter $limiter,
		private IAppConfig $appConfig,
		private OneTimeService $oneTime,
	) {
	}

	/**
	 * Free slots on one calendar day in the visitor's timezone
	 *
	 * @return list<array{start: int, end: int}>
	 * @throws ValidationException|ScheduleNotFoundException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function getSlotsForDay(string $token, string $date, string $timeZone): array {
		$tz = Input::timezone($timeZone);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			throw new ValidationException('date', 'Invalid date');
		}
		$dayStart = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
		if ($dayStart === false || $dayStart->format('Y-m-d') !== $date) {
			throw new ValidationException('date', 'Invalid date');
		}
		$from = $dayStart->getTimestamp();
		$until = $dayStart->modify('+1 day')->getTimestamp();
		$now = $this->timeFactory->getTime();
		if ($until <= $now) {
			return [];
		}

		$schedule = $this->calendar->findSchedule($token);
		if ($from > $now + $this->horizon($schedule)) {
			return [];
		}
		$slots = $this->calendar->getAvailableSlots($schedule, $from, $until);
		// One-time schedules: the Calendar's weekly rules can cover more days than the chosen dates
		return $this->oneTime->datesForToken($schedule->token)?->filter($slots) ?? $slots;
	}

	/**
	 * Book a slot immediately (no email confirmation round trip)
	 *
	 * @throws ValidationException|EmailLimitException|ScheduleNotFoundException|SlotUnavailableException|CalendarUnavailableException|TemporaryFailureException
	 */
	public function book(string $token, int $start, int $end, string $timeZone, string $displayName, string $email, string $description): void {
		$displayName = Input::singleLine($displayName);
		$email = trim($email);
		$description = Input::multiLine($description);

		if ($displayName === '' || mb_strlen($displayName) > self::MAX_NAME_LENGTH) {
			throw new ValidationException('displayName', 'Please enter your name');
		}
		// The name is shown next to the email address in the organizer's mail: no look-alike addresses
		if (preg_match('/[@<>]/', $displayName) === 1) {
			throw new ValidationException('displayName', 'Please enter your name without @, < or >');
		}
		if (strlen($email) > self::MAX_EMAIL_LENGTH || !$this->mailer->validateMailAddress($email)) {
			throw new ValidationException('email', 'Please enter a valid email address');
		}
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new ValidationException('description', 'The comment is too long');
		}
		$tz = Input::timezone($timeZone);

		$schedule = $this->calendar->findSchedule($token);

		// Only plausible slots reach the Calendar code: exactly one appointment long,
		// in the future and within the booking horizon (also keeps the integers small)
		$now = $this->timeFactory->getTime();
		if ($start < $now || $start > $now + $this->horizon($schedule)) {
			throw new SlotUnavailableException('Slot is outside the bookable period');
		}
		if ($end - $start !== $schedule->lengthSeconds) {
			throw new ValidationException('start', 'Invalid time range');
		}
		if (!$this->oneTime->allowsBooking($schedule->token, $start, $end)) {
			throw new SlotUnavailableException('Slot is not on one of the dates');
		}

		$this->registerEmailBooking($email);

		// Serialize bookings per schedule so two visitors cannot take the same slot at once
		$lock = 'quickbook/schedule/' . $schedule->id;
		$this->acquireLock($lock);
		try {
			$this->calendar->bookConfirmed($schedule, $start, $end, $tz->getName(), $displayName, $email, $description);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Limit bookings per email address with Nextcloud's rate limiter, on top of the limit per
	 * visitor. Stops one visitor with many IP addresses from sending booking emails to someone.
	 *
	 * @throws EmailLimitException
	 */
	private function registerEmailBooking(string $email): void {
		$limit = $this->appConfig->getValueInt(Application::APP_ID, 'email_limit', self::EMAIL_LIMIT);
		$period = $this->appConfig->getValueInt(Application::APP_ID, 'email_period', self::EMAIL_PERIOD);
		if ($limit <= 0 || $period <= 0) {
			return;
		}
		try {
			// The limiter counts per identifier and IP subnet. A fixed address makes the email
			// address the only key, wherever the requests come from.
			$this->limiter->registerAnonRequest(
				'quickbook-email-' . hash('sha256', mb_strtolower($email)),
				$limit,
				$period,
				'0.0.0.0',
			);
		} catch (IRateLimitExceededException $e) {
			throw new EmailLimitException('Too many bookings for this email address', 0, $e);
		}
	}

	private function acquireLock(string $lock): void {
		for ($attempt = 0; ; $attempt++) {
			try {
				$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Quick Booking');
				return;
			} catch (LockedException $e) {
				if ($attempt >= 20) {
					throw new TemporaryFailureException('Another booking is in progress', 0, $e);
				}
				usleep(250_000);
			}
		}
	}

	private function horizon(Schedule $schedule): int {
		return $schedule->futureLimit !== null && $schedule->futureLimit > 0
			? min($schedule->futureLimit, self::DEFAULT_HORIZON)
			: self::DEFAULT_HORIZON;
	}
}
