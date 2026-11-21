<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

/**
 * Read-only copy of a Calendar appointment schedule ("appointment config").
 *
 * Everything outside the Calendar namespace of this app works with this class,
 * never with the Calendar app's own entity.
 */
final class Schedule {
	/**
	 * @param array<string, mixed> $availability decoded availability rules ({timezoneId, slots})
	 * @param object $config the Calendar's AppointmentConfig, only to be used by CalendarBridge
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $token,
		public readonly string $userId,
		public readonly string $name,
		public readonly string $description,
		public readonly string $location,
		public readonly int $lengthSeconds,
		public readonly ?int $start,
		public readonly ?int $end,
		public readonly ?int $futureLimit,
		public readonly int $timeBeforeNextSlot,
		public readonly string $targetCalendarUri,
		/** "Create a Talk room" for each booking; false when the Calendar has no such setting */
		public readonly bool $createTalkRoom,
		/** ContractCheck::VISIBILITY_* */
		public readonly string $visibility,
		/** @var list<string> extra calendars checked for conflicts (the target calendar always is) */
		public readonly array $conflictCalendarUris,
		/** Time between possible slot starts */
		public readonly int $incrementSeconds,
		/** Buffers before and after each appointment, kept free of other events */
		public readonly int $preparationSeconds,
		public readonly int $followupSeconds,
		/** Maximum bookings per day, null for no limit */
		public readonly ?int $dailyMax,
		public readonly array $availability,
		public readonly object $config,
	) {
	}

	/**
	 * Weekdays that have availability rules, as two letter codes (MO, TU, ...)
	 *
	 * @return list<string>
	 */
	public function availableWeekdays(): array {
		$slots = $this->availability['slots'] ?? [];
		if (!is_array($slots)) {
			return [];
		}
		return array_values(array_map('strval', array_keys(array_filter($slots, static fn ($daySlots) => !empty($daySlots)))));
	}

	public function availabilityTimezone(): ?string {
		$tz = $this->availability['timezoneId'] ?? null;
		return is_string($tz) && $tz !== '' ? $tz : null;
	}
}
