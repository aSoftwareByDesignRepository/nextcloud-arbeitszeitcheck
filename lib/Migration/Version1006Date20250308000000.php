<?php

declare(strict_types=1);

/**
 * Add app-owned teams, team_members, and team_managers tables.
 * Enables teams/departments with parent-child hierarchy and multiple managers per team.
 * When enabled (use_app_teams config), TeamResolver uses these instead of Nextcloud groups.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1006Date20250308000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_teams')) {
			$table = $schema->createTable('at_teams');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('parent_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('sort_order', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['parent_id'], 'at_teams_parent_id');
		}

		if (!$schema->hasTable('at_team_members')) {
			$table = $schema->createTable('at_team_members');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('team_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['team_id', 'user_id'], 'at_team_members_team_user');
			$table->addIndex(['user_id'], 'at_team_members_user_id');
		}

		if (!$schema->hasTable('at_team_managers')) {
			$table = $schema->createTable('at_team_managers');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('team_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['team_id', 'user_id'], 'at_team_managers_team_user');
			$table->addIndex(['user_id'], 'at_team_managers_user_id');
		}

		return $schema;
	}
}
