<?php

declare(strict_types=1);

/**
 * Scoped widget tokens (suite widget spec D-03 / E.6).
 *
 * Per-device, revocable, narrowly-scoped tokens that let a home-screen widget
 * execute exactly one action (azc clock toggle) without opening the app.
 * The raw token is returned once at issue; only its SHA-256 hash is stored.
 *
 * Idempotent (hasTable guard), no app-class imports on the upgrade path.
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

class Version1060Date20261124090000 extends SimpleMigrationStep
{
	public const TABLE = 'azc_widget_tokens';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true, 'notnull' => true, 'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true, 'length' => 64,
		]);
		$table->addColumn('token_hash', Types::STRING, [
			'notnull' => true, 'length' => 64, // SHA-256 of the raw token — never the raw token
		]);
		$table->addColumn('scope', Types::STRING, [
			'notnull' => true, 'length' => 64, // 'azc-clock' only (E.6 deny-by-default)
		]);
		$table->addColumn('device_label', Types::STRING, [
			'notnull' => false, 'length' => 128,
		]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('last_used_at', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token_hash'], 'azc_wtok_hash');
		$table->addIndex(['user_id'], 'azc_wtok_user');
		return $schema;
	}
}
