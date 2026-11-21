<?php

declare(strict_types=1);

namespace OCA\QuickBook\Migration;

use Closure;
use OCA\QuickBook\Db\OneTimeScheduleMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One-time appointment schedules: the exact dates of a Calendar schedule.
 */
class Version000600Date20261121000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(OneTimeScheduleMapper::TABLE)) {
			return null;
		}

		$table = $schema->createTable(OneTimeScheduleMapper::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		// Same lengths as the Calendar's own columns
		$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('config_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('timezone', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('dates', Types::TEXT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'quickbook_onetime_token');
		$table->addIndex(['user_id'], 'quickbook_onetime_user');
		return $schema;
	}
}
