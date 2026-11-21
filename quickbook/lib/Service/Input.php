<?php

declare(strict_types=1);

namespace OCA\QuickBook\Service;

use DateTimeZone;
use OCA\QuickBook\Exception\ValidationException;

/**
 * Cleanup rules for text and timezones sent by browsers.
 */
final class Input {
	/** Control and invisible formatting characters (incl. bidi overrides, zero-width) */
	private const INVISIBLE = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';
	private const INVISIBLE_KEEP_LINES = '/(?![\n\t])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

	/** @var array<string, true>|null */
	private static ?array $timezones = null;

	/**
	 * One line of text: no invisible characters, whitespace collapsed.
	 * Invisible characters could make text look different in emails and events than what was typed.
	 */
	public static function singleLine(string $text): string {
		return trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace(self::INVISIBLE, '', $text)));
	}

	/**
	 * Free text that keeps its line breaks and tabs.
	 */
	public static function multiLine(string $text): string {
		$text = trim(str_replace("\r\n", "\n", $text));
		return trim((string)preg_replace(self::INVISIBLE_KEEP_LINES, '', $text));
	}

	/**
	 * Only named (IANA) timezones, as sent by browsers. Offsets like "+05:00" are refused.
	 *
	 * @throws ValidationException
	 */
	public static function timezone(string $timeZone, string $field = 'timeZone'): DateTimeZone {
		self::$timezones ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
		if (!isset(self::$timezones[$timeZone])) {
			throw new ValidationException($field, 'Invalid timezone');
		}
		return new DateTimeZone($timeZone);
	}
}
