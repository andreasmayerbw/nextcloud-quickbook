<?php

declare(strict_types=1);

namespace OCA\QuickBook\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<OneTimeSchedule>
 */
class OneTimeScheduleMapper extends QBMapper {
	public const TABLE = 'quickbook_onetime';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, OneTimeSchedule::class);
	}

	public function findByToken(string $token): ?OneTimeSchedule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByConfigId(int $configId, string $userId): ?OneTimeSchedule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('config_id', $qb->createNamedParameter($configId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<OneTimeSchedule>
	 */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	public function deleteForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}
}
