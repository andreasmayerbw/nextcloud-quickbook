<?php

declare(strict_types=1);

namespace OCA\QuickBook\AppInfo;

use OCA\QuickBook\Calendar\ContractCheck;
use OCA\QuickBook\Listener\BookingGuardListener;
use OCA\QuickBook\Listener\CalendarPageListener;
use OCA\QuickBook\Listener\UserDeletedListener;
use OCA\QuickBook\Middleware\CalendarLinkMiddleware;
use OCA\QuickBook\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'quickbook';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Global: it acts on the Calendar app's booking page
		$context->registerMiddleware(CalendarLinkMiddleware::class, true);
		$context->registerNotifierService(Notifier::class);
		// One-time schedules: the option in the Calendar app, and no bookings outside their dates
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, CalendarPageListener::class);
		$context->registerEventListener(ContractCheck::BOOKED_EVENT, BookingGuardListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
