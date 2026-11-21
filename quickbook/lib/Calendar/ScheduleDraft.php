<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

/**
 * The settings Quick Booking writes into a Calendar appointment schedule: every setting of the
 * one-time schedule form. The Calendar's "limit how far ahead" is never written.
 */
final class ScheduleDraft {
	/**
	 * @param array{timezoneId: string, slots: array<string, list<array{start: int, end: int}>>} $availability
	 * @param string $visibility ContractCheck::VISIBILITY_*
	 * @param list<string> $conflictCalendarUris extra calendars checked for conflicts
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly string $location,
		public readonly int $lengthSeconds,
		public readonly string $targetCalendarUri,
		public readonly array $availability,
		public readonly int $start,
		public readonly int $end,
		public readonly bool $createTalkRoom,
		public readonly string $visibility,
		public readonly array $conflictCalendarUris,
		public readonly int $incrementSeconds,
		public readonly int $preparationSeconds,
		public readonly int $followupSeconds,
		public readonly int $timeBeforeNextSlotSeconds,
		public readonly ?int $dailyMax,
	) {
	}
}
