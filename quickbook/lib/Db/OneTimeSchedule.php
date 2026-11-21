<?php

declare(strict_types=1);

namespace OCA\QuickBook\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Marks a Calendar appointment schedule as one-time and stores its exact dates.
 *
 * Linked by the schedule's token (random, never reused) rather than its id alone,
 * so a reused database id can never turn another schedule into a one-time one.
 *
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int getConfigId()
 * @method void setConfigId(int $configId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTimezone()
 * @method void setTimezone(string $timezone)
 * @method string getDates()
 * @method void setDates(string $dates)
 */
class OneTimeSchedule extends Entity {
	protected $token;
	protected $configId;
	protected $userId;
	protected $timezone;
	protected $dates;

	public function __construct() {
		$this->addType('token', 'string');
		$this->addType('configId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('timezone', 'string');
		$this->addType('dates', 'string');
	}
}
