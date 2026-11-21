<?php

declare(strict_types=1);

namespace OCA\QuickBook\Listener;

use OCA\QuickBook\OneTime\OneTimeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * The Calendar app deletes a removed user's schedules; this removes their one-time dates.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private OneTimeService $oneTime,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->oneTime->deleteForUser($event->getUser()->getUID());
		}
	}
}
