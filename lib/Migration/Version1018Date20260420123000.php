<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1018Date20260420123000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		try {
			$countQb = $this->db->getQueryBuilder();
			$countQb->select($countQb->createFunction('COUNT(*)'))
				->from('at_user_vacation_policies');
			$existingCount = (int)$countQb->executeQuery()->fetchOne();
			if ($existingCount > 0) {
				return;
			}

			$selectQb = $this->db->getQueryBuilder();
			$selectQb->select('user_id', 'vacation_days_per_year', 'start_date', 'end_date')
				->from('at_user_models');
			// Use the public IResult API (associative is the default fetch mode).
			$rows = $selectQb->executeQuery()->fetchAll();

			foreach ($rows as $row) {
				$insertQb = $this->db->getQueryBuilder();
				$insertQb->insert('at_user_vacation_policies')
					->values([
						'user_id' => $insertQb->createNamedParameter((string)$row['user_id']),
						'vacation_mode' => $insertQb->createNamedParameter('manual_fixed'),
						'manual_days' => $insertQb->createNamedParameter((float)$row['vacation_days_per_year']),
						'tariff_rule_set_id' => $insertQb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
						'override_reason' => $insertQb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
						'effective_from' => $insertQb->createNamedParameter((string)$row['start_date']),
						'effective_to' => $row['end_date'] !== null
							? $insertQb->createNamedParameter((string)$row['end_date'])
							: $insertQb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
						'created_by' => $insertQb->createNamedParameter('migration'),
						'created_at' => $insertQb->createNamedParameter((new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
						'updated_at' => $insertQb->createNamedParameter((new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
					]);
				$insertQb->executeStatement();
			}
		} catch (\Throwable $e) {
			// no-op: migration is best-effort and idempotent
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}
}

