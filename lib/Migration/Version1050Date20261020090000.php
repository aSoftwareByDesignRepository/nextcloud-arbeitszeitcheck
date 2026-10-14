<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Payload fingerprints for offline stamp idempotency.
 *
 * Without a stored hash, a clientRequestId reused with a different payload
 * would replay the stale cached response and the client would drop the op as
 * "applied" — silent data loss. Nullable keeps rows created by 1045 valid;
 * a missing hash is treated as "cannot verify" and accepts the replay.
 */
class Version1050Date20261020090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach (['at_mob_stamp_idem', 'at_kiosk_stamp_idem'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('payload_hash')) {
				$table->addColumn('payload_hash', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
