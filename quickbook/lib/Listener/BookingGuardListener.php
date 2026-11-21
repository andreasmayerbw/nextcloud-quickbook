<?php

declare(strict_types=1);

namespace OCA\QuickBook\Listener;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCA\QuickBook\OneTime\OneTimeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses bookings of a one-time schedule outside its dates, also those made on the
 * Calendar's own booking page. Its weekly rules can cover more days than the chosen dates
 * when they are more than a week apart.
 *
 * Runs inside the Calendar's booking code: anything unexpected lets the booking through.
 *
 * @template-implements IEventListener<Event>
 */
class BookingGuardListener implements IEventListener {
	public function __construct(
		private CalendarBridge $calendar,
		private OneTimeService $oneTime,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$booking = $this->calendar->bookingFromEvent($event);
		if ($booking === null) {
			return;
		}
		try {
			$allowed = $this->oneTime->allowsBooking($booking['token'], $booking['start'], $booking['end']);
		} catch (\Throwable $e) {
			$this->logger->warning('Quick Booking could not check a booking against one-time dates', ['app' => Application::APP_ID, 'exception' => $e]);
			return;
		}
		if (!$allowed) {
			throw $this->calendar->slotUnavailableError('Not one of the dates of this one-time schedule');
		}
	}
}
