<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Offline stamp idempotency for mobile companion + kiosk replay.
 */
class Version1045Date20260916100000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('at_mob_stamp_idem')) {
			$table = $schema->createTable('at_mob_stamp_idem');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('client_request_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('response_json', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_mob_si_pk');
			$table->addUniqueIndex(['user_id', 'client_request_id'], 'at_mob_si_uq');
			$table->addIndex(['user_id'], 'at_mob_si_user_idx');
			$changed = true;
		}

		if (!$schema->hasTable('at_kiosk_stamp_idem')) {
			$table = $schema->createTable('at_kiosk_stamp_idem');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('terminal_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('client_request_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('response_json', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_kiosk_si_pk');
			$table->addUniqueIndex(['terminal_id', 'client_request_id'], 'at_kiosk_si_uq');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
