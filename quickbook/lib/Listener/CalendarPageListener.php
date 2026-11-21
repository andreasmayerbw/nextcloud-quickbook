<?php

declare(strict_types=1);

namespace OCA\QuickBook\Listener;

use OCA\QuickBook\AppInfo\Application;
use OCA\QuickBook\Calendar\CalendarBridge;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Adds the "One-time appointment schedule" option to the Calendar app's own page.
 * Only while one-time schedules work; otherwise the Calendar page stays as it is.
 *
 * @template-implements IEventListener<Event>
 */
class CalendarPageListener implements IEventListener {
	public function __construct(
		private CalendarBridge $calendar,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || !$event->isLoggedIn()) {
			return;
		}
		$response = $event->getResponse();
		if ($response->getApp() !== 'calendar' || $response->getTemplateName() !== 'main') {
			return;
		}
		try {
			if (!$this->calendar->isOneTimeUsable()) {
				return;
			}
			Util::addScript(Application::APP_ID, 'calendar-onetime');
			Util::addStyle(Application::APP_ID, 'calendar-onetime');
		} catch (\Throwable $e) {
			// Never break the Calendar page
			$this->logger->warning('Quick Booking could not add one-time schedules to the Calendar page', ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
