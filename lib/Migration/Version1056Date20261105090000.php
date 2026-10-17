<?php

declare(strict_types=1);

/**
 * Rule-set provenance (Workforce Policy Suite, Phase 6 — spec §10.5).
 *
 * Every jurisdiction profile version carries its legal source and the
 * documented legal review that gates activation: `source_title`,
 * `source_reference`, `classifier_schema_version`, `reviewed_at`,
 * `reviewed_by`. Existing rows keep null review fields — activation is
 * refused until `markReviewed` records the legal sign-off, so upgrading
 * installations cannot silently activate unreviewed profiles.
 *
 * Expand-only and idempotent.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1056Date20261105090000 extends SimpleMigrationStep
{
	public const TABLE = 'at_btrip_ruleset';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::TABLE);
		$changed = false;
		if (!$table->hasColumn('source_title')) {
			$table->addColumn('source_title', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}
		if (!$table->hasColumn('source_reference')) {
			$table->addColumn('source_reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}
		if (!$table->hasColumn('classifier_schema_version')) {
			$table->addColumn('classifier_schema_version', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$changed = true;
		}
		if (!$table->hasColumn('reviewed_at')) {
			$table->addColumn('reviewed_at', Types::DATETIME, ['notnull' => false]);
			$changed = true;
		}
		if (!$table->hasColumn('reviewed_by')) {
			$table->addColumn('reviewed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
