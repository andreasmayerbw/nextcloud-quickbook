<?php

declare(strict_types=1);

namespace OCA\QuickBook\OneTime;

use DateTimeImmutable;
use DateTimeZone;
use OCA\QuickBook\Exception\ValidationException;
use OCA\QuickBook\Service\Input;

/**
 * The exact dates and times of a one-time appointment schedule, in the organizer's timezone.
 *
 * Calendar schedules only know weekly rules, so this is the source of truth:
 * {@see self::availability()} and {@see self::windowStart()}/{@see self::windowEnd()} give the
 * closest Calendar settings, and {@see self::contains()} decides which slots really belong to it.
 */
final class OneTimeDates {
	public const MAX_DAYS = 100;
	private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
	private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

	/**
	 * @param list<array{date: string, from: string, to: string}> $days sorted, unique
	 */
	private function __construct(
		public readonly DateTimeZone $timezone,
		public readonly array $days,
	) {
	}

	/**
	 * @param mixed $days list of {date: 'YYYY-MM-DD', from: 'HH:MM', to: 'HH:MM'}
	 * @throws ValidationException
	 */
	public static function fromInput(string $timezone, mixed $days): self {
		$tz = Input::timezone($timezone, 'timezone');
		if (!is_array($days) || !array_is_list($days) || $days === []) {
			throw new ValidationException('days', 'Please add at least one date');
		}
		if (count($days) > self::MAX_DAYS) {
			throw new ValidationException('days', 'Too many dates');
		}

		$clean = [];
		foreach ($days as $day) {
			$date = is_array($day) ? ($day['date'] ?? null) : null;
			$from = is_array($day) ? ($day['from'] ?? null) : null;
			$to = is_array($day) ? ($day['to'] ?? null) : null;
			if (!is_string($date) || preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $m) !== 1
				|| (int)$m[1] < 2000 || (int)$m[1] > 2100
				|| DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
				throw new ValidationException('days', 'Please enter a valid date');
			}
			if (!is_string($from) || !is_string($to) || preg_match(self::TIME, $from) !== 1 || preg_match(self::TIME, $to) !== 1) {
				throw new ValidationException('days', 'Please enter valid times');
			}
			if ($from >= $to) {
				throw new ValidationException('days', 'The end time must be after the start time');
			}
			$clean["$date $from $to"] = ['date' => $date, 'from' => $from, 'to' => $to];
		}
		ksort($clean);
		return new self($tz, array_values($clean));
	}

	/**
	 * @throws \UnexpectedValueException when the stored value is damaged
	 */
	public static function fromStored(string $timezone, string $json): self {
		try {
			return self::fromInput($timezone, json_decode($json, true, 4, JSON_THROW_ON_ERROR));
		} catch (ValidationException|\JsonException $e) {
			throw new \UnexpectedValueException('Stored one-time dates are invalid', 0, $e);
		}
	}

	public function toJson(): string {
		return json_encode($this->days, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return list<array{start: int, end: int}>
	 */
	public function ranges(): array {
		return array_map(fn (array $day): array => [
			'start' => $this->at($day['date'], $day['from'])->getTimestamp(),
			'end' => $this->at($day['date'], $day['to'])->getTimestamp(),
		], $this->days);
	}

	/**
	 * Whether an appointment from $start to $end lies completely within one of the dates' times.
	 */
	public function contains(int $start, int $end): bool {
		return $this->filter([['start' => $start, 'end' => $end]]) !== [];
	}

	/**
	 * The slots that lie completely within one of the dates' times.
	 *
	 * @param list<array{start: int, end: int}> $slots
	 * @return list<array{start: int, end: int}>
	 */
	public function filter(array $slots): array {
		$ranges = $this->ranges();
		return array_values(array_filter($slots, static function (array $slot) use ($ranges): bool {
			foreach ($ranges as $range) {
				if ($slot['start'] >= $range['start'] && $slot['end'] <= $range['end']) {
					return true;
				}
			}
			return false;
		}));
	}

	/** Midnight at the start of the first date */
	public function windowStart(): int {
		return $this->at($this->days[0]['date'], '00:00')->getTimestamp();
	}

	/** Midnight at the end of the last date */
	public function windowEnd(): int {
		$last = max(array_column($this->days, 'date'));
		return $this->at($last, '00:00')->modify('+1 day')->getTimestamp();
	}

	/**
	 * Weekly rules in the Calendar's format: every weekday that has a date gets that date's times.
	 * Together with the window this matches the dates exactly, unless two dates are more than
	 * a week apart (then {@see self::contains()} keeps the days in between out).
	 *
	 * @return array{timezoneId: string, slots: array<string, list<array{start: int, end: int}>>}
	 */
	public function availability(): array {
		$slots = array_fill_keys(self::WEEKDAYS, []);
		foreach ($this->days as $day) {
			$start = $this->at($day['date'], $day['from']);
			$weekday = self::WEEKDAYS[(int)$start->format('N') - 1];
			$times = [$day['from'], $day['to']];
			$known = array_map(fn (array $s): array => [$this->time($s['start']), $this->time($s['end'])], $slots[$weekday]);
			if (!in_array($times, $known, true)) {
				$slots[$weekday][] = ['start' => $start->getTimestamp(), 'end' => $this->at($day['date'], $day['to'])->getTimestamp()];
			}
		}
		return ['timezoneId' => $this->timezone->getName(), 'slots' => $slots];
	}

	/**
	 * Dates whose times have not ended yet
	 *
	 * @return list<string> YYYY-MM-DD
	 */
	public function upcomingDates(int $now): array {
		$dates = [];
		$ranges = $this->ranges();
		foreach ($this->days as $i => $day) {
			if ($ranges[$i]['end'] > $now) {
				$dates[$day['date']] = true;
			}
		}
		return array_keys($dates);
	}

	private function at(string $date, string $time): DateTimeImmutable {
		return new DateTimeImmutable("$date $time", $this->timezone);
	}

	private function time(int $timestamp): string {
		return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->format('H:i');
	}
}
