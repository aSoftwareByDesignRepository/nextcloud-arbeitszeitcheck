<?php

declare(strict_types=1);

/**
 * Drop at_workforce_delivery — the durable notification-outbox table created
 * by Version1065 was never wired into any writer or dispatcher. The shipped
 * design uses PostCommitEffectQueue for post-commit side effects and the
 * persisted pending-approval/correction queue as the discoverable truth, so
 * an additional outbox is dead schema (audit cleanup).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1066Date20261126150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('at_workforce_delivery')) {
			$schema->dropTable('at_workforce_delivery');
		}

		return $schema;
	}
}
