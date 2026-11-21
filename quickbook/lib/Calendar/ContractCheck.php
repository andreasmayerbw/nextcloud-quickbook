<?php

declare(strict_types=1);

namespace OCA\QuickBook\Calendar;

use OCP\AppFramework\Db\Entity;

/**
 * Describes every part of the Calendar app's internal code this app relies on,
 * and verifies through reflection that the installed Calendar version still provides it.
 *
 * This check is cheap (no database, no calendar access) so it runs synchronously
 * on the first request after the Calendar app version changes.
 */
class ContractCheck {
	public const BOOKING_SERVICE = 'OCA\Calendar\Service\Appointments\BookingService';
	public const CONFIG_SERVICE = 'OCA\Calendar\Service\Appointments\AppointmentConfigService';
	public const BOOKING_MAPPER = 'OCA\Calendar\Db\BookingMapper';
	public const BOOKING = 'OCA\Calendar\Db\Booking';
	public const CONFIG = 'OCA\Calendar\Db\AppointmentConfig';
	public const INTERVAL = 'OCA\Calendar\Service\Appointments\Interval';
	public const CLIENT_EXCEPTION = 'OCA\Calendar\Exception\ClientException';
	public const NO_SLOT_EXCEPTION = 'OCA\Calendar\Exception\NoSlotFoundException';
	public const BOOKED_EVENT = 'OCA\Calendar\Events\BeforeAppointmentBookedEvent';
	/** AppointmentConfig::VISIBILITY_*: listed on the organizer's profile page, or only reachable by link */
	public const VISIBILITY_PUBLIC = 'PUBLIC';
	public const VISIBILITY_PRIVATE = 'PRIVATE';

	/** Route of the Calendar's own booking page, used as fallback */
	public const FALLBACK_ROUTE = 'calendar.appointment.show';

	/** @var list<array{string, string, list<string>}> class, method, leading parameter types */
	private const METHODS = [
		[self::BOOKING_SERVICE, 'getAvailableSlots', [self::CONFIG, 'int', 'int']],
		[self::BOOKING_SERVICE, 'confirmBooking', [self::BOOKING, self::CONFIG]],
		[self::CONFIG_SERVICE, 'findByToken', ['string']],
		[self::BOOKING_MAPPER, 'insert', [Entity::class]],
		[self::BOOKING_MAPPER, 'delete', [Entity::class]],
		[self::CONFIG, 'getAvailabilityAsArray', []],
		[self::INTERVAL, 'getStart', []],
		[self::INTERVAL, 'getEnd', []],
	];

	/** Entity fields accessed through the magic getters and setters */
	private const FIELDS = [
		self::BOOKING => ['apptConfigId', 'createdAt', 'token', 'displayName', 'description', 'email', 'start', 'end', 'timezone', 'confirmed'],
		self::CONFIG => ['id', 'token', 'name', 'description', 'location', 'userId', 'targetCalendarUri', 'length', 'availability', 'start', 'end', 'futureLimit', 'timeBeforeNextSlot'],
	];

	/**
	 * Only needed to create and edit one-time schedules. When these change, that feature
	 * switches off on its own and bookings keep working.
	 *
	 * @var list<array{string, string, list<string>}>
	 */
	private const ONE_TIME_METHODS = [
		[self::CONFIG_SERVICE, 'create', ['string', 'string', 'string', 'string', 'string', 'string', 'array', 'int', 'int', 'int', 'int', 'int', 'int', 'array', 'int', 'int', 'int', 'bool']],
		[self::CONFIG_SERVICE, 'update', [self::CONFIG]],
		[self::CONFIG_SERVICE, 'findByIdAndUser', ['int', 'string']],
		[self::CONFIG_SERVICE, 'getAllAppointmentConfigurations', ['string']],
		[self::CONFIG_SERVICE, 'delete', ['int', 'string']],
		[self::CONFIG, 'setAvailabilityAsArray', ['array']],
		[self::CONFIG, 'getCalendarFreebusyUrisAsArray', []],
		[self::CONFIG, 'setCalendarFreeBusyUrisAsArray', ['array']],
	];

