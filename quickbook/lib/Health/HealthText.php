<?php

declare(strict_types=1);

namespace OCA\QuickBook\Health;

use OCP\IL10N;

/**
 * The self-check report in the reader's language.
 *
 * The report is stored once and read by admins with different languages, so the rows carry
 * ids and the texts are made when shown. Error details (class names, messages from the
 * Calendar's code) stay as they are, like in the log. occ shows the English texts.
 */
final class HealthText {
	/**
	 * @param array<string, mixed> $row
	 */
	public static function name(IL10N $l, array $row): string {
		return match ($row['id'] ?? '') {
			'components' => $l->t('Quick Booking components'),
			'calendar_enabled' => $l->t('Calendar app enabled'),
			'contract' => $l->t('Calendar code contract'),
			'one_time' => $l->t('One-time appointment schedules'),
			'services' => $l->t('Calendar services'),
			'fallback' => $l->t('Standard booking page (fallback)'),
			'slots' => $l->t('Slot lookup'),
			'storage' => $l->t('Booking storage'),
			default => (string)($row['name'] ?? ''),
		};
	}

	public static function status(IL10N $l, string $status): string {
		return match ($status) {
			'pass' => $l->t('Passed'),
			'fail' => $l->t('Failed'),
			'warn' => $l->t('Warning'),
			'skip' => $l->t('Skipped'),
			default => $status,
		};
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function detail(IL10N $l, array $row): string {
		$args = array_map('strval', array_values(is_array($row['detailArgs'] ?? null) ? $row['detailArgs'] : []));
		return match ($row['detailId'] ?? '') {
			'components_load' => $l->t('Booking page, booking API and Calendar link redirect load'),
			'calendar_disabled' => $l->t('The Calendar app is not enabled'),
			'contract_holds' => $l->t('All classes, methods and fields found'),
			'one_time_usable' => $l->t('Can be created and edited'),
			'services_load' => $l->t('Booking service, schedule service and booking storage load'),
			'no_schedule' => $l->t('No appointment schedule exists yet'),
			'free_slots' => $l->t('Schedule "%1$s", free slots in the next 7 days: %2$s', [$args[0] ?? '', $args[1] ?? '']),
			'storage_works' => $l->t('Test booking stored and removed'),
			default => (string)($row['detail'] ?? ''),
		};
	}
}
