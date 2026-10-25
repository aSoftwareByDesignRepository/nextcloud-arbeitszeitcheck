<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Safety net when migrations were marked complete without creating every table
 * (partial install, restored backup, manual DB edits, or incomplete deploy).
 *
 * Runs on fresh install and on every upgrade (post-migration) before data repair steps.
 */
final class EnsureArbeitszeitCheckSchema implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure ArbeitszeitCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore === []) {
			$output->info('ArbeitszeitCheck: all ' . count(SchemaTableCatalog::EXPECTED_TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'ArbeitszeitCheck: %d table(s) missing (%s); running pending migrations.',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			ArbeitszeitCheckTableCatalog::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter === []) {
			$output->info('ArbeitszeitCheck: schema repair completed; all tables are now present.');
			return;
		}

		throw new \RuntimeException(sprintf(
			'ArbeitszeitCheck schema is still incomplete after migrate("latest"). Missing: %s. '
			. 'Run `php occ upgrade` or re-enable the app and check nextcloud.log. '
			. 'Deploy the full app bundle — partial file copies break repair steps.',
			implode(', ', $missingAfter),
		));
	}

	/**
	 * @return list<string>
	 */
	private function missingTables(): array
	{
		$missing = [];
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