	private const ONE_TIME_FIELDS = [
		self::CONFIG => ['increment', 'createTalkRoom', 'visibility', 'calendarFreebusyUris', 'preparationDuration', 'followupDuration', 'dailyMax'],
	];

	private const CLASSES = [
		self::CLIENT_EXCEPTION,
		self::NO_SLOT_EXCEPTION,
	];

	/**
	 * @return list<string> problems found, empty when the contract holds
	 */
	public function run(): array {
		return $this->check(self::CLASSES, self::FIELDS, self::METHODS);
	}

	/**
	 * The extra parts used by one-time schedules (on top of {@see self::run()}).
	 *
	 * @return list<string> problems found, empty when the contract holds
	 */
	public function runOneTime(): array {
		return $this->check([], self::ONE_TIME_FIELDS, self::ONE_TIME_METHODS);
	}

	/**
	 * @param list<string> $classes
	 * @param array<string, list<string>> $fieldsByClass
	 * @param list<array{string, string, list<string>}> $methods
	 * @return list<string>
	 */
	private function check(array $classes, array $fieldsByClass, array $methods): array {
		$problems = [];

		foreach ($classes as $class) {
			if (!class_exists($class)) {
				$problems[] = "class $class is missing";
			}
		}

		foreach ($fieldsByClass as $class => $fields) {
			if (!class_exists($class)) {
				$problems[] = "class $class is missing";
				continue;
			}
			if (!is_subclass_of($class, Entity::class)) {
				$problems[] = "$class is no longer a database entity";
				continue;
			}
			foreach ($fields as $field) {
				if (!property_exists($class, $field)) {
					$problems[] = "$class has no field '$field'";
				}
			}
		}

		foreach ($methods as [$class, $method, $types]) {
			$problem = $this->checkMethod($class, $method, $types);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		if (in_array(self::NO_SLOT_EXCEPTION, $classes, true) && class_exists(self::NO_SLOT_EXCEPTION) && !is_subclass_of(self::NO_SLOT_EXCEPTION, \Throwable::class)) {
			$problems[] = self::NO_SLOT_EXCEPTION . ' is not an exception';
		}

		return array_values(array_unique($problems));
	}

	/**
	 * @param list<string> $types
	 */
	private function checkMethod(string $class, string $method, array $types): ?string {
		if (!class_exists($class)) {
			return "class $class is missing";
		}
		$classRef = new \ReflectionClass($class);
		if (!$classRef->hasMethod($method)) {
			return "$class::$method() is missing";
		}
		$ref = $classRef->getMethod($method);
		if (!$ref->isPublic() || $ref->isStatic()) {
			return "$class::$method() is no longer a public instance method";
		}
		if ($ref->getNumberOfRequiredParameters() > count($types)) {
			return "$class::$method() now requires more parameters";
		}
		$params = $ref->getParameters();
		foreach ($types as $i => $expected) {
			if (!isset($params[$i])) {
				return "$class::$method() no longer accepts parameter #" . ($i + 1);
			}
			if (!$this->acceptsType($params[$i]->getType(), $expected)) {
				return "$class::$method() parameter #" . ($i + 1) . " no longer accepts $expected";
			}
		}
		return null;
	}

	private function acceptsType(?\ReflectionType $type, string $expected): bool {
		if ($type === null) {
			return true;
		}
		$named = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
		foreach ($named as $candidate) {
			if (!$candidate instanceof \ReflectionNamedType) {
				continue;
			}
			$name = $candidate->getName();
			if ($name === 'mixed' || strcasecmp($name, $expected) === 0) {
				return true;
			}
			// A parameter typed with a parent class or interface still accepts our value
			if (!$candidate->isBuiltin() && (is_a($expected, $name, true))) {
				return true;
			}
		}
		return false;
	}
}
